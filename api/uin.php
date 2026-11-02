<?php
namespace Api;
use Redis;

class uin {
	public Redis $Redis;
	public $data;
	public function __construct() {
		global $api;
		$this->Redis = $api->Redis;
		$this->Redis->select(5);
		$this->data();
	}
	public function get($uin) {
		if(isset($this->data[$uin])) return $this->data[$uin];
		return $uin;
	}
	public function set($uin, $openid) {
		$this->data[$uin] = $openid;
		$this->Redis->set('102129105.member', json_encode($this->data, 320));
		$this->data();
		return true;
	}
	public function data() {
		$this->data = json_decode(($this->Redis->get('102129105.member') ?: '{}'), true);
	}
}