<?php
namespace Utils;


class Worker {
	private static self $instance;
	private string $cacheKey = 'worker_list';

	private function __construct() {}

	public static function getInstance(): self {
		if (!isset(self::$instance)) {
			self::$instance = new self();
			// 可选：构造时预检查，确保缓存存在（提前兜底）
			$this->initIfNotExists();
		}
		return self::$instance;
	}

	/**
	 * 核心方法：获取Worker列表，不存在则自动初始化空列表
	 */
	public function getWorkerList(): array {
		$cache = Cache::$api->redis->get($this->cacheKey);
		
		// 1. 缓存不存在/过期 → 初始化空列表并写入缓存
		if (!$cache) {
			$this->initWorkerList(); // 执行初始化
			return []; // 初始化后返回空列表
		}

		// 2. 缓存存在 → 解析为数组（避免json_decode返回null）
		$list = json_decode($cache, true);
		return is_array($list) ? $list : [];
	}

	/**
	 * 初始化Worker列表（私有方法，仅内部调用）
	 */
	private function initWorkerList(): bool {
		// 方案1：默认初始化空列表（推荐，最小侵入）
		return Cache::$api->redis->set($this->cacheKey, json_encode([]));

		// 方案2：初始化时自动加入“默认启用的Worker”（如基础插件）
		// $defaultWorkers = ['App\Plugins\BaseWorker', 'App\Plugins\Demo'];
		// return Cache::$api->redis->set($this->cacheKey, json_encode($defaultWorkers));
	}

	/**
	 * 预检查初始化（可选，在构造或首次调用时触发）
	 */
	private function initIfNotExists(): void {
		if (!Cache::$api->redis->exists($this->cacheKey)) {
			$this->initWorkerList();
		}
	}

	/**
	 * 新增Worker（插件启用）
	 * @param string $className 完整类名（如App\Plugins\Demo）
	 */
	public function addWorker(string $className): bool {
		$list = $this->getWorkerList();
		if (!in_array($className, $list)) {
			$list[] = $className;
			return Cache::$api->redis->set($this->cacheKey, json_encode($list));
		}
		return true;
	}

	/**
	 * 删除Worker（插件禁用）
	 * @param string $className 完整类名
	 */
	public function removeWorker(string $className): bool {
		$list = $this->getWorkerList();
		$newList = array_filter($list, fn($item) => $item !== $className);
		return Cache::$api->redis->set($this->cacheKey, json_encode($newList));
	}
}
