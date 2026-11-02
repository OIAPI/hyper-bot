<?php
namespace Protocol\OneBot;

use Swoole\Coroutine\Channel;
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Utils\Logger;
use Utils\Cache;
use Protocol\Core\EventDispatcher;

/**
 * OneBot11 WebSocket 连接中枢
 *
 * 同时支持两种形态：
 *  - 正向 WS（forward）：本框架作为客户端，主动连接 OneBot 实现端（go-cqhttp / Lagrange.OneBot 等）
 *  - 反向 WS（reverse）：本框架开启 WS 服务端，由 OneBot 实现端连入（/onebot/v11/ws）
 *
 * 事件：实现端 → 框架（post_type 结构）
 * 调用：框架 → 实现端（action + params + echo，按 echo 关联响应）
 */
class WsHub {
	/** @var array<int, array> fd => 会话信息（反向 WS 连入的客户端） */
	private static array $sessions = [];
	/** @var array<string, Channel> echo => 等待响应的通道 */
	private static array $pending = [];
	/** @var \Swoole\WebSocket\Server|null WS 服务端引用 */
	private static $server = null;
	/** @var Client|null 正向 WS 客户端 */
	private static ?Client $forward = null;
	private static bool $forwardRunning = false;
	private static ?string $forwardUrl = null;
	private static string $forwardToken = '';
	private static int $echoSeq = 0;
	/** @var callable|null 收到未知帧时的兜底处理 */
	private static $frameHandler = null;
	/** @var Channel|null 正向 WS 出站队列（保证同一连接只在一个协程内读写） */
	private static ?Channel $outbound = null;

	// ---------------------- 反向 WS（服务端） ----------------------

	/** 绑定 Swoole 服务器的 WS 回调 */
	public static function bindServer(\Swoole\WebSocket\Server $server): void {
		self::$server = $server;
		$server->on('open', [self::class, 'onOpen']);
		$server->on('message', [self::class, 'onMessage']);
		$server->on('close', [self::class, 'onClose']);
	}

	public static function onOpen(\Swoole\WebSocket\Server $server, $request): void {
		$fd = $request->fd;
		$path = rtrim((string)($request->server['path_info'] ?? '/'), '/');
		$milkyPath = rtrim(\Protocol\Core\Config::string('milky.ws.server_path', '/milky/event'), '/');
		$onebotPath = rtrim(\Protocol\Core\Config::string('onebot.reverse.path', '/onebot/v11/ws'), '/');

		// 路径校验：不在预期端点上的连接直接拒绝，避免任意路径都能挂成反向会话
		$isMilky  = $path === $milkyPath;
		$isOneBot = $path === $onebotPath;
		if (!$isMilky && !$isOneBot) {
			Logger::warning("[WS] 未知端点，拒绝连接 | fd={$fd} | path={$path}");
			$server->disconnect($fd, 4004, 'unknown endpoint');
			return;
		}
		if ($isOneBot && !\Protocol\Core\Config::bool('onebot.reverse.enable', false)) {
			Logger::warning("[OneBot-WS] 反向 WS 未启用，拒绝连接 | fd={$fd}");
			$server->disconnect($fd, 4003, 'reverse ws disabled');
			return;
		}

		/*
		 * 鉴权说明：Swoole 的 WebSocket\Server + open_websocket_protocol 会在完成握手后
		 * 直接派发 open 事件（onRequest 不再被调用），因此 token 校验必须放在这里。
		 * 不通过时下发一条错误帧并断开，避免实现端无限重连却不报错。
		 */
		$expect = $isMilky
			? \Protocol\Core\Config::string('milky.token', '')
			: \Protocol\Core\Config::string('onebot.reverse.token', '');

		if ($expect !== '' && !self::authOk($request, $expect)) {
			Logger::warning("[WS] 鉴权失败，拒绝连接 | fd={$fd} | path={$path}");
			$server->push($fd, json_encode([
				'status'  => 'failed',
				'retcode' => 401,
				'message' => 'unauthorized: access_token 不匹配',
			], JSON_UNESCAPED_UNICODE));
			$server->disconnect($fd, 4001, 'unauthorized');
			return;
		}

		// Milky WS 端点：只用于本框架向外部推送事件，不按 OneBot 会话处理
		if ($isMilky) {
			self::$sessions[$fd] = [
				'fd'        => $fd,
				'connected' => time(),
				'last'      => time(),
				'self_id'   => 0,
				'remote'    => $request->server['remote_addr'] ?? '',
				'path'      => $path,
				'role'      => 'milky',
			];
			Logger::success("[Milky-WS] 客户端已连接 | fd={$fd}");
			return;
		}

		self::$sessions[$fd] = [
			'fd'         => $fd,
			'connected'  => time(),
			'last'       => time(),
			'self_id'    => (int)\Protocol\Core\Config::string('onebot.self_id', '0'),
			'remote'     => $request->server['remote_addr'] ?? '',
			'path'       => $path,
			'role'       => 'reverse',
		];
		Logger::success("[OneBot-WS] 反向 WS 客户端已连接 | fd={$fd} | IP=" . ($request->server['remote_addr'] ?? ''));
	}

