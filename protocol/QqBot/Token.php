<?php
namespace Protocol\QqBot;

use Utils\CoroutineCurl;
use Utils\Logger;
use Protocol\Core\Config;

/**
 * QQ 官方机器人 AccessToken 管理
 *
 * POST https://api.bot.qq.com/app/getAppAccessToken（生产/沙箱共用）
 *   body: {"appId":"...","clientSecret":"..."}
 *   成功: {"access_token":"...","expires_in":"7200"}（expires_in 是字符串）
 *   失败: {"code":100007,...,"message":"..."} —— 失败时 HTTP 仍是 200，必须按 body.code 判断
 *
 * 调用头：Authorization: QQBot {ACCESS_TOKEN}
 */
class Token {
	private static ?string $token = null;
	private static int $expiresAt = 0;
	private static float $refreshingUntil = 0.0;

	/** 优先读当前账号（多账号），没有账号上下文再回落到 Config.ini / 环境变量 */
	private static function fromAccount(string $key): string {
		[$hit, $value] = \Core\Bot\AccountContext::get($key);
		return $hit ? (string)$value : '';
	}

	/** 第一次启动 Gateway 时没有事件上下文，从「机器人账号」里取第一个启用的官方号 */
	private static function fromEnabledAccount(string $field): string {
		$account = \Core\Bot\Accounts::firstEnabled('qqbot');
		if ($account === null) return '';
		return (string)($account[$field] ?? '');
	}

	public static function appId(): string {
		$fromAccount = self::fromAccount('qqbot.app_id');
		if ($fromAccount !== '') return $fromAccount;
		$fromEnabled = self::fromEnabledAccount('app_id');
		if ($fromEnabled !== '') return $fromEnabled;
		return (string)(Config::string('qqbot.app_id', '') ?: getenv('QQ_BOT_APP_ID'));
	}

	public static function secret(): string {
		$fromAccount = self::fromAccount('qqbot.client_secret');
		if ($fromAccount !== '') return $fromAccount;
		$fromEnabled = self::fromEnabledAccount('client_secret');
		if ($fromEnabled !== '') return $fromEnabled;
		return (string)(Config::string('qqbot.client_secret', '') ?: getenv('QQ_BOT_CLIENT_SECRET'));
	}

	/** 当前账号是否沙箱模式（QQ 官方只允许生产/沙箱两个固定域名） */
	public static function sandbox(): bool {
		$account = \Core\Bot\AccountContext::current() ?? \Core\Bot\Accounts::firstEnabled('qqbot');
		return $account !== null && (bool)($account['sandbox'] ?? false);
	}

	public static function apiBase(): string {
		return self::sandbox() ? \Core\Bot\Accounts::QQ_SANDBOX_API : \Core\Bot\Accounts::QQ_PROD_API;
	}

	/** token 接口固定（生产/沙箱共用同一个发 token 的入口） */
	private static function tokenUrl(): string {
		return 'https://api.bot.qq.com/app/getAppAccessToken';
	}

	/**
	 * 获取可用 token（自动缓存与提前刷新）
	 * @param bool $force 强制刷新
	 * @return string|null 失败返回 null
	 */
	public static function get(bool $force = false): ?string {
		$now = time();
		// 提前 120 秒刷新（官方在过期前 60 秒内才发新 token）
		if (!$force && self::$token !== null && self::$expiresAt - 120 > $now) {
			return self::$token;
		}

		// 简易并发保护：避免同进程并发请求触发 100001（频繁请求）
		if (!$force && microtime(true) < self::$refreshingUntil && self::$token !== null) {
			return self::$token;
		}
		self::$refreshingUntil = microtime(true) + 3.0;

		$appId  = self::appId();
		$secret = self::secret();
		if ($appId === '' || $secret === '') {
			Logger::error('[QqBot] 未配置 AppID / ClientSecret：请在「机器人账号」页添加 QQ 官方机器人（AppID/AppSecret），或配置 Config.ini [protocol.qqbot]');
			return null;
		}

		$curl = new CoroutineCurl();
		$curl->timeout(15);
		$curl->addHeaders('Content-Type', 'application/json');
		try {
			$curl->post(self::tokenUrl(), json_encode(['appId' => $appId, 'clientSecret' => $secret]));
		} catch (\Throwable $e) {
			Logger::error('[QqBot] 获取 AccessToken 异常：' . $e->getMessage());
			return null;
		}

		$body = json_decode((string)$curl->result, true);
		if (!is_array($body)) {
			Logger::error('[QqBot] AccessToken 返回非 JSON：' . substr((string)$curl->result, 0, 200));
			return null;
		}
		if (isset($body['code'])) {
			Logger::error('[QqBot] 获取 AccessToken 失败 | code=' . $body['code'] . ' | ' . ($body['message'] ?? ''));
			return null;
		}
		$token = (string)($body['access_token'] ?? '');
		if ($token === '') {
			Logger::error('[QqBot] AccessToken 响应缺少 access_token');
			return null;
		}

		self::$token     = $token;
		self::$expiresAt = $now + (int)($body['expires_in'] ?? 7200);
		Logger::success('[QqBot] AccessToken 已刷新，有效期至 ' . date('H:i:s', self::$expiresAt));
		return $token;
	}

	/** 供 Gateway Identify 使用的 token 字符串 */
	public static function identifyToken(): ?string {
		$token = self::get();
		return $token === null ? null : 'QQBot ' . $token;
	}
}
