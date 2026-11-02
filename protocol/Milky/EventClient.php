<?php
namespace Protocol\Milky;

use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Utils\Logger;
use Protocol\Core\Config;
use Protocol\Core\EventDispatcher;

/**
 * Milky 事件接入客户端
 *
 * Milky 的事件推送有三种形态（由协议端决定，本框架任选其一）：
 *  1. WebHook —— 协议端 POST 到本框架的 HTTP 路由（见 Router 注册，默认 /milky/webhook）
 *  2. WebSocket —— 本框架作为客户端连接 ws://{host}:{port}/event
 *  3. SSE —— 本框架作为客户端 GET /event（text/event-stream）
 *
 * 注意：Milky 的 WS 只能推送事件，不能调用 API（API 一律走 HTTP，见 Api 类）。
 */
class EventClient {
	private static bool $wsRunning = false;
	private static bool $sseRunning = false;
	private static ?Client $ws = null;
	/** 去重缓存（WebHook 多地址重复投递 / WS+WebHook 双开时的幂等保护） */
	private static array $seen = [];

	/** 依据配置启动（WebHook 模式无需启动任何客户端） */
	public static function start(): void {
		$mode = strtolower(Config::string('milky.event.mode', 'webhook'));
		$enabled = Config::bool('milky.enable', false);
		if (!$enabled) {
			Logger::info('[Milky] 未启用（protocol.milky.enable = false）');
			return;
		}
		switch ($mode) {
			case 'ws':
			case 'websocket':
				self::startWs();
				break;
			case 'sse':
				self::startSse();
				break;
			case 'webhook':
				Logger::success('[Milky] 事件接入方式：WebHook（POST ' . Config::string('milky.webhook.path', '/milky/webhook') . '）');
				break;
			default:
				Logger::warning("[Milky] 未知的事件接入方式：{$mode}（可选 webhook/ws/sse）");
		}
	}

	public static function stop(): void {
		self::$wsRunning = false;
		self::$sseRunning = false;
		if (self::$ws !== null) {
			try { self::$ws->close(); } catch (\Throwable $e) {}
			self::$ws = null;
		}
	}

	// ---------------- 事件入口 ----------------

	/**
	 * 处理一条 Milky 原始事件（WebHook / WS / SSE 共用）
	 * @return array 处理结果
	 */
	public static function handleEvent(array $event, string $source = 'webhook'): array {
		$eventType = (string)($event['event_type'] ?? '');
		if ($eventType === '') {
			Logger::warning('[Milky] 收到缺少 event_type 的事件，已忽略');
			return ['status' => 'ok', 'retcode' => 0, 'data' => new \stdClass()];
		}

		// 幂等去重：WebHook 可能向多个地址重复投递，WS+WebHook 双开时也会收到同一事件
		$key = self::dedupeKey($event);
		if ($key !== '') {
			if (isset(self::$seen[$key])) {
				Logger::debug("[Milky] 重复事件已跳过 | {$eventType} | {$key}");
				return ['status' => 'ok', 'retcode' => 0, 'data' => new \stdClass()];
			}
			self::$seen[$key] = time();
			if (count(self::$seen) > 500) {
				self::$seen = array_slice(self::$seen, -300, null, true);
			}
		}

		$converted = Converter::toOneBot($event);
		if ($converted === null) {
			// 官方兼容性要求：未知 event_type 直接忽略，不抛错
			Logger::debug("[Milky] 未支持的事件类型已忽略：{$eventType}");
			return ['status' => 'ok', 'retcode' => 0, 'data' => new \stdClass()];
		}

		Logger::debug("[Milky] 事件 {$eventType}({$source}) → OneBot {$converted['post_type']}/" . ($converted['notice_type'] ?? ($converted['message_type'] ?? ($converted['request_type'] ?? ''))));
		EventDispatcher::dispatch($converted, new Adapter());
		return ['status' => 'ok', 'retcode' => 0, 'data' => new \stdClass()];
	}

