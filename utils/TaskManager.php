<?php
// src/Utils/TaskManager.php
namespace Utils;

use Swoole\Timer;
use Utils\Logger;
use Utils\Cache;

class TaskManager
{
	private static ?TaskManager $instance = null;
	private array $tasks = [];
	private array $running = [];
	private array $restoredFromRedis = [];
	private string $storageKey = 'task_manager:tasks';
	private bool $initialized = false;

	private function __construct() {}

	public static function getInstance(): self
	{
		$currentPid = getmypid();
		
		if (self::$instance !== null) {
			return self::$instance;
		}
		
		try {
			if (Cache::$api && Cache::$api->redis) {
				$redis = Cache::$api->redis;
				$redis->select(Env::get('Redis.select', 0));
				$masterPid = $redis->get('task_manager:master_pid');
				
				if ($masterPid && $masterPid != $currentPid) {
					// 不是管理进程，返回空壳
					self::$instance = new self();
					return self::$instance;
				}
				
				// 是管理进程（或首次）
				if (!$masterPid) {
					$redis->set('task_manager:master_pid', $currentPid);
				}
			}
		} catch (\Throwable $e) {}
		
		self::$instance = new self();
		self::$instance->init();
		
		return self::$instance;
	}

	public function init(): void
	{
		if ($this->initialized) {
			$this->restore();
			return;
		}
		$this->initialized = true;
		$this->restore();
	}

	/**
	 * 添加定时任务
	 */
	public function add(string $name, callable $callback, int $interval, bool $allowOverlap = false, bool $immediate = false, array|string $params = []): self
	{
		// 干掉旧任务
		// var_dump(getmypid());
		if (isset($this->tasks[$name])) {
			Timer::clear($this->tasks[$name]['_timer_id']);
		}
		unset($this->restoredFromRedis[$name]);

		if (is_string($params)) $params = [$params];

		$timerId = Timer::tick($interval * 1000, function () use ($name) {
			$this->execute($name);
		});

		$this->tasks[$name] = [
			'_timer_id'	=> $timerId,
			'name'		 => $name,
			'callback'	 => $callback,
			'interval'	 => $interval,
			'allow_overlap' => $allowOverlap,
			'params'	   => $params,
			'run_count'	=> 0,
			'active'	   => true,
		];

		if ($immediate) {
			$this->execute($name);
		}

		$this->saveToRedis();

		Logger::debug("[TaskManager] 添加任务: {$name}，间隔 {$interval}s");

		return $this;
	}

	/**
	 * 延迟执行（一次性）
	 */
	public function after(string $name, callable $callback, int $after, array|string $params = []): self
	{
		if (isset($this->tasks[$name])) {
			Timer::clear($this->tasks[$name]['_timer_id']);
		}

		if (is_string($params)) $params = [$params];

		$timerId = Timer::after($after * 1000, function () use ($name) {
			$this->execute($name);
			unset($this->tasks[$name]);
		});

		$this->tasks[$name] = [
			'_timer_id'	=> $timerId,
			'name'		 => $name,
			'callback'	 => $callback,
			'interval'	 => 0,
			'allow_overlap' => false,
			'params'	   => $params,
			'run_count'	=> 0,
			'active'	   => true,
		];

		Logger::debug("[TaskManager] 添加延迟任务: {$name}，{$after}s后执行");

		return $this;
	}

	/**
	 * 执行任务
	 */
	private function execute(string $name): void
	{
		if (!isset($this->tasks[$name]) || !$this->tasks[$name]['active']) {
			return;
		}

		$task = &$this->tasks[$name];

		if (!$task['allow_overlap'] && in_array($name, $this->running, true)) {
			return;
		}

		$task['run_count']++;
		$this->running[] = $name;

		$callback = $task['callback'];
		$params   = $task['params'] ?? [];

		go(function () use ($name, $callback, $params) {
			try {
				if (is_array($callback) && is_string($callback[0])) {
					$callback[0] = new $callback[0];
				}
				call_user_func($callback, ...$params);
			} catch (\Throwable $e) {
				Logger::error("[TaskManager] 任务 '{$name}' 异常: {$e->getMessage()}");
			} finally {
				$index = array_search($name, $this->running, true);
				if ($index !== false) {
					unset($this->running[$index]);
				}
			}
		});
	}

