<?php
namespace Api;

use Utils\CoroutineCurl;
use Utils\Env;

class app {
	protected $config, $api;
	protected $curl;
	public function __construct() {
		$this->api = Env::get('cq.api');
		$this->curl = new CoroutineCurl();
		$this->curl->timeout(120);
		$this->curl->addheaders('Authorization', 'Bearer ' . Env::get('cq.auth'));
		$this->curl->addheaders('content-type', 'application/json');
	}
	/**
	 * 统一入口：若当前事件来自 OneBot-WS / Milky / QQ 官方机器人，
	 * 则通过协议桥转发给对应适配器；否则回落原有 HTTP 协议端。
	 */
	protected function get($url, ?array $data = []) : object {
		if (\Protocol\Core\Bridge::hasAdapter()) {
			return \Protocol\Core\Bridge::call($this->actionOf($url), $data ?: [], 'GET');
		}
		if($data) {
			if(str_contains($url, '?')) {
				$url .= '&' . http_build_query($data);
			} else {
				$url .= '?' . http_build_query($data);
			}
		}
		$this->curl->get($url);
		return ($this->curl->error ? $this->curl : $this->curl->object()) ?? new \stdClass();
	}
	protected function post($url, ?array $data = []) : object {
		if (\Protocol\Core\Bridge::hasAdapter()) {
			return \Protocol\Core\Bridge::call($this->actionOf($url), is_array($data) ? $data : [], 'POST');
		}
		$data = is_array($data) || is_object($data) ? json_encode($data, 320) : '{}';
		$this->curl->post($url, $data);
		// print_r($this->curl);
		return ($this->curl->error ? $this->curl : $this->curl->object()) ?? new \stdClass();
	}

	/**
	 * 从完整 URL 提取 OneBot action 名（{cq.api}/send_group_msg → send_group_msg）
	 */
	protected function actionOf(string $url) : string {
		$path = parse_url($url, PHP_URL_PATH) ?: $url;
		$segments = array_values(array_filter(explode('/', (string)$path), fn($v) => $v !== ''));
		return $segments === [] ? '' : (string)end($segments);
	}
}