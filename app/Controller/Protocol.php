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
		$event = (array)(\Utils\Cache::get('param') ?? []);
		if (!isset($event['post_type'])) {
			return $this->res(-1, '缺少 post_type');
		}
		\Protocol\Core\EventDispatcher::dispatch($event, new \Protocol\OneBot\Adapter());
		return $this->res(1, 'ok', ['status' => 'accepted']);
	}

	/** Milky WebHook 事件 */
	#[Route('POST', 'milky/webhook')]
	public function milkyWebhook() {
		/*
		 * 鉴权：Milky 协议端会在 WebHook 请求里带 Authorization: Bearer {access_token}，
		 * 应用端必须自行校验。协议端配置了多个 WebHook 地址时同一条事件会被重复投递若干次，
		 * 幂等去重已在 Milky\EventClient::handleEvent 内完成。
		 */
		$expect = Config::string('milky.token', '');
		if ($expect !== '') {
			$headers = (array)\Utils\Cache::getLocal('headers', []);
			$auth = (string)($headers['authorization'] ?? '');
			$token = str_starts_with(strtolower($auth), 'bearer ') ? trim(substr($auth, 7)) : '';
			if ($token === '' || !hash_equals($expect, $token)) {
				\Utils\Logger::warning('[Milky] WebHook 鉴权失败，已拒绝');
				return $this->res(-1, 'unauthorized');
			}
		}

		$param = \Utils\Cache::get('param');
		$event = is_object($param) ? (array)$param : (array)$param;
		$result = MilkyClient::handleEvent($event, 'webhook');
		return $this->res(1, 'ok', (array)($result['data'] ?? []));
	}

	/** QQ 官方机器人 WebHook（签名校验在中间件中完成，这里只分发） */
	#[Route('POST', 'qqbot/webhook')]
	public function qqbotWebhook() {
		$param = \Utils\Cache::get('param');
		$payload = is_object($param) ? (array)$param : (array)$param;
		$raw = (string)\Utils\Cache::getLocal('raw_body', '');
		$headers = (array)\Utils\Cache::getLocal('headers', []);

		if ((int)($payload['op'] ?? -1) === 13) {
			// 回调地址验证
			return QqBotWebhook::validation($payload);
		}

		/*
		 * 签名校验（Ed25519）
		 *
		 * - verify = true（默认）：sodium 不可用 / 未配置 secret 都属于配置错误，直接拒绝，
		 *   避免把「验签失败」静默降级成「完全不验签」的安全漏洞；
		 * - verify = false：仅在本地联调时可关闭。
		 */
		if (Config::bool('qqbot.webhook.verify', true)) {
			$secret = Config::string('qqbot.client_secret', '');
			if ($secret === '') {
				return ['code' => -1, 'message' => 'client_secret 未配置，无法校验签名（可设置 webhook.verify = false 关闭）'];
			}
			if (!function_exists('sodium_crypto_sign_verify_detached')) {
				return ['code' => -1, 'message' => '缺少 ext-sodium 扩展，无法校验签名'];
			}
			if (!QqBotWebhook::verify($headers, (string)$raw)) {
				return ['code' => -1, 'message' => 'signature verification failed'];
			}
		} else {
			\Utils\Logger::warning('[QqBot] 已按配置关闭 Webhook 验签（仅建议本地联调使用）');
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
				'reverse'  => ['sessions' => WsHub::reverseCount()],
			],
			'milky' => [
				'enable' => Config::bool('milky.enable', false),
				'api'    => Config::string('milky.api', ''),
				'event'  => MilkyClient::status(),
				'ws_in'  => ['sessions' => WsHub::milkyCount()],
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