	private static function dedupeKey(array $event): string {
		$data = (array)($event['data'] ?? []);
		$parts = [
			(string)($event['self_id'] ?? ''),
			(string)($event['event_type'] ?? ''),
			(string)($data['peer_id'] ?? ($data['group_id'] ?? ($data['user_id'] ?? ''))),
			(string)($data['message_seq'] ?? ($data['notification_seq'] ?? ($data['seq'] ?? ''))),
		];
		$joined = implode('|', $parts);
		return $joined === '|||' ? '' : md5($joined);
	}

	// ---------------- WebSocket 客户端 ----------------

	private static function startWs(): void {
		if (self::$wsRunning) return;
		self::$wsRunning = true;
		$base = (string)(Config::string('milky.ws.url', '') ?: self::deriveWsUrl());
		$token = Config::string('milky.token', '');

		Coroutine::create(function () use ($base, $token) {
			$retry = 1;
			while (self::$wsRunning) {
				try {
					$client = self::connectWs($base, $token);
					if ($client !== null) {
						Logger::success('[Milky] WS 已连接：' . $base);
						$retry = 1;
						self::wsLoop($client);
					}
				} catch (\Throwable $e) {
					Logger::error('[Milky] WS 异常：' . $e->getMessage());
				}
				$delay = min(30, $retry * 2);
				$retry++;
				Logger::warning("[Milky] WS 断开，{$delay} 秒后重连");
				Coroutine::sleep($delay);
			}
		});
	}

	private static function deriveWsUrl(): string {
		$api = Config::string('milky.api', 'http://127.0.0.1:3010');
		$parts = parse_url($api);
		$scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
		$host = $parts['host'] ?? '127.0.0.1';
		$port = $parts['port'] ?? (($scheme === 'wss') ? 443 : 80);
		return "{$scheme}://{$host}:{$port}/event";
	}