	/**
	 * 校验 WS 握手请求的 access_token
	 * 支持 Authorization: Bearer xxx、?access_token=xxx、X-Access-Token 三种写法。
	 */
	private static function authOk($request, string $expect): bool {
		$header = (array)($request->header ?? []);
		$bearer = (string)($header['authorization'] ?? ($header['Authorization'] ?? ''));
		if (str_starts_with(strtolower($bearer), 'bearer ')) {
			$bearer = trim(substr($bearer, 7));
		}
		if ($bearer === '') {
			$bearer = (string)($header['x-access-token'] ?? ($header['X-Access-Token'] ?? ''));
		}
		if ($bearer === '') {
			parse_str((string)($request->server['query_string'] ?? ''), $query);
			$bearer = (string)($query['access_token'] ?? '');
		}
		return $bearer !== '' && hash_equals($expect, $bearer);
	}

	public static function onMessage(\Swoole\WebSocket\Server $server, $frame): void {
		$fd = $frame->fd;
		// Milky WS 端点只做事件推送，客户端上行帧一律忽略（Milky 没有 WS action 协议）
		if ((self::$sessions[$fd]['role'] ?? '') === 'milky') {
			Logger::debug("[Milky-WS] 忽略客户端上行帧 | fd={$fd}");
			return;
		}
		$data = json_decode((string)$frame->data, true);
		if (!is_array($data)) {
			Logger::warning("[OneBot-WS] 收到非 JSON 帧，已忽略 | fd={$fd}");
			return;
		}
		if (isset(self::$sessions[$fd])) {
			self::$sessions[$fd]['last'] = time();
		}
		self::handleFrame($data, $fd);
	}

	public static function onClose(\Swoole\WebSocket\Server $server, int $fd): void {
		$role = self::$sessions[$fd]['role'] ?? 'reverse';
		unset(self::$sessions[$fd]);
		Logger::warning(($role === 'milky' ? '[Milky-WS] 客户端断开' : '[OneBot-WS] 反向 WS 客户端断开') . " | fd={$fd}");
	}

	/** 已连接的反向 WS 会话数（不包含 Milky 等仅推送端点） */
	public static function reverseCount(): int {
		return count(self::reverseSessions());
	}

	/** 过滤出 OneBot 反向 WS 会话 */
	private static function reverseSessions(): array {
		return array_filter(self::$sessions, fn($info) => ($info['role'] ?? '') === 'reverse');
	}

	/** 取一个可用于下发 API 调用的反向会话 fd */
	public static function pickReverseFd(): ?int {
		$sessions = self::reverseSessions();
		if ($sessions === []) return null;
		$best = null;
		foreach ($sessions as $fd => $info) {
			if ($best === null || $info['last'] > $sessions[$best]['last']) {
				$best = $fd;
			}
		}
		return $best;
	}

	/** 向反向会话推送事件 */
	public static function pushEvent(array $event, ?int $fd = null): bool {
		$fd ??= self::pickReverseFd();
		if ($fd === null) return false;
		return self::push($fd, $event);
	}

	/** 已连接的 Milky WS 会话数（本框架作为 Milky 协议端时的对外订阅连接） */
	public static function milkyCount(): int {
		return count(array_filter(self::$sessions, fn($info) => ($info['role'] ?? '') === 'milky'));
	}

