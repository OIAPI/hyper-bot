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
	protected function get($url, ?array $data = []) : object {
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
		$data = is_array($data) || is_object($data) ? json_encode($data, 320) : '{}';
		$this->curl->post($url, $data);
		// print_r($this->curl);
		return ($this->curl->error ? $this->curl : $this->curl->object()) ?? new \stdClass();
	}
}