<?php
namespace Protocol\Core;

use Utils\CoroutineCurl;
use Utils\Env;

/**
 * 传统 HTTP OneBot/CQ 协议端适配器（基线能力）
 * 直接以 HTTP 调用 ${cq.api}，兼容原有 webhook-only 部署。
 */
class HttpAdapter extends Adapter {
	protected string $name = 'http';
	protected string $title = 'OneBot11 HTTP(CQ)';
	protected string $base;
	protected string $token;

	public function __construct(array $config = []) {
		$this->base  = rtrim((string)Env::get('cq.api', ''), '/');
		$this->token = (string)Env::get('cq.auth', '');
		parent::__construct($config);
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		$curl = new CoroutineCurl();
		$curl->timeout(120);
		if ($this->token !== '') {
			$curl->addheaders('Authorization', 'Bearer ' . $this->token);
		}
		$curl->addheaders('content-type', 'application/json');

		$url = $this->base . '/' . ltrim($action, '/');
		$payload = $params === [] ? [] : $params;

		try {
			if (strtoupper($method) === 'GET') {
				$curl->get($url, $payload ? http_build_query($payload, '', '&', PHP_QUERY_RFC3986) : '');
			} else {
				$curl->post($url, json_encode($payload, JSON_UNESCAPED_UNICODE));
			}
		} catch (\Throwable $e) {
			return $this->fail(-1, "HTTP 协议端调用失败：{$e->getMessage()}");
		}

		if ($curl->error) {
			return $this->fail(-1, "HTTP 协议端调用失败：" . (is_string($curl->error) ? $curl->error : '未知错误'));
		}

		$json = json_decode((string)$curl->result, true);
		if (!is_array($json)) {
			return $this->fail(-1, 'HTTP 协议端返回非 JSON 数据：' . substr((string)$curl->result, 0, 200));
		}

		// 兼容两种响应形态：OneBot11（status/retcode）与老 CQ（code/message）
		$code = $json['code'] ?? ($json['retcode'] ?? 0);
		$status = $json['status'] ?? ((int)$code === 1 || (int)$code === 0 ? 'ok' : 'failed');
		return (object)[
			'status'  => $status,
			'retcode' => $json['retcode'] ?? $code,
			'data'    => $json['data'] ?? null,
			'message' => $json['message'] ?? '',
		];
	}
}