	public static function push(int $fd, array $payload): bool {
		$server = self::$server;
		if (!$server instanceof \Swoole\WebSocket\Server) {
			$server = Cache::$http;
			self::$server = $server instanceof \Swoole\WebSocket\Server ? $server : null;
		}
		if (!$server instanceof \Swoole\WebSocket\Server) {
			Logger::warning('[OneBot-WS] WS 服务端未就绪，无法向 fd=' . $fd . ' 推送');
			return false;
		}
		if (!$server->exist($fd)) {
			Logger::warning('[OneBot-WS] fd=' . $fd . ' 不存在，推送失败');
			return false;
		}
		return (bool)$server->push($fd, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	// ---------------------- 正向 WS（客户端） ----------------------

	/**
	 * 启动正向 WS 连接（常驻协程内运行，断线自动重连）
	 * @param string $url 例如 ws://127.0.0.1:3001
	 * @param string $token access_token（可为空）
	 */
	public static function startForward(string $url, string $token = ''): void {
		if (self::$forwardRunning) {
			Logger::warning('[OneBot-WS] 正向 WS 已在运行，忽略重复启动');
			return;
		}
		self::$forwardUrl    = $url;
		self::$forwardToken  = $token;
		self::$forwardRunning = true;
		self::$outbound ??= new Channel(128);

		Coroutine::create(function () {
			$retry = 1;
			while (self::$forwardRunning) {
				try {
					$connected = self::connectForward();
					if ($connected) {
						$retry = 1;
						Logger::success('[OneBot-WS] 正向 WS 已连接：' . self::$forwardUrl);
						self::forwardLoop();
					}
				} catch (\Throwable $e) {
					Logger::error('[OneBot-WS] 正向 WS 异常：' . $e->getMessage());
				} finally {
					if (self::$forward !== null) {
						try { self::$forward->close(); } catch (\Throwable $e) {}
						self::$forward = null;
					}
				}
				// 指数退避重连（1~30 秒）
				$delay = min(30, $retry * 2);
				$retry++;
				Logger::warning("[OneBot-WS] 正向 WS 断开，{$delay} 秒后重连");
				Coroutine::sleep($delay);
			}
		});
	}

	public static function stopForward(): void {
		self::$forwardRunning = false;
		if (self::$forward !== null) {
			try { self::$forward->close(); } catch (\Throwable $e) {}
			self::$forward = null;
		}
	}

	private static function connectForward(): bool {
		$url = parse_url(self::$forwardUrl ?? '');
		if (!$url || empty($url['host'])) {
			Logger::error('[OneBot-WS] 非法的正向 WS 地址：' . (self::$forwardUrl ?? ''));
			self::$forwardRunning = false;
			return false;
		}
		$scheme = $url['scheme'] ?? 'ws';
		$ssl    = in_array($scheme, ['wss', 'https'], true);
		$host   = $url['host'];
		$port   = $url['port'] ?? ($ssl ? 443 : 80);
		$path   = ($url['path'] ?? '/');
		$query  = isset($url['query']) ? ('?' . $url['query']) : '';
		$path  .= $query;

		$client = new Client($host, $port, $ssl);
		$client->set(['timeout' => -1, 'websocket_mask' => true, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
		$headers = ['User-Agent' => 'oiapi-bot/1.0'];
		if (self::$forwardToken !== '') {
			$headers['Authorization'] = 'Bearer ' . self::$forwardToken;
		}
		$client->setHeaders($headers);
		$ok = $client->upgrade($path);
		if (!$ok) {
			Logger::warning('[OneBot-WS] 正向 WS 握手失败 | ' . self::$forwardUrl . ' | errCode=' . $client->errCode);
			return false;
		}
		self::$forward = $client;
		return true;
	}

	/**
	 * 正向 WS 收发循环
	 *
	 * Swoole 协程客户端要求「同一连接只在一个协程内读写」，因此这里由单一协程
	 * 同时负责 recv 与出站队列出队，其它协程只往队列里塞数据。
	 */
	private static function forwardLoop(): void {
		$client = self::$forward;
		$queue  = self::$outbound;
		while (self::$forwardRunning && $client !== null) {
			// 1) 优先处理出站请求（最多等待 50ms，避免长时间空转）
			if ($queue !== null) {
				$payload = $queue->pop(0.05);
				if (is_array($payload)) {
					$client->push(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
				}
			}

			// 2) 接收服务端帧（最多阻塞 50ms）
			$frame = $client->recv(0.05);
			if ($frame === false || $frame === null) {
				if ($client->errCode) {
					Logger::warning('[OneBot-WS] 正向 WS 连接回收 | errCode=' . $client->errCode);
					break;
				}
				continue; // 空闲超时，继续等待
			}
			if (!$frame instanceof \Swoole\WebSocket\Frame) continue;
			if ((int)$frame->opcode === \WEBSOCKET_OPCODE_PING) continue;
			$data = json_decode((string)$frame->data, true);
			if (!is_array($data)) continue;
			self::handleFrame($data, null);
		}
	}

	// ---------------------- 帧处理 ----------------------

	/**
	 * 统一帧处理：区分「事件 / API 响应 / API 请求」
	 * @param array $data 帧数据
	 * @param int|null $fd 来源反向会话（正向连接时为 null）
	 */
	private static function handleFrame(array $data, ?int $fd): void {
		// 1) API 响应：带 echo 且带 status/retcode
		if (isset($data['echo']) && (isset($data['status']) || isset($data['retcode']))) {
			$echo = (string)$data['echo'];
			if (isset(self::$pending[$echo])) {
				self::$pending[$echo]->push($data);
			}
			return;
		}

		// 2) 事件：带 post_type
		if (isset($data['post_type'])) {
			self::handleIncomingEvent($data, $fd);
			return;
		}

		// 3) API 请求：带 action（OneBot 实现端反调框架，较少见）
		if (isset($data['action'])) {
			self::handleApiRequest($data, $fd);
			return;
		}

		if (self::$frameHandler) {
			call_user_func(self::$frameHandler, $data, $fd);
		} else {
			Logger::debug('[OneBot-WS] 未识别的帧：' . substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 200));
		}
	}

	/** 处理入站事件（实现端 → 框架） */
	private static function handleIncomingEvent(array $event, ?int $fd): void {
		// 记录会话对应的机器人账号：实现端会在 lifecycle/heartbeat 里带 self_id
		if ($fd !== null && isset(self::$sessions[$fd]) && !empty($event['self_id'])) {
			self::$sessions[$fd]['self_id'] = (int)$event['self_id'];
		}
		$adapter = Adapter::currentAdapter($fd);
		EventDispatcher::dispatch($event, $adapter);
	}

	/** 处理入站 API 请求（实现端 → 框架，走本地触发逻辑或转发） */
	private static function handleApiRequest(array $data, ?int $fd): void {
		$echo = $data['echo'] ?? ($data['request_id'] ?? null);
		try {
			// 实现端反向调用框架的 API：本地不承载此类语义，转发给默认 HTTP 协议端
			$result = (array) (new \Protocol\Core\HttpAdapter())->call((string)$data['action'], (array)($data['params'] ?? []));
		} catch (\Throwable $e) {
			$result = ['status' => 'failed', 'retcode' => -1, 'data' => null, 'message' => $e->getMessage()];
		}
		if ($echo !== null) {
			if ($fd !== null) {
				self::push($fd, array_merge($result, ['echo' => $echo]));
			} elseif (self::$forward !== null) {
				self::sendRaw(array_merge($result, ['echo' => $echo]));
			}
		}
	}

	/**
	 * 通过 WS 调用 OneBot API（优先正向连接，其次反向会话）
	 *
	 * 返回值三态，调用方必须区分：
	 *  - array：收到协议端响应
	 *  - null ：**没有可用连接**（可以安全回落 HTTP）
	 *  - false：**已发出但等不到响应（超时）**，此时绝不能回落 HTTP 重发，
	 *            send_* 这类非幂等操作会变成「发两条」。
	 *
	 * @return array|false|null
	 */
	public static function callApi(string $action, array $params = [], float $timeout = 30.0, ?int $fd = null): array|false|null {
		$payload = [
			'action' => $action,
			'params' => (object)$params,
			'echo'   => self::nextEcho(),
		];
		$echo = $payload['echo'];
		$channel = new Channel(1);
		self::$pending[$echo] = $channel;

		$sent = false;

		/*
		 * 会话优先：事件来自哪个反向连接，API 就回哪个连接。
		 * 反向 WS 的实现端可能只在自己那条连接上维护会话状态，
		 * 把回复推到另一条连接会导致「消息发到了别人的会话里」。
		 */
		if ($fd !== null && isset(self::$sessions[$fd]) && (self::$sessions[$fd]['role'] ?? '') === 'reverse') {
			$sent = self::push($fd, $payload);
		}

		if (!$sent && self::$forward !== null && self::$outbound !== null) {
			// 入队，由 forwardLoop 所属协程真正写出
			$sent = (bool)self::$outbound->push($payload, 1.0);
			if (!$sent) {
				$sent = self::sendRaw($payload);
			}
		}
		if (!$sent) {
			$pickFd = self::pickReverseFd();
			if ($pickFd !== null) {
				$sent = self::push($pickFd, $payload);
			}
		}

		if (!$sent) {
			unset(self::$pending[$echo]);
			return null;
		}

		$response = $channel->pop($timeout);
		unset(self::$pending[$echo]);
		if ($response === false) {
			Logger::warning("[OneBot-WS] API 调用超时：{$action}（{$timeout}s），不回落 HTTP 以免重复发送");
			return false;
		}
		return is_array($response) ? $response : false;
	}

	private static function sendRaw(array $payload): bool {
		if (self::$forward === null) return false;
		return (bool)self::$forward->push(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	private static function nextEcho(): string {
		self::$echoSeq++;
		return 'echo_' . substr(md5((string)microtime(true) . self::$echoSeq . mt_rand()), 0, 12) . '_' . self::$echoSeq;
	}

	public static function hasForward(): bool {
		return self::$forward !== null;
	}

	/** 当前是否存在反向 WS 会话 */
	public static function hasReverse(): bool {
		return self::reverseCount() > 0;
	}

	public static function forwardUrl(): ?string {
		return self::$forwardUrl;
	}

	public static function setFrameHandler(callable $handler): void {
		self::$frameHandler = $handler;
	}

	/** 会话信息（调试/管理面板用） */
	public static function sessions(): array {
		return self::$sessions;
	}
}