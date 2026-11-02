<?php
namespace App\Controller;

use Utils\Cache;

abstract class Common extends \App\BaseController {
	protected ?\Controller\Api $api;
	public function __construct() {
		$this->api = Cache::$api;
	}
	protected function res(int $code = 1, ?string $message = null, array|object $data = []) {
		return [
			'code' => $code,
			'message' => $message,
			'data' => $data,
			'timestamp' => time(),
		];
	}
}