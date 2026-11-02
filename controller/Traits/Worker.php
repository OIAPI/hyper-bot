<?php
namespace Controller\Traits;

use Controller\Router;
use Utils\Cache;
use Utils\Logger;

trait Worker {
	/**
	 * 获取群信息（优化版：避免递归，支持插件）
	 * @param string|float|int $group 群号
	 * @return object|bool 群信息（失败返回false）
	 */
	public function getGroupInfo(string|float|int $group): object|bool {
		$redis = Cache::$api->redis;
		$groupKey = "{$group}.info";
		
		// 1. 优先读取Redis缓存
		if ($redis->exists($groupKey)) {
			$data = json_decode($redis->get($groupKey));
			if (!$data->worker) {
				// Worker为空时重新初始化
				$data->worker = $this->scanWorkerList();
				$redis->set($groupKey, json_encode($data, 320));
				// Logger::debug("初始化 worker || {$group}");
			}
			// Logger::debug("获取 info || {$group}");
			return $data;
		}
		
		// 2. 缓存不存在，直接创建新数据
		$data = (object)[
			'status' => 0, 
			'worker' => $this->scanWorkerList(),
		];
		$redis->set($groupKey, json_encode($data, 320));
		// Logger::debug("创建新的 info || {$group}");
		return $data;
	}

	/**
	 * 检测群状态：开启返回true，关闭返回false
	 * @param string|float|int $group 群号
	 * @return bool 群状态
	 */
	public function groupStatus(string|float|int $group): bool {
		$groupInfo = $this->getGroupInfo($group);
		return $groupInfo->status === 1;
	}

	/**
	 * 初始化群信息（兼容旧数据）
	 * @param string|float|int $group 群号
	 * @param bool $isArray 是否返回数组
	 * @return object|array|bool 群信息
	 */
	public function initGroupInfo(string|float|int $group, bool $isArray = false): object|array|bool {
		$groupKey = "{$group}.info";
		$status = 0;
		$worker = [];

		// 读取旧数据（如果存在）
		if ($this->redis->exists($groupKey)) {
			$oldData = json_decode($this->redis->get($groupKey));
			$status = $oldData->status ?? 0;
			$worker = (array)($oldData->worker ?? []);
		}

		// 同步Worker列表
		$latestWorker = $this->scanWorkerList();
		$workerMap = $latestWorker;
		
		// 保留原有的worker状态
		foreach ($worker as $key => $val) {
			if (isset($workerMap[$key])) {
				$workerMap[$key] = $val;
			}
		}

		// 更新缓存
		$groupInfo = [
			'status' => $status,
			'worker' => $workerMap
		];
		$this->redis->set($groupKey, json_encode($groupInfo, JSON_UNESCAPED_UNICODE));
		
		Logger::success("[群信息初始化] 群{$group}：状态={$status}，Worker数=" . count($workerMap));
		return $isArray ? $groupInfo : (object)$groupInfo;
	}

	/**
	 * 更新群信息
	 * @param string|float|int $group 群号
	 * @param object|array $info 新群信息
	 * @return bool 更新结果
	 */
	public function updateGroupInfo(string|float|int $group, object|array $info): bool {
		$groupKey = "{$group}.info";
		$info = is_object($info) ? (array)$info : $info;

		$info['status'] = $info['status'] ?? 0;
		$info['worker'] = $info['worker'] ?? [];

		$result = $this->redis->set($groupKey, json_encode($info, JSON_UNESCAPED_UNICODE));
		Router::clean(); // 清理路由缓存

		return $result;
	}

	/**
	 * 扫描Worker列表（对接Router的自动扫描逻辑）
	 * @return array Worker类名列表
	 */
	public function scanWorkerList(): array {
		return Router::scanAppClasses();
	}
}