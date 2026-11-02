<?php
namespace controller;

use Utils\Env;
use Utils\Logger;
use Swoole\Timer;
use Swoole\Coroutine\Redis as CoRedis;
use Swoole\Coroutine;
use Redis;
use InvalidArgumentException;

/**
 * API容器类：统一管理API实例，支持单例缓存、协程兼容、配置化
 */
class Api {
	// 单例缓存：key=API名称，value=实例（协程环境下用协程ID隔离）
	private array $apiInstances = [];
	// 配置：支持自定义API映射、Redis参数，便于扩展
	private array $config = [
		// API名称 => 完整类名/特殊标识（如'redis'）
		'apiMap' => [
			'sql' => '\\config\\sql',
			'input' => '\\message\\input',
			'redis' => 'redis', // 特殊标识，单独处理Redis实例
			// 可扩展其他API映射，避免硬编码
		],
		// Redis配置（复用Env，支持协程开关）
		'redis' => [
			'host' => '',
			'port' => 6379,
			'timeout' => 5,
			'auth' => '',
			'select' => 0,
			'useCoroutine' => false, // 是否使用Swoole协程Redis
		],
	];


	// 1. 静态变量：存储唯一实例（关键）
	private static ?self $instance = null;

	// 2. 私有构造函数：禁止外部通过new创建实例（关键）
	private function __construct() {
		$this->initConfig();
	}

	// 3. 静态方法：全局唯一获取实例的入口（关键）
	public static function getInstance(): self {
		// 记录getInstance的调用位置
		$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
		$caller = "{$trace[0]['file']}:{$trace[0]['line']}";
		// Logger::debug("[API实例追踪] getInstance被调用，来源：{$caller}");
		// 协程安全：Swoole协程环境下，给每个协程单独分配实例（避免跨协程污染）
		if (Coroutine::getCid() > 0) {
			static $coInstances = []; // 协程级实例缓存
			$cid = Coroutine::getCid();
			if (!isset($coInstances[$cid])) {
				$coInstances[$cid] = new self();
				// Logger::debug("[API容器] 新建协程级实例：CID={$cid}");
			}
			return $coInstances[$cid];
		}

		// 非协程环境：全局单例
		if (self::$instance === null) {
			self::$instance = new self();
			// Logger::debug("[API容器] 新建全局单例实例");
		}
		return self::$instance;
	}


	/**
	 * 初始化配置：读取Env配置，适配协程环境
	 */
	private function initConfig(): void {
		// 读取Redis配置
		$this->config['redis']['host'] = Env::get('Redis.host', '127.0.0.1');
		$this->config['redis']['port'] = Env::get('Redis.port', 6379);
		$this->config['redis']['timeout'] = Env::get('Redis.timeout', 5);
		$this->config['redis']['auth'] = Env::get('Redis.auth', '');
		$this->config['redis']['select'] = Env::get('Redis.select', 0);

		// 自动检测协程环境：存在协程时默认使用协程Redis
		$this->config['redis']['useCoroutine'] = Env::get('Redis.useCoroutine', Coroutine::getCid() > 0);
	}


	/**
	 * 魔术方法：获取API实例（单例缓存，协程安全）
	 * @param string $apiName API名称（如'sql'、'redis'）
	 * @return object API实例
	 * @throws InvalidArgumentException|RuntimeException
	 */
	public function __get(string $apiName): object {
		$apiName = (trim($apiName));
		if (empty($apiName)) {
			throw new InvalidArgumentException('API名称不能为空', 400);
		}

		// 1. 获取实例缓存Key（协程环境下用「协程ID+API名」隔离，避免串用）
		$cacheKey = $this->getCacheKey($apiName);

		// 2. 缓存命中：直接返回实例
		if (isset($this->apiInstances[$cacheKey])) {
			return $this->apiInstances[$cacheKey];
		}

		// 3. 缓存未命中：创建实例
		$instance = $this->createInstance($apiName);
		if (!$instance) {
			throw new \RuntimeException("创建API实例失败：{$apiName}", 500);
		}

		// 4. 存入缓存并返回
		$this->apiInstances[$cacheKey] = $instance;
		Logger::success("[API容器] 初始化API实例：{$apiName}（缓存Key：{$cacheKey}）");
		return $instance;
	}


	/**
	 * 魔术方法：手动设置API实例（支持自定义实例注入）
	 * @param string $apiName API名称
	 * @param object $instance API实例
	 */
	public function __set(string $apiName, object $instance): void {
		$apiName = (trim($apiName));
		$cacheKey = $this->getCacheKey($apiName);
		$this->apiInstances[$cacheKey] = $instance;
		Logger::success("[API容器] 手动注入API实例：{$apiName}");
	}