	private static function connectWs(string $url, string $token): ?Client {
		$parts = parse_url($url);
		if (!$parts || empty($parts['host'])) {
			Logger::error('[Milky] 非法的 WS 地址：' . $url);
			self::$wsRunning = false;
			return null;
		}
		$ssl  = in_array(($parts['scheme'] ?? 'ws'), ['wss', 'https'], true);
		$host = $parts['host'];
		$port = $parts['port'] ?? ($ssl ? 443 : 80);
		$path = ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
		if ($token !== '' && !str_contains($path, 'access_token=')) {
			$path .= (str_contains($path, '?') ? '&' : '?') . 'access_token=' . urlencode($token);
		}

		$client = new Client($host, $port, $ssl);
		$client->set(['timeout' => -1, 'websocket_mask' => true, 'ssl_verify_peer' => false, 'ssl_verify_host' => false]);
		$headers = ['User-Agent' => 'oiapi-bot/1.0'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$client->setHeaders($headers);
		if (!$client->upgrade($path)) {
			Logger::warning('[Milky] WS 握手失败 | ' . $url . ' | errCode=' . $client->errCode);
			return null;
		}
		self::$ws = $client;
		return $client;
	}

	private static function wsLoop(Client $client): void {
		while (self::$wsRunning) {
			$frame = $client->recv(1.0);
			if ($frame === false || $frame === null) {
				if ($client->errCode) {
					Logger::warning('[Milky] WS 连接关闭 | errCode=' . $client->errCode);
					break;
				}
				continue;
			}
			if (!$frame instanceof \Swoole\WebSocket\Frame) continue;
			if ((int)$frame->opcode === \WEBSOCKET_OPCODE_PING) continue;
			$data = json_decode((string)$frame->data, true);
			if (is_array($data)) {
				self::handleEvent($data, 'ws');
			}
		}
		try { $client->close(); } catch (\Throwable $e) {}
		self::$ws = null;
	}

	// ---------------- SSE 客户端 ----------------

	private static function startSse(): void {
		if (self::$sseRunning) return;
		self::$sseRunning = true;
		$url = (string)(Config::string('milky.sse.url', '') ?: self::deriveSseUrl());
		$token = Config::string('milky.token', '');

		Coroutine::create(function () use ($url, $token) {
			$retry = 1;
			while (self::$sseRunning) {
				try {
					$x = self::runSse($url, $token);
					if ($x) $retry = 1;
				} catch (\Throwable $e) {
					Logger::error('[Milky] SSE 异常：' . $e->getMessage());
				}
				$delay = min(30, $retry * 2);
				$retry++;
				Logger::warning("[Milky] SSE 断开，{$delay} 秒后重连");
				Coroutine::sleep($delay);
			}
		});
	}

	private static function deriveSseUrl(): string {
		// 与 deriveWsUrl 同理：API 地址的主机/端口就是事件端点所在位置
		return rtrim(Config::string('milky.api', 'http://127.0.0.1:3010'), '/') . '/event';
	}

	private static function runSse(string $url, string $token): bool {
		$parts = parse_url($url);
		if (!$parts || empty($parts['host'])) {
			Logger::error('[Milky] 非法的 SSE 地址：' . $url);
			self::$sseRunning = false;
			return false;
		}
		$ssl  = in_array(($parts['scheme'] ?? 'http'), ['https'], true);
		$host = $parts['host'];
		$port = $parts['port'] ?? ($ssl ? 443 : 80);
		$path = ($parts['path'] ?? '/event');
		$query = $parts['query'] ?? '';
		if ($token !== '') {
			$query = ($query !== '' ? $query . '&' : '') . 'access_token=' . urlencode($token);
		}

		$client = new Client($host, $port, $ssl);
		$client->set([
			'timeout'          => -1,
			'connect_timeout'  => 5,
			'ssl_verify_peer'  => false,
			'ssl_verify_host'  => false,
			'headers'          => ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache'],
		]);
		$headers = ['Accept' => 'text/event-stream', 'Cache-Control' => 'no-cache', 'User-Agent' => 'oiapi-bot/1.0'];
		if ($token !== '') {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$client->setHeaders($headers);
		$client->get($path . ($query !== '' ? '?' . $query : ''));
		if ($client->statusCode !== 200) {
			Logger::warning('[Milky] SSE 连接失败 | HTTP ' . $client->statusCode);
			$client->close();
			return false;
		}
		Logger::success('[Milky] SSE 已连接：' . $url);

		$buffer = '';
		$dataLines = [];
		$deadline = time() + 90; // 90 秒无数据视为超时
		while (self::$sseRunning) {
			$chunk = $client->recv(1.0);
			if ($chunk === false || $chunk === null) {
				if ($client->errCode) {
					break;
				}
				if (time() > $deadline) {
					Logger::warning('[Milky] SSE 长时间无数据，主动重连');
					break;
				}
				continue;
			}
			if ($chunk instanceof \Swoole\WebSocket\Frame) {
				$chunk = (string)$chunk->data;
			}
			$buffer .= (string)$chunk;
			$deadline = time() + 90;

			// 按行解析 SSE 帧
			while (($pos = strpos($buffer, "\n")) !== false) {
				$line = rtrim(substr($buffer, 0, $pos), "\r");
				$buffer = substr($buffer, $pos + 1);
				if ($line === '') {
					// 空行 = 一帧结束
					if ($dataLines !== []) {
						$json = implode("\n", $dataLines);
						$dataLines = [];
						$decoded = json_decode($json, true);
						if (is_array($decoded)) {
							self::handleEvent($decoded, 'sse');
						} else {
							Logger::debug('[Milky] SSE 数据非 JSON：' . substr($json, 0, 120));
						}
					}
					continue;
				}
				if (str_starts_with($line, 'data:')) {
					$dataLines[] = ltrim(substr($line, 5));
				}
				// event: milky_event / id / retry 等字段忽略
			}
		}
		$client->close();
		return true;
	}

	public static function status(): array {
		return [
			'ws_running'  => self::$wsRunning,
			'ws_connected'=> self::$ws !== null,
			'sse_running' => self::$sseRunning,
		];
	}
}