	/**
	 * 暂停任务
	 */
	public function pause(string $name): bool
	{
		if (!isset($this->tasks[$name])) return false;

		$this->tasks[$name]['active'] = false;
		Timer::clear($this->tasks[$name]['_timer_id']);

		$this->saveToRedis();
		Logger::info("[TaskManager] 任务 '{$name}' 已暂停");
		return true;
	}

	public function stop(string $name): bool
	{
		return $this->pause($name);
	}

	/**
	 * 恢复任务
	 */
	public function resume(string $name): bool
	{
		if (!isset($this->tasks[$name])) return false;

		$task = &$this->tasks[$name];
		$task['active'] = true;

		$task['_timer_id'] = Timer::tick($task['interval'] * 1000, function () use ($name) {
			$this->execute($name);
		});

		$this->execute($name);
		$this->saveToRedis();

		Logger::info("[TaskManager] 任务 '{$name}' 已恢复");
		return true;
	}

	public function start(string $name): bool
	{
		return $this->resume($name);
	}

	/**
	 * 删除任务
	 */
	public function remove(string $name): bool
	{
		if (!isset($this->tasks[$name])) return false;

		Timer::clear($this->tasks[$name]['_timer_id']);
		unset($this->tasks[$name]);
		unset($this->restoredFromRedis[$name]);
		$this->saveToRedis();

		Logger::info("[TaskManager] 任务 '{$name}' 已删除");
		return true;
	}

	/**
	 * 清空所有任务
	 */
	public function clear(): void
	{
		Timer::clearAll();

		$this->tasks	= [];
		$this->running  = [];
		$this->restoredFromRedis = [];

		$this->saveToRedis();

		try {
			if (Cache::$api && Cache::$api->redis) {
				$redis = Cache::$api->redis;
				$redis->select(Env::get('Redis.select', 0));
				$redis->del($this->storageKey);
			}
		} catch (\Throwable $e) {
			Logger::error("[TaskManager] clear 删除 Redis key 失败: {$e->getMessage()}");
		}

		Logger::info("[TaskManager] 已清空所有任务");
	}

	/**
	 * 获取任务状态
	 */
	public function status(string $name = null): array
	{
		if ($name !== null) {
			return $this->getSingleStatus($name);
		}

		$status = [];
		foreach ($this->tasks as $name => $task) {
			$status[] = $this->getSingleStatus($name);
		}
		return $status;
	}

	private function getSingleStatus(string $name): array
	{
		if (!isset($this->tasks[$name])) {
			return ['error' => '任务不存在'];
		}

		$task = $this->tasks[$name];
		return [
			'name'	  => $name,
			'interval'  => $task['interval'] . 's',
			'run_count' => $task['run_count'],
			'active'	=> $task['active'],
			'running'   => in_array($name, $this->running, true),
		];
	}

	// ==================== 持久化 ====================

	/**
	 * 从 Redis 恢复所有任务
	 */
	private function restore(): void
	{
		$savedTasks = $this->loadFromRedis();
	
		if (empty($savedTasks)) {
			Logger::debug("[TaskManager] Redis中无任务数据");
			return;
		}
	
		// 清除现有所有 timer
		Timer::clearAll();
		
		$this->tasks   = [];
		$this->running = [];
		$this->restoredFromRedis = [];
	
		$restored = 0;
		foreach ($savedTasks as $name => $data) {
			if (($data['interval'] ?? 0) < 1) continue;
	
			$callback = $this->resolveCallback($data);
			if ($callback === null) continue;
	
			// 调 add，add 内部管理 _timer_id
			$this->add($name, $callback, $data['interval'], $data['allow_overlap'] ?? false, false, $data['params'] ?? []);
	
			// 恢复运行次数
			$this->tasks[$name]['run_count'] = $data['run_count'] ?? 0;
	
			// 恢复暂停
			if (!($data['active'] ?? true)) {
				$this->pause($name);
			}
	
			$this->restoredFromRedis[$name] = true;
			$restored++;
		}
	
		Logger::info("[TaskManager] 恢复 {$restored} 个任务");
	}