	/**
	 * 创建API实例：根据API名称适配不同实例化逻辑
	 * @param string $apiName API名称
	 * @return object|null 实例（失败返回null）
	 */
	private function createInstance(string $apiName): ?object {
		// 优先使用配置中的API映射
		$className = $this->config['apiMap'][strtolower($apiName)] ?? "\\Api\\{$apiName}";

		// 特殊处理Redis实例（支持普通/协程两种模式）
		if (strtolower($className) === 'redis') {
			return $this->createRedisInstance();
		}

		// 常规类实例化：检查类是否存在，支持构造函数参数（扩展预留）
		if (!class_exists($className)) {
			Logger::error("[API容器] 类不存在：{$className}（API名称：{$apiName}）");
			return null;
		}

		// 支持带参数的构造函数（扩展场景：如传入配置）
		try {
			return new $className();
		} catch (\Exception $e) {
			Logger::error("[API容器] 实例化失败：{$className}，原因：{$e->getMessage()}");
			return null;
		}
	}


	/**
	 * 创建Redis实例：自动适配普通/协程模式
	 * @return Redis|CoRedis|null Redis实例
	 */
	private function createRedisInstance(): Redis|CoRedis|null {
		$redisConf = $this->config['redis'];
		$redis = null;

		try {
			// 协程模式：使用Swoole协程Redis
			if ($redisConf['useCoroutine']) {
				$redis = new CoRedis();
				$connectResult = $redis->connect($redisConf['host'], $redisConf['port'], $redisConf['timeout']);
			}
			// 普通模式：使用原生Redis
			else {
				$redis = new Redis();
				$connectResult = $redis->pconnect($redisConf['host'], $redisConf['port'], $redisConf['timeout']);
			}

			// 连接失败处理
			if (!$connectResult) {
				Logger::error("[API容器] Redis连接失败：{$redisConf['host']}:{$redisConf['port']}");
				return null;
			}

			// 认证处理（有密码时执行）
			if (!empty($redisConf['auth']) && !$redis->auth($redisConf['auth'])) {
				Logger::error("[API容器] Redis认证失败");
				return null;
			}

			// 切换数据库
			if ($redisConf['select'] > 0 && !$redis->select($redisConf['select'])) {
				Logger::error("[API容器] Redis切换数据库失败：{$redisConf['select']}");
				return null;
			}

			return $redis;
		} catch (\Exception $e) {
			Logger::error("[API容器] Redis实例化失败：{$e->getMessage()}");
			return null;
		}
	}


	/**
	 * 获取缓存Key：协程环境下添加协程ID，避免实例串用
	 * @param string $apiName API名称
	 * @return string 缓存Key
	 */
	private function getCacheKey(string $apiName): string {
		return (trim($apiName));
		// 协程环境：用「cid:apiName」作为Key
		if (Coroutine::getCid() > 0) {
			return sprintf('cid_%d_%s', Coroutine::getCid(), $apiName);
		}
		// 非协程环境：直接用API名称
		return $apiName;
	}


	/**
	 * 定时任务：基于Swoole Timer（优化参数传递）
	 * @param int $interval 执行间隔（毫秒）
	 * @param callable $callback 回调函数
	 * @param mixed ...$params 回调参数
	 * @return int Timer ID（便于后续清除）
	 */
	public function tick(int $interval, callable $callback, ...$params): int {
		if ($interval < 10) {
			Logger::warning("[API容器] 定时任务间隔过短（{$interval}ms），已调整为10ms");
			$interval = 10;
		}

		$timerId = Timer::tick($interval, function (...$args) use ($callback) {
			try {
				call_user_func_array($callback, $args);
			} catch (\Exception $e) {
				Logger::error("[定时任务异常] 回调执行失败：{$e->getMessage()}");
			}
		}, ...$params);

		Logger::success("[API容器] 创建定时任务：ID={$timerId}，间隔={$interval}ms");
		return $timerId;
	}


	/**
	 * 清除API实例缓存（支持单个/全部清除）
	 * @param string|null $apiName API名称（null=清除全部）
	 */
	public function clear(?string $apiName = null): void {
		if ($apiName === null) {
			// 清除全部实例
			$count = count($this->apiInstances);
			$this->apiInstances = [];
			Logger::success("[API容器] 清除全部API实例缓存，共{$count}个");
			return;
		}

		// 清除单个实例
		$apiName = (trim($apiName));
		$cacheKey = $this->getCacheKey($apiName);
		if (isset($this->apiInstances[$cacheKey])) {
			unset($this->apiInstances[$cacheKey]);
			Logger::success("[API容器] 清除API实例缓存：{$apiName}（Key：{$cacheKey}）");
		}
	}


	/**
	 * 检查API实例是否存在
	 * @param string $apiName API名称
	 * @return bool 是否存在
	 */
	public function has(string $apiName): bool {
		$apiName = (trim($apiName));
		$cacheKey = $this->getCacheKey($apiName);
		return isset($this->apiInstances[$cacheKey]);
	}
	// 4. 禁止克隆：避免通过clone创建新实例（关键）
	private function __clone() {}

}
