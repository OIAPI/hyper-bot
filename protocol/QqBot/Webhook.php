<?php
namespace Protocol\QqBot;

use Utils\Logger;
use Protocol\Core\Config;

/**
 * QQ 官方机器人 Webhook 验签与回调地址校验
 *
 * 验签（Ed25519）：
 *   seed = AppSecret 重复拼接至 >=32 字节后取前 32 字节
 *   keypair = sodium_crypto_sign_seed_keypair(seed)  → 公钥用于验签、私钥用于握手签名
 *   签名体 msg = X-Signature-Timestamp . 原始 body（必须使用原始字节，不能 decode/encode）
 *   header: X-Signature-Ed25519（hex），校验长度 64 且 sig[63] & 0xE0 === 0
 *
 * 回调地址验证（op = 13）：
 *   请求 {"op":13,"d":{"plain_token":"...","event_ts":"..."}}
 *   响应 {"plain_token":"原样","signature":"hex(sign(event_ts . plain_token))"}
 */
class Webhook {
	public static function secret(): string {
		[$hit, $value] = \Core\Bot\AccountContext::get('qqbot.client_secret');
		if ($hit && (string)$value !== '') return (string)$value;
		$account = \Core\Bot\Accounts::firstEnabled('qqbot');
		if ($account !== null && (string)($account['client_secret'] ?? '') !== '') {
			return (string)$account['client_secret'];
		}
		return (string)(Config::string('qqbot.client_secret', '') ?: getenv('QQ_BOT_CLIENT_SECRET'));
	}

	/** 由 AppSecret 派生 Ed25519 密钥对 */
	public static function keypair(?string $secret = null): array {
		if (!function_exists('sodium_crypto_sign_seed_keypair')) {
			throw new \RuntimeException('需要 PHP sodium 扩展（ext-sodium）来校验 QQ 官方机器人 Webhook 签名');
		}
		$secret ??= self::secret();
		if ($secret === '') {
			throw new \RuntimeException('未配置 client_secret，无法校验 Webhook 签名');
		}
		$seed = $secret;
		while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) {
			$seed .= $seed;
		}
		$seed = substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
		$pair = sodium_crypto_sign_seed_keypair($seed);
		return [
			'public' => sodium_crypto_sign_publickey($pair),
			'secret' => sodium_crypto_sign_secretkey($pair),
		];
	}

	/**
	 * 校验回调签名
	 * @param array $headers 头信息（键需小写）
	 * @param string $rawBody 原始请求体
	 * @return bool
	 */
	public static function verify(array $headers, string $rawBody): bool {
		$signatureHex = $headers['x-signature-ed25519'] ?? '';
		$timestamp    = $headers['x-signature-timestamp'] ?? '';
		if ($signatureHex === '' || $timestamp === '') {
			Logger::warning('[QqBot] Webhook 缺少签名头，拒绝请求');
			return false;
		}

		$signature = @hex2bin($signatureHex);
		if ($signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
			Logger::warning('[QqBot] 签名长度非法');
			return false;
		}
		if ((ord($signature[63]) & 0xE0) !== 0) {
			Logger::warning('[QqBot] 签名格式非法（signature[63] & 224 != 0）');
			return false;
		}

		try {
			$pair = self::keypair();
			$msg  = $timestamp . $rawBody;
			$ok   = sodium_crypto_sign_verify_detached($signature, $msg, $pair['public']);
		} catch (\Throwable $e) {
			Logger::error('[QqBot] 验签异常：' . $e->getMessage());
			return false;
		}

		if (!$ok) {
			Logger::warning('[QqBot] Webhook 签名校验失败（可能是伪造的回调或 client_secret 不匹配）');
		}
		return $ok;
	}

	/**
	 * 回调地址验证（op=13）
	 * @param array $payload 解析后的回调体
	 * @return array 需要返回给平台的 JSON
	 */
	public static function validation(array $payload): array {
		$d = (array)($payload['d'] ?? []);
		$plainToken = (string)($d['plain_token'] ?? '');
		$eventTs    = (string)($d['event_ts'] ?? '');
		$pair = self::keypair();
		$signature = sodium_crypto_sign_detached($eventTs . $plainToken, $pair['secret']);
		return [
			'plain_token' => $plainToken,
			'signature'   => bin2hex($signature),
		];
	}

	/** 处理一条 webhook 回调，返回应答数组 */
	public static function handle(array $headers, string $rawBody): array {
		$payload = json_decode($rawBody, true);
		if (!is_array($payload)) {
			Logger::warning('[QqBot] Webhook 请求体非 JSON');
			return ['code' => -1, 'message' => 'invalid json'];
		}

		$op = (int)($payload['op'] ?? -1);
		// 回调地址验证
		if ($op === 13) {
			Logger::success('[QqBot] 收到回调地址验证请求');
			return self::validation($payload);
		}

		// 事件推送：验签
		if (!self::verify($headers, $rawBody)) {
			return ['code' => -1, 'message' => 'signature verification failed'];
		}

		// 事件分发
		EventClient::handlePayload($payload, 'webhook');

		// 回包：文档只给出 op=12 的 ACK 语义，未定义 body 结构，使用 {"op":12,"d":{}} 最稳妥
		return ['op' => 12, 'd' => new \stdClass()];
	}
}