	/**
	 * 从 Redis 数据解析回调
	 */
	private function resolveCallback(array $data): ?callable
	{
		if (isset($data['class'], $data['method'])) {
			return [$data['class'], $data['method']];
		}

		if (isset($data['object'], $data['method'])) {
			$obj = @unserialize($data['object']);
			if ($obj !== false) {
				return [$obj, $data['method']];
			}
		}

		if (isset($data['closure'])) {
			return $this->unserializeClosure($data['closure']);
		}

		return null;
	}

	/**
	 * 保存任务到 Redis
	 */
	private function saveToRedis(): void
	{
		try {
			if (!Cache::$api || !Cache::$api->redis) return;

			$redis = Cache::$api->redis;
			$redis->select(Env::get('Redis.select', 0));

			$data = [];
			foreach ($this->tasks as $name => $task) {
				// after 任务不持久化
				if ($task['interval'] === 0) {
					continue;
				}

				$entry = [
					'name'		  => $name,
					'interval'	  => $task['interval'],
					'run_count'	 => $task['run_count'],
					'active'		=> $task['active'],
					'allow_overlap' => $task['allow_overlap'],
					'params'		=> $task['params'] ?? [],
				];

				$callback = $task['callback'];

				if (is_array($callback) && is_string($callback[0])) {
					$entry['class']  = $callback[0];
					$entry['method'] = $callback[1];
				} elseif (is_array($callback) && is_object($callback[0])) {
					$entry['object'] = serialize($callback[0]);
					$entry['method'] = $callback[1];
				} elseif ($callback instanceof \Closure) {
					$entry['closure'] = $this->serializeClosure($callback);
				}

				$data[$name] = $entry;
			}

			$redis->set($this->storageKey, json_encode($data, JSON_UNESCAPED_UNICODE));
		} catch (\Throwable $e) {
			Logger::error("[TaskManager] 保存失败: {$e->getMessage()}");
		}
	}

	/**
	 * 序列化闭包
	 */
	private function serializeClosure(\Closure $fn): string
	{
		$ref  = new \ReflectionFunction($fn);
		$file = file($ref->getFileName());
		$code = '';
		for ($i = $ref->getStartLine() - 1; $i < $ref->getEndLine(); $i++) {
			$code .= $file[$i];
		}

		$body = '';
		if (preg_match('/(function\s*\([^)]*\)\s*(use\s*\([^)]*\))?\s*\{.*\})/s', $code, $m)) {
			$body = $m[1];
		} elseif (preg_match('/(fn\s*\([^)]*\)\s*=>\s*.+)/', $code, $m)) {
			$body = 'function() { return ' . trim(rtrim(trim($m[1]), ';')) . '; }';
		}

		return serialize([
			'code' => $body,
			'vars' => $ref->getStaticVariables(),
		]);
	}

	/**
	 * 反序列化闭包
	 */
	private function unserializeClosure(string $data): ?\Closure
	{
		try {
			$info = unserialize($data);
			extract($info['vars']);
			return eval('return ' . $info['code'] . ';');
		} catch (\Throwable $e) {
			Logger::error("[TaskManager] 闭包反序列化失败: {$e->getMessage()}");
			return null;
		}
	}

	/**
	 * 从 Redis 读取任务数据
	 */
	private function loadFromRedis(): array
	{
		try {
			if (!Cache::$api || !Cache::$api->redis) return [];

			$redis = Cache::$api->redis;
			$redis->select(Env::get('Redis.select', 0));
			$data = $redis->get($this->storageKey);

			return $data ? json_decode($data, true) : [];
		} catch (\Throwable $e) {
			return [];
		}
	}

	// ==================== 生命周期 ====================

	public function shutdown(): void
	{
		foreach ($this->tasks as $task) {
			Timer::clear($task['_timer_id']);
		}
		$this->saveToRedis();
		$this->tasks = [];
		
		// 清理管理进程标记
		try {
			if (Cache::$api && Cache::$api->redis) {
				$redis = Cache::$api->redis;
				$redis->select(Env::get('Redis.select', 0));
				if ($redis->get('task_manager:master_pid') == getmypid()) {
					$redis->del('task_manager:master_pid');
				}
			}
		} catch (\Throwable $e) {
			Logger::error("[TaskManager] 清理 master_pid 失败: {$e->getMessage()}");
		}
		
		Logger::info("[TaskManager] 已关闭");
	}

	private function __clone() {}
}