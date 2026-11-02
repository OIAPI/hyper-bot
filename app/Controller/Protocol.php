<?php
namespace App\Controller;

use Controller\Attr\Route;
use Controller\Attr\RoutePrefix;
use Protocol\Core\Config;
use Protocol\OneBot\WsHub;
use Protocol\Milky\EventClient as MilkyClient;
use Protocol\Milky\Api as MilkyApi;
use Protocol\QqBot\Webhook as QqBotWebhook;
use Protocol\QqBot\EventClient as QqBotClient;

/**
 * 协议接入端点（HTTP）
 *
 * - POST /api/protocol/webhook                  OneBot11 实现端主动推送到本框架（扩展形态）
 * - ALL  /api/protocol/onebot/ws/(:fd)          反向 WS 升级入口（实际由 HttpServer 的 open/message 处理）
 * - POST /api/protocol/milky/webhook             Milky WebHook 事件
 * - POST /api/protocol/qqbot/webhook             QQ 官方机器人 WebHook（含 op=13 回调地址验证）
 * - GET  /api/protocol/status                    各协议运行状态
 */
#[RoutePrefix('/api/protocol')]
class Protocol extends Common {
	/** OneBot11 HTTP 推送（实现端 POST 事件到本框架） */
	#[Route('POST', 'webhook')]
	public function webhook() {
		$event = (array)(\Utils\Cache::$param ?? []);
		if (!isset($event['post_type'])) {
			return $this->res(-1, '缺少 post_type');
		}
		\Protocol\Core\EventDispatcher::dispatch($event, new \Protocol\OneBot\Adapter());
		return $this->res(1, 'ok', ['status' => 'accepted']);
	}

	/** Milky WebHook 事件 */
	#[Route('POST', 'milky/webhook')]
	public function milkyWebhook() {
		$param = \Utils\Cache::$param;
		$event = is_object($param) ? (array)$param : (array)$param;
		$result = MilkyClient::handleEvent($event, 'webhook');
		return $this->res(1, 'ok', (array)($result['data'] ?? []));
	}

	/** QQ 官方机器人 WebHook（签名校验在中间件中完成，这里只分发） */
	#[Route('POST', 'qqbot/webhook')]
	public function qqbotWebhook() {
		$param = \Utils\Cache::$param;
		$payload = is_object($param) ? (array)$param : (array)$param;
		$raw = \Utils\Cache::get('raw_body', '');
		$headers = (array)\Utils\Cache::get('headers', []);

		if ((int)($payload['op'] ?? -1) === 13) {
			// 回调地址验证
			return QqBotWebhook::validation($payload);
		}

		// 签名校验（sodium 可用且配置了 secret 时强制校验）
		if (Config::bool('qqbot.webhook.verify', true) && function_exists('sodium_crypto_sign_verify_detached')) {
			$secret = Config::string('qqbot.client_secret', '');
			if ($secret !== '' && !QqBotWebhook::verify($headers, (string)$raw)) {
				return ['code' => -1, 'message' => 'signature verification failed'];
			}
		}

		QqBotClient::handlePayload($payload, 'webhook');
		return ['op' => 12, 'd' => new \stdClass()];
	}

	/** 协议状态 */
	#[Route('GET', 'status')]
	public function status() {
		$adapters = [
			'http' => [
				'enable' => true,
				'api'    => Config::string('default', 'http'),
			],
			'onebot' => [
				'enable'   => Config::bool('onebot.enable', false),
				'forward'  => ['running' => WsHub::hasForward(), 'url' => WsHub::forwardUrl()],
				'reverse'  => ['sessions' => count(WsHub::sessions())],
			],
			'milky' => [
				'enable' => Config::bool('milky.enable', false),
				'api'    => Config::string('milky.api', ''),
				'event'  => MilkyClient::status(),
			],
			'qqbot' => [
				'enable'  => Config::bool('qqbot.enable', false),
				'api'     => Config::string('qqbot.api', ''),
				'gateway' => QqBotClient::status(),
			],
		];
		return $this->res(1, 'ok', $adapters);
	}
}
