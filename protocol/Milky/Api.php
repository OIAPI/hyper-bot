<?php
namespace Protocol\Milky;

use Utils\CoroutineCurl;
use Utils\Logger;
use Protocol\Core\Config;

/**
 * Milky HTTP API 客户端
 *
 * 与 OneBot 方向相反：Milky 协议端（QQ 端实现）提供 HTTP 服务，本框架作为客户端调用。
 * - 端点：POST {base}/api/{api_name}
 * - 鉴权：Authorization: Bearer {access_token}
 * - 响应：{status: ok|failed, retcode, data, message}
 * - 状态码：200（业务成败都在 200）/401/404/415
 */
class Api {
	private string $base;
	private string $token;
	private float $timeout;

	public function __construct(array $config = []) {
		$base = (string)($config['api'] ?? Config::string('milky.api', 'http://127.0.0.1:3010'));
		$this->base    = rtrim($base, '/');
		$this->token   = (string)($config['token'] ?? Config::string('milky.token', ''));
		$this->timeout = (float)($config['timeout'] ?? Config::int('milky.timeout', 30));
	}

	/**
	 * 直接调用 Milky 原生 API
	 * @return object{status,retcode,data,message}
	 */
	public function call(string $api, array $params = []): object {
		$curl = new CoroutineCurl();
		$curl->timeout((int)$this->timeout);
		$curl->accept('object');
		$curl->addHeaders('Content-Type', 'application/json');
		$curl->addHeaders('Accept', 'application/json');
		if ($this->token !== '') {
			$curl->addHeaders('Authorization', 'Bearer ' . $this->token);
		}

		$url = $this->base . '/api/' . ltrim($api, '/');
		try {
			$curl->post($url, json_encode((object)$params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
		} catch (\Throwable $e) {
			return $this->fail(-1, "Milky API 调用异常：{$e->getMessage()}");
		}

		$httpCode = (int)($curl->info['http_code'] ?? 0);
		if ($httpCode === 401) return $this->fail(-401, 'Milky 鉴权失败（access_token 不匹配）');
		if ($httpCode === 404) return $this->fail(-404, "Milky API 不存在：{$api}");
		if ($httpCode === 415) return $this->fail(-415, 'Milky 不接受该 Content-Type');
		if ($curl->error) {
			return $this->fail(-1, 'Milky 网络错误：' . (is_string($curl->error) ? $curl->error : '未知'));
		}

		$data = json_decode((string)$curl->result);
		if (!is_object($data)) {
			return $this->fail(-1, 'Milky 返回非 JSON：' . substr((string)$curl->result, 0, 200));
		}

		// retcode 只判 0 / 非 0（其余错误码由协议端自行定义）
		return (object)[
			'status'  => $data->status ?? 'failed',
			'retcode' => (int)($data->retcode ?? -1),
			'data'    => $data->data ?? new \stdClass(),
			'message' => $data->message ?? '',
		];
	}

	/** 判断是否可用（配置了地址即认为启用） */
	public function configured(): bool {
		return $this->base !== '';
	}

	private function fail(int $code, string $message): object {
		return (object)['status' => 'failed', 'retcode' => $code, 'data' => null, 'message' => $message];
	}

	public function baseUrl(): string {
		return $this->base;
	}
}
