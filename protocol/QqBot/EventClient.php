<?php
namespace Protocol\QqBot;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Timer;
use Utils\Logger;
use Utils\CoroutineCurl;
use Protocol\Core\Config;
use Protocol\Core\EventDispatcher;

/**
 * QQ 官方机器人事件接入
 *
 * 支持两种方式：
 *  - webhook：平台 POST 回调（鉴权在 Webhook 类中完成），本类负责解析与分发
 *  - ws     ：Gateway 长连接（心跳 / Identify / Resume / 重连）
 *
 * 同时维护「被动回复上下文」：官方要求回复消息时携带 msg_id + msg_seq，
 * 因此每条收到的消息都会登记 (msg_id, event_id, 过期时间)，供 Adapter 取用。
 */
class EventClient {
	private static bool $running = false;
	private static ?Client $ws = null;
	private static string $sessionId = '';
	private static int $lastSeq = 0;
	private static int $heartbeatTimer = -1;
	private static bool $identified = false;
	private static ?int $selfId = null;

	/** @var array 被动回复上下文：会话键 => ['msg_id','event_id','expires','seq'] */
	private static array $passive = [];

	// ---------------- 启动 ----------------

	public static function start(): void {
		if (!Config::bool('qqbot.enable', false)) {
			Logger::info('[QqBot] 未启用（protocol.qqbot.enable = false）');
			return;
		}
		$mode = strtolower(Config::string('qqbot.event.mode', 'ws'));
		if ($mode === 'webhook') {
			Logger::success('[QqBot] 事件接入方式：WebHook（POST ' . Config::string('qqbot.webhook.path', '/qqbot/webhook') . '）');
			return;
		}
		self::startGateway();
	}

	public static function stop(): void {
		self::$running = false;
		if (self::$heartbeatTimer > 0) {
			Timer::clear(self::$heartbeatTimer);
			self::$heartbeatTimer = -1;
		}
		if (self::$ws !== null) {
			try { self::$ws->close(); } catch (\Throwable $e) {}
			self::$ws = null;
		}
	}

	// ---------------- Gateway ----------------

