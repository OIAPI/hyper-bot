<?php
namespace Controller;

use Core\App;
use Core\Container;
use Core\Store\Store;
use Core\Store\StoreBridge;
use InvalidArgumentException;

/**
 * API 容器（v2）：插件访问外部能力的统一入口。
 *
 * 插件里写的是 `$this->api->message->sendMsg(...)`，
 * 其中 `$this->api` 就是本类，`->message` 走 __get 自动装配 `Api\message`。
 *
 * v1 的三个问题（本次全部修掉）：
 *   1. **实例泄漏**：`static $coInstances[Coroutine::getCid()]` 只增不减，
 *      协程结束后 key 永远留在数组里 —— 常驻进程跑几天就是内存缓慢上涨。
 *   2. **缓存键是死代码**：`getCacheKey()` 第一行 `return trim($apiName)`，
 *      下面那段「协程隔离」永远不执行，注释却在宣称协程安全。
 *   3. **硬依赖 ext-redis**：`$this->api->redis` 必须返回 `\Redis` 实例，
 *      没装扩展时插件构造直接 fatal，而内核早就有了 Store 抽象。
 *
 * v2 做法：
 *   - 不再自己管理实例字典，直接委托 `Core\Container`（内核唯一的 DI 容器）；
 *   - `redis` 返回 `Core\Store\StoreBridge`（长得像 Redis，实现是 Store）——
 *     装了 Redis 走 Redis，没装自动落文件，**插件代码一行不用改**；
 *   - 容器单例本身无状态，不需要按协程隔离（状态都在 Context 里）。
 */
class Api {
	/** 特殊标识：不是类名，而是 Store 门面 */
	private const REDIS_ALIAS = 'redis';

	private static ?self $instance = null;

	private function __construct() {}

	public static function getInstance(): self {
		$instance = self::$instance ??= new self();
		// v1 插件通过 cache::$api 取容器（Utils\Markdown 就这么写）。
		// 这个静态槽以前只在 swoole 的 HttpServer 里赋值，php 架构 / 队列 / cron
		// 下永远是 null，于是 Markdown 一类老组件直接 TypeError。
		if (\Utils\Cache::$api === null) {
			\Utils\Cache::$api = $instance;
		}
		return $instance;
	}

	/**
	 * 取一个 API 实例（委托内核容器）
	 * @param string $apiName 如 message / groupSetup / redis
	 */
	public function __get(string $apiName): object {
		$apiName = trim($apiName);
		if ($apiName === '') {
			throw new InvalidArgumentException('API名称不能为空', 400);
		}
		return $this->resolve($apiName);
	}

	/** 手动注入实例（保留 v1 能力，测试/替换实现用） */
	public function __set(string $apiName, object $instance): void {
		Container::instance()->bind('api.' . trim($apiName), $instance);
	}

	public function has(string $apiName): bool {
		return Container::instance()->has('api.' . trim($apiName));
	}

	/** 清除实例缓存（v1 签名兼容；容器层面就是重置绑定） */
	public function clear(?string $apiName = null): void {
		if ($apiName === null) {
			Container::instance()->forgetPrefix('api.');
			return;
		}
		Container::instance()->forget('api.' . trim($apiName));
	}

	private function resolve(string $apiName): object {
		$key = 'api.' . $apiName;
		$container = Container::instance();
		if ($container->has($key)) {
			return $container->get($key);
		}

		// 1. Store 门面（替代 v1 的 \Redis）
		if (strtolower($apiName) === self::REDIS_ALIAS) {
			$bridge = new StoreBridge($container->get(Store::class));
			$container->bind($key, $bridge);
			return $bridge;
		}

		// 2. 同名类（Api\message 等）
		$class = 'Api\\' . $apiName;
		if (class_exists($class)) {
			$instance = new $class();
			$container->bind($key, $instance);
			return $instance;
		}

		throw new \RuntimeException("API 实例不存在：{$apiName}（已尝试 {$class}）", 500);
	}

	private function __clone() {}
}