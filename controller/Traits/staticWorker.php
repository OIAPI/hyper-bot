<?php
namespace Controller\Traits;

use Controller\Router;
use Utils\Cache;
use Utils\Logger;

trait staticWorker {
	public static function getGroupInfo(float $group) {
		$redis = Cache::$api->redis;
		
		// 先检查是否存在，避免递归
		if($redis->exists("{$group}.info")) {
			$data = json_decode($redis->get("{$group}.info"));
			if(!$data->worker) {
				$data->worker = Router::scanAppClasses();
				// Logger::debug("初始化 worker || {$group}");
				$redis->set("{$group}.info", json_encode($data, 320));
			}
			// Logger::debug("获取 info || {$group}");
			return $data;
		}
		
		// 不存在时直接创建并返回
		$data = ['status' => 0, 'worker' => Router::scanAppClasses(),];
		$redis->set("{$group}.info", json_encode($data, 320));
		// Logger::debug("创建新的 info || {$group}");
		return $data;
	}
}