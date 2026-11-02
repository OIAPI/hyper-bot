<?php
namespace Protocol;

use Utils\Logger;
use Protocol\Core\Config;
use Protocol\OneBot\WsHub;
use Protocol\Milky\EventClient as MilkyEvent;
use Protocol\QqBot\EventClient as QqBotEvent;

/**
 * 协议启动引导：在 Worker 0 启动所有常驻客户端
 *
 * 说明：
 *  - OneBot 反向 WS 由 Swoole 的 open/message 事件驱动（见 WsHub::bindServer），无需常驻协程；
 *  - OneBot 正向 WS / Milky WS·SSE / QQ 官方 Gateway 都是「框架作为客户端主动连接」，
 *    必须在 Worker 内以协程常驻运行。
 */
class WebhookRouter {
	private static bool $booted = false;

	public static function boot(): void {
		if (self::$booted) return;
		self::$booted = true;

		$enabled = [];

		// ---------- OneBot11 正向 WS ----------
		if (Config::bool('onebot.enable', false)) {
			$url = Config::string('onebot.forward.url', '');
			if (Config::bool('onebot.forward.enable', false) && $url !== '') {
				WsHub::startForward($url, Config::string('onebot.forward.token', ''));
				$enabled[] = "OneBot 正向 WS → {$url}";
			} elseif (Config::bool('onebot.forward.enable', false)) {
				Logger::warning('[OneBot] 已开启正向 WS 但未配置 url（protocol.onebot.forward.url）');
			}

			// ---------- OneBot11 反向 WS（服务端） ----------
			if (Config::bool('onebot.reverse.enable', false)) {
				$path = Config::string('onebot.reverse.path', '/onebot/v11/ws');
				$enabled[] = "OneBot 反向 WS ← ws://{host}:{port}{$path}";
			}
		}

		// ---------- Milky ----------
		if (Config::bool('milky.enable', false)) {
			MilkyEvent::start();
			$enabled[] = 'Milky → ' . Config::string('milky.api', '') . '（事件：' . Config::string('milky.event.mode', 'webhook') . '）';
		}

		// ---------- QQ 官方机器人 ----------
		if (Config::bool('qqbot.enable', false)) {
			QqBotEvent::start();
			$enabled[] = 'QQ 官方机器人 → ' . Config::string('qqbot.api', 'https://api.bot.qq.com') . '（事件：' . Config::string('qqbot.event.mode', 'ws') . '）';
		}

		if ($enabled === []) {
			Logger::info('[协议] 未启用任何扩展协议（OneBot WS / Milky / QQ 官方机器人）');
		} else {
			foreach ($enabled as $line) {
				Logger::success('[协议] 已启动：' . $line);
			}
		}
	}
}