	private static function startGateway(): void {
		if (self::$running) {
			Logger::warning('[QqBot] Gateway 已在运行');
			return;
		}
		self::$running = true;

		Coroutine::create(function () {
			$retry = 1;
			while (self::$running) {
				$connected = false;
				try {
					$connected = self::connectGateway();
					if ($connected) {
						$retry = 1;
						self::gatewayLoop();
					}
				} catch (\Throwable $e) {
					Logger::error('[QqBot] Gateway 异常：' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
				} finally {
					if (self::$heartbeatTimer > 0) {
						Timer::clear(self::$heartbeatTimer);
						self::$heartbeatTimer = -1;
					}
					if (self::$ws !== null) {
						try { self::$ws->close(); } catch (\Throwable $e) {}
						self::$ws = null;
					}
				}
				if (!self::$running) break;
				$delay = min(30, $retry * 2);
				$retry++;
				Logger::warning("[QqBot] Gateway 断开，{$delay} 秒后重连");
				Coroutine::sleep($delay);
			}
		});
	}

	/** 获取网关地址（GET /gateway 或 /gateway/bot） */
	private static function gatewayUrl(): ?string {
		$shard = Config::bool('qqbot.gateway.shard', false);
		$path  = $shard ? '/gateway/bot' : '/gateway';
		$token = Token::get();
		if ($token === null) {
			Logger::error('[QqBot] 无法获取 AccessToken，Gateway 连接中止');
			return null;
		}

		$curl = new CoroutineCurl();
		$curl->timeout(15);
		$curl->addHeaders('Authorization', 'QQBot ' . $token);
		$curl->addHeaders('Content-Type', 'application/json');
		try {
			$curl->get(Token::apiBase() . $path);
		} catch (\Throwable $e) {
			Logger::error('[QqBot] 获取网关地址异常：' . $e->getMessage());
			return null;
		}
		$body = json_decode((string)$curl->result, true);
		$url  = is_array($body) ? (string)($body['url'] ?? '') : '';
		if ($url === '') {
			Logger::error('[QqBot] 获取网关地址失败：' . substr((string)$curl->result, 0, 200));
			return null;
		}
		return $url;
	}

	private static function connectGateway(): bool {
		$url = self::gatewayUrl();
		if ($url === null) return false;

		$parts = parse_url($url);
		if (!$parts || empty($parts['host'])) {
			Logger::error('[QqBot] 非法的网关地址：' . $url);
			return false;
		}
		$ssl  = in_array(($parts['scheme'] ?? 'wss'), ['wss', 'https'], true);
		$host = $parts['host'];
		$port = $parts['port'] ?? ($ssl ? 443 : 80);
		$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');

		$client = new Client($host, $port, $ssl);
		$client->set(['timeout' => -1, 'websocket_mask' => true, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
		$client->setHeaders(['User-Agent' => 'oiapi-bot/1.0']);
		if (!$client->upgrade($path)) {
			Logger::warning('[QqBot] 网关握手失败 | ' . $url . ' | errCode=' . $client->errCode);
			return false;
		}
		self::$ws = $client;
		Logger::success('[QqBot] 网关已连接：' . $url);
		return true;
	}

	/** 读取第一个 Hello 包 */
	private static function waitHello(): int {
		$deadline = time() + 10;
		while (time() < $deadline && self::$ws !== null) {
			$frame = self::$ws->recv(1.0);
			if (!$frame instanceof \Swoole\WebSocket\Frame) continue;
			$payload = json_decode((string)$frame->data, true);
			if (!is_array($payload)) continue;
			if ((int)($payload['op'] ?? -1) === 10) {
				return (int)($payload['d']['heartbeat_interval'] ?? 45000);
			}
			// 其它包（如错误）也处理一下
			self::handlePayload($payload, 'gateway');
		}
		return 45000;
	}

	private static function gatewayLoop(): void {
		$interval = self::waitHello();

		// 鉴权（或恢复）
		self::identify();

		// 心跳
		self::$heartbeatTimer = Timer::tick(max(10, (int)($interval * 0.9)), function () {
			self::heartbeat();
		});

		while (self::$running && self::$ws !== null) {
			$frame = self::$ws->recv(1.0);
			if ($frame === false || $frame === null) {
				if (self::$ws->errCode) {
					Logger::warning('[QqBot] 网关断开 | errCode=' . self::$ws->errCode);
					break;
				}
				continue;
			}
			if (!$frame instanceof \Swoole\WebSocket\Frame) continue;
			$payload = json_decode((string)$frame->data, true);
			if (!is_array($payload)) continue;
			self::handlePayload($payload, 'gateway');
		}
	}

	private static function identify(): void {
		$token = Token::identifyToken();
		if ($token === null) {
			Logger::error('[QqBot] Identify 缺少 token');
			return;
		}

		// 断线重连优先 Resume（4006/4007/op=9 时改回 Identify）
		$useResume = Config::bool('qqbot.gateway.resume', true)
			&& self::$sessionId !== ''
			&& self::$lastSeq > 0;

		$payload = $useResume
			? [
				'op' => 6,
				'd'  => ['token' => $token, 'session_id' => self::$sessionId, 'seq' => self::$lastSeq],
			]
			: [
				'op' => 2,
				'd'  => [
					'token'      => $token,
					'intents'    => self::intents(),
					'shard'      => [(int)Config::int('qqbot.gateway.shard_id', 0), max(1, Config::int('qqbot.gateway.shard_count', 1))],
					'properties' => [
						'$os'      => PHP_OS,
						'$browser' => 'oiapi-bot',
						'$device'  => 'oiapi-bot',
					],
				],
			];

		self::sendPayload($payload);
		Logger::success('[QqBot] 已发送 ' . ($useResume ? 'Resume' : 'Identify'));
	}

	private static function heartbeat(): void {
		self::sendPayload(['op' => 1, 'd' => self::$lastSeq > 0 ? self::$lastSeq : null]);
	}

	private static function sendPayload(array $payload): void {
		if (self::$ws === null) return;
		self::$ws->push(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/** intents 计算：支持整型或事件名数组 */
	public static function intents(): int {
		$conf = Config::get('qqbot.intents', null);
		if (is_numeric($conf)) {
			return (int)$conf;
		}
		if (is_string($conf) && trim($conf) !== '') {
			$parts = array_map('trim', explode(',', strtoupper($conf)));
			return self::intentsFromNames($parts);
		}
		if (is_array($conf) && $conf !== []) {
			return self::intentsFromNames(array_map('strtoupper', array_map('strval', array_values($conf))));
		}
		// 默认：群与单聊事件 + 群成员事件
		return (1 << 25) | (1 << 24);
	}

	private static function intentsFromNames(array $names): int {
		$map = [
			'GUILDS'                     => 1 << 0,
			'GUILD_MEMBERS'              => 1 << 1,
			'GUILD_MESSAGES'             => 1 << 9,
			'GUILD_MESSAGE_REACTIONS'    => 1 << 10,
			'DIRECT_MESSAGE'             => 1 << 12,
			'GROUP_MEMBER_EVENT'         => 1 << 24,
			'GROUP_AND_C2C_EVENT'        => 1 << 25,
			'INTERACTION'                => 1 << 26,
			'MESSAGE_AUDIT'              => 1 << 27,
			'FORUMS_EVENT'               => 1 << 28,
			'AUDIO_ACTION'               => 1 << 29,
			'PUBLIC_GUILD_MESSAGES'      => 1 << 30,
		];
		$intents = 0;
		foreach ($names as $name) {
			if (isset($map[$name])) {
				$intents |= $map[$name];
			} else {
				Logger::warning("[QqBot] 未知的 intents：{$name}");
			}
		}
		return $intents !== 0 ? $intents : ((1 << 25) | (1 << 24));
	}

	// ---------------- Payload 处理 ----------------

	/**
	 * 处理一条官方 payload（WebHook 与 Gateway 共用）
	 * @param array $payload {"id","op","d","s","t"}
	 */
	public static function handlePayload(array $payload, string $source): void {
		$op = (int)($payload['op'] ?? -1);
		$d  = (array)($payload['d'] ?? []);
		if (isset($payload['s']) && (int)$payload['s'] > self::$lastSeq) {
			self::$lastSeq = (int)$payload['s'];
		}

		switch ($op) {
			case 0: // Dispatch
				self::dispatch($payload, $d, $source);
				return;

			case 10: // Hello
				Logger::success('[QqBot] 收到 Hello，心跳间隔 ' . ($d['heartbeat_interval'] ?? 0) . 'ms');
				return;

			case 11: // Heartbeat ACK
				Logger::debug('[QqBot] 心跳 ACK');
				return;

			case 7: // Reconnect
				Logger::warning('[QqBot] 服务端要求重连（op=7）');
				self::reconnect();
				return;

			case 9: // Invalid Session
				Logger::warning('[QqBot] 会话失效（op=9），将重新 Identify');
				self::$sessionId = '';
				self::$lastSeq = 0;
				return;

			default:
				Logger::debug("[QqBot] 收到未处理的 op={$op}");
				return;
		}
	}

	private static function dispatch(array $payload, array $d, string $source): void {
		$t = (string)($payload['t'] ?? '');

		// 记录会话与登录信息
		if ($t === 'READY') {
			self::$sessionId = (string)($d['session_id'] ?? '');
			self::$lastSeq   = (int)($payload['s'] ?? 0);
			self::$selfId    = (int)($d['user']['id'] ?? 0);
			Logger::success('[QqBot] READY | session_id=' . substr(self::$sessionId, 0, 12) . '... | bot=' . (($d['user']['username'] ?? '')));
			// 让代码以 OneBot 元事件形式感知上线
			EventDispatcher::dispatch(Converter::toOneBot($payload, self::$selfId) ?? [], new Adapter());
			return;
		}

		if ($t === 'RESUMED') {
			Logger::success('[QqBot] RESUMED');
			return;
		}

		// 登记被动回复上下文（消息事件才有）
		self::registerPassive($t, $d);

		$converted = Converter::toOneBot($payload, self::$selfId);
		if ($converted === null) {
			Logger::debug("[QqBot] 未支持的事件已忽略：{$t}");
			return;
		}

		// 去掉空事件（例如生命周期尚未就绪）
		if ($converted === [] || !isset($converted['post_type'])) return;

		EventDispatcher::dispatch($converted, new Adapter());
	}

	private static function reconnect(): void {
		if (self::$ws !== null) {
			try { self::$ws->close(); } catch (\Throwable $e) {}
			self::$ws = null;
		}
	}

	// ---------------- 被动回复上下文 ----------------

	private static function registerPassive(string $t, array $d): void {
		$msgId = (string)($d['id'] ?? '');
		if ($msgId === '') return;

		$key = '';
		$ttl = 300; // 群聊 5 分钟
		if (in_array($t, ['GROUP_AT_MESSAGE_CREATE', 'GROUP_MESSAGE_CREATE'], true)) {
			$key = 'group:' . (string)($d['group_openid'] ?? '');
			$ttl = 300;
		} elseif ($t === 'C2C_MESSAGE_CREATE') {
			$key = 'user:' . (string)($d['author']['user_openid'] ?? ($d['author']['id'] ?? ''));
			$ttl = 3600; // 单聊 60 分钟
		} else {
			return;
		}
		// event_id 用于被动回复（官方文档：message_scene.ext 在部分场景下携带事件 ID）
		$eventId = '';
		$sceneExt = $d['message_scene']['ext'] ?? '';
		if (is_string($sceneExt) && $sceneExt !== '') {
			$eventId = $sceneExt;
		} elseif (is_array($sceneExt)) {
			$eventId = (string)($sceneExt['event_id'] ?? ($sceneExt['ext'] ?? ''));
		}
		self::$passive[$key] = [
			'msg_id'   => $msgId,
			'event_id' => $eventId,
			'expires'  => time() + $ttl,
			'seq'      => 1,
			'kind'     => $t,
			'raw'      => $d,
		];
	}

	/** 取被动回复上下文（并自动分配 msg_seq） */
	public static function passiveContext(string $key): ?array {
		$ctx = self::$passive[$key] ?? null;
		if ($ctx === null) return null;
		if (time() > $ctx['expires']) {
			unset(self::$passive[$key]);
			return null;
		}
		// 群聊最多 5 次、单聊最多 4 次
		$max = ($ctx['kind'] === 'C2C_MESSAGE_CREATE') ? 4 : 5;
		if ($ctx['seq'] > $max) {
			Logger::warning("[QqBot] 被动回复次数已用尽（{$key}），转主动消息");
			return null;
		}
		$seq = $ctx['seq'];
		self::$passive[$key]['seq'] = $seq + 1;
		return ['msg_id' => $ctx['msg_id'], 'msg_seq' => $seq, 'event_id' => $ctx['event_id']];
	}

	public static function status(): array {
		return [
			'running'    => self::$running,
			'connected'  => self::$ws !== null,
			'session'    => self::$sessionId,
			'last_seq'   => self::$lastSeq,
			'self_id'    => self::$selfId,
			'passive'    => array_map(fn($v) => ['msg_id' => $v['msg_id'], 'seq' => $v['seq'], 'ttl' => $v['expires'] - time()], self::$passive),
		];
	}
}
