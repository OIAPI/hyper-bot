<?php
namespace Utils;

use Controller\Server\RequestHandler;
use Controller\Api;
// 服务端为 WebSocket\Server（继承自 Http\Server），WS 推送与 HTTP 请求共用同一实例
use Swoole\WebSocket\Server as SwooleHttpServer;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * 缓存工具类：区分静态核心属性与动态缓存，支持过期、过滤、并发安全
 */
class Cache {
	// ---------------------- 静态核心属性（固定业务依赖，不参与动态缓存）----------------------
	// 注：仅存放全局核心实例，避免随意扩展
	public static ?object $param = null;	   // 请求参数对象
	// 常驻事件（WS/Gateway）没有 HTTP 响应对象，此时为 Protocol\Core\NullResponder
	public static ?object $server = null;	  // 业务Server实例（RequestHandler 或 NullResponder）
	public static ?SwooleHttpServer $http = null; // Swoole HTTP Server实例
	public static ?Api $api = null;			// API容器实例
	public static ?array $replys = null;	   // 回复内容列表（统一小写命名）
	public static ?object $adapter = null;	  // 当前事件来源的协议适配器（OneBot/Milky/QqBot）

	// ---------------------- 动态缓存区（支持过期、过滤）----------------------
	private static array $dynamicCache = [];   // 动态缓存存储：key=缓存名，value=[value, expireTime]
	/** 协程私有数据在上下文容器中的键名（与静态属性名区分，避免冲突） */
	private const LOCAL_KEY = '__local';
	/** 非协程环境的「协程私有」数据兜底存储 */
	private static array $localFallback = [];
	private static array $reservedKeys = ['param', 'server', 'http', 'api', 'replys', 'adapter']; // 静态属性预留键名

	/**
	 * 获取协程级上下文容器（Swoole 协程内独立，非协程返回 null）
	 * 说明：持久连接（WS）会并发处理多个事件，必须按协程隔离上下文，避免串号。
	 */
	private static function ctx(): ?\ArrayObject {
		if (\extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
			return \Swoole\Coroutine::getContext();
		}
		return null;
	}


	/**
	 * 获取缓存/静态属性值
	 * @param string $name 缓存名/静态属性名（前缀"?"表示仅判断是否存在）
	 * @param mixed $default 默认值（缓存不存在时返回）
	 * @param string|array $filter 过滤函数（多个用逗号分隔或传数组）
	 * @return mixed 结果（判断存在时返回bool，否则返回处理后的值）
	 * @throws InvalidArgumentException 非法参数时抛出
	 */
	public static function get(string $name, mixed $default = null, string|array $filter = []): mixed {
		// 1. 处理「存在性判断」逻辑（前缀"?"）
		$checkExists = false;
		if (str_starts_with($name, '?')) {
			$name = substr($name, 1);
			$checkExists = true;
		}
		$name = trim($name);
		if (empty($name)) {
			throw new InvalidArgumentException('缓存名称不能为空', 400);
		}

		// 2. 优先检查静态核心属性（协程内优先读取协程上下文，避免并发事件串号）
		if (in_array($name, self::$reservedKeys, true)) {
			$ctx = self::ctx();
			if ($ctx !== null && $ctx->offsetExists($name)) {
				return $checkExists ? true : $ctx[$name];
			}
			return $checkExists ? isset(self::$$name) : (self::$$name ?? $default);
		}

		// 3. 检查动态缓存（处理过期）
		if (!isset(self::$dynamicCache[$name])) {
			return $checkExists ? false : $default;
		}
		[$value, $expireTime] = self::$dynamicCache[$name];
		// 过期缓存自动清理
		if ($expireTime > 0 && time() > $expireTime) {
			self::del($name);
			return $checkExists ? false : $default;
		}

		// 4. 存在性判断直接返回结果
		if ($checkExists) {
			return true;
		}

		// 5. 应用过滤函数
		$value = $value ?? $default;
		$filter = is_string($filter) ? array_filter(explode(',', $filter)) : (array)$filter;
		foreach ($filter as $func) {
			$func = trim($func);
			if (empty($func) || !function_exists($func)) {
				throw new InvalidArgumentException("过滤函数不存在：{$func}", 400);
			}
			$value = $func($value); // 单个值直接处理（原array_map冗余，优化性能）
		}

		return $value;
	}


	/**
	 * 设置缓存/静态属性值
	 * @param string $name 缓存名/静态属性名
	 * @param mixed $value 值
	 * @param int $expire 动态缓存过期时间（秒，0=永久，仅对动态缓存有效）
	 * @throws InvalidArgumentException 静态属性赋值类型不匹配时抛出
	 */
	public static function set(string $name, mixed $value, int $expire = 0): void {
		$name = trim($name);
		if (empty($name)) {
			throw new InvalidArgumentException('缓存名称不能为空', 400);
		}

		// 1. 处理静态核心属性：协程内写入协程上下文，避免并发事件串号
		if (in_array($name, self::$reservedKeys, true)) {
			$ctx = self::ctx();
			if ($ctx !== null) {
				self::validateReservedProperty($name, $value);
				$ctx[$name] = $value;
				return;
			}
			self::setReservedProperty($name, $value);
			return;
		}

		// 2. 处理动态缓存（支持过期时间）
		$expireTime = $expire > 0 ? (time() + $expire) : 0;
		self::$dynamicCache[$name] = [$value, $expireTime];
		Logger::success("[Cache] 设置动态缓存：{$name}，过期时间：" . ($expire > 0 ? "{$expire}秒" : "永久"));
	}


	/**
	 * 快照当前协程的静态核心属性
	 *
	 * 背景：Swoole 的 Coroutine::create() 创建的子协程**不会继承**父协程上下文，
	 * 而 RequestHandler / EventDispatcher 都是在请求协程里读取参数、再开子协程处理业务。
	 * 因此需要在创建子协程前 snapshot()，在子协程开头 restore()。
	 *
	 * @return array 属性名 => 值
	 */
	public static function snapshot(): array {
		$snapshot = [];
		$ctx = self::ctx();
		foreach (self::$reservedKeys as $key) {
			$value = $ctx !== null && $ctx->offsetExists($key) ? $ctx[$key] : (self::$$key ?? null);
			if ($value !== null) {
				$snapshot[$key] = $value;
			}
		}
		// 协程私有数据（raw_body / headers）也要一起带过去，
		// 否则 Webhook 验签在子协程里会读到空值。
		$snapshot[self::LOCAL_KEY] = $ctx !== null
			? ($ctx[self::LOCAL_KEY] ?? [])
			: self::$localFallback;
		return $snapshot;
	}

	/**
	 * 把快照写回当前协程（子协程开头调用）
	 * @param array $snapshot snapshot() 的返回值
	 */
	public static function restore(array $snapshot): void {
		foreach ($snapshot as $key => $value) {
			if ($key === self::LOCAL_KEY) {
				foreach ((array)$value as $localKey => $localValue) {
					self::setLocal((string)$localKey, $localValue);
				}
				continue;
			}
			if (in_array($key, self::$reservedKeys, true)) {
				self::set($key, $value);
			}
		}
	}

	/**
	 * 写入「协程私有」数据（不参与全局共享）
	 *
	 * 用途：HTTP 请求级数据（原始 body、请求头）。这些值每个请求各不相同，
	 * 若放入全局动态缓存，两个并发请求会互相覆盖（例如 QQ 官方 Webhook 验签会用错 body）。
	 * 非协程环境（CLI 单测等）退化为静态数组。
	 *
	 * @param string $name 键名
	 * @param mixed $value 值
	 */
	public static function setLocal(string $name, mixed $value): void {
		$ctx = self::ctx();
		if ($ctx !== null) {
			$local = $ctx[self::LOCAL_KEY] ?? [];
			$local[$name] = $value;
			$ctx[self::LOCAL_KEY] = $local;
			return;
		}
		self::$localFallback[$name] = $value;
	}

	/**
	 * 读取「协程私有」数据
	 * @param string $name 键名
	 * @param mixed $default 默认值
	 */
	public static function getLocal(string $name, mixed $default = null): mixed {
		$ctx = self::ctx();
		if ($ctx !== null) {
			$local = $ctx[self::LOCAL_KEY] ?? [];
			return array_key_exists($name, $local) ? $local[$name] : $default;
		}
		return array_key_exists($name, self::$localFallback) ? self::$localFallback[$name] : $default;
	}

	/**
	 * 删除缓存/重置静态属性
	 * @param string ...$names 缓存名/静态属性名（传"data"删除全部动态缓存）
	 */
	public static function del(string ...$names): void {
		foreach ($names as $name) {
			$name = trim($name);
			if (empty($name)) {
				continue;
			}

			// 特殊处理：删除全部动态缓存
			if ($name === 'data') {
				$count = count(self::$dynamicCache);
				self::$dynamicCache = [];
				Logger::success("[Cache] 清空全部动态缓存，共{$count}个键");
				continue;
			}

			// 删除静态属性（重置为null）
			if (in_array($name, self::$reservedKeys, true)) {
				self::$$name = null;
				Logger::success("[Cache] 重置静态属性：{$name}");
				continue;
			}

			// 删除单个动态缓存
			if (isset(self::$dynamicCache[$name])) {
				unset(self::$dynamicCache[$name]);
				Logger::success("[Cache] 删除动态缓存：{$name}");
			}
		}
	}


	/**
	 * 批量获取动态缓存
	 * @param array $names 缓存名列表
	 * @param mixed $default 默认值
	 * @return array 键值对结果
	 */
	public static function getBatch(array $names, mixed $default = null): array {
		$result = [];
		foreach ($names as $name) {
			$result[$name] = self::get($name, $default);
		}
		return $result;
	}


	/**
	 * 批量设置动态缓存
	 * @param array $data 键值对数组（key=缓存名，value=值）
	 * @param int $expire 过期时间（秒，0=永久）
	 */
	public static function setBatch(array $data, int $expire = 0): void {
		foreach ($data as $name => $value) {
			self::set($name, $value, $expire);
		}
	}


	/**
	 * 静态属性严格赋值（避免类型错误）
	 * @param string $name 静态属性名
	 * @param mixed $value 待赋值
	 * @throws InvalidArgumentException 类型不匹配时抛出
	 */
	private static function setReservedProperty(string $name, mixed $value): void {
		self::validateReservedProperty($name, $value);
		self::$$name = $value;
		Logger::success("[Cache] 设置静态属性：{$name}");
	}

	/**
	 * 校验静态核心属性的类型
	 * @throws InvalidArgumentException 类型不匹配时抛出
	 */
	private static function validateReservedProperty(string $name, mixed $value): void {
		// 类型映射：静态属性名 => 允许的类型
		$typeMap = [
			'param' => 'object',
			'server' => 'object', // RequestHandler / NullResponder 均是对象
			'http' => SwooleHttpServer::class,
			'api' => Api::class,
			'replys' => 'array',
			'adapter' => 'object',
		];

		$expectedType = $typeMap[$name];
		// 适配器 / 响应器允许为 null（常驻事件结束后需要清空）
		if (in_array($name, ['adapter', 'server'], true) && $value === null) {
			return;
		}
		// 检查类型（类实例用instanceof，基础类型用gettype）
		$isValid = match (true) {
			class_exists($expectedType) => $value instanceof $expectedType,
			default => gettype($value) === $expectedType,
		};

		if (!$isValid) {
			$actualType = is_object($value) ? get_class($value) : gettype($value);
			throw new InvalidArgumentException(
				"静态属性【{$name}】赋值类型错误：期望{$expectedType}，实际{$actualType}",
				400
			);
		}
	}


	// /**
	 // * 魔术方法：禁止直接访问动态缓存（引导使用get/set方法，避免混淆）
	 // * @param string $name 属性名
	 // * @return mixed 仅返回静态属性，动态缓存抛异常
	 // * @throws UnexpectedValueException 访问动态缓存时抛出
	 // */
	// public function __get(string $name): mixed {
		// if (in_array($name, self::$reservedKeys, true)) {
			// return self::$$name;
		// }

		// throw new UnexpectedValueException(
			// "禁止直接访问动态缓存【{$name}】，请使用 Cache::get('{$name}') 方法",
			// 403
		// );
	// }


	// /**
	 // * 魔术方法：禁止直接赋值动态缓存（引导使用set方法）
	 // * @param string $name 属性名
	 // * @param mixed $value 值
	 // * @throws UnexpectedValueException 赋值动态缓存时抛出
	 // */
	// public function __set(string $name, mixed $value): void {
		// if (in_array($name, self::$reservedKeys, true)) {
			// self::setReservedProperty($name, $value);
			// return;
		// }

		// throw new UnexpectedValueException(
			// "禁止直接赋值动态缓存【{$name}】，请使用 Cache::set('{$name}', \$value) 方法",
			// 403
		// );
	// }


	/**
	 * 魔术方法：禁止静态调用动态缓存（避免语义混淆）
	 * @param string $method 方法名
	 * @param array $args 参数
	 * @throws UnexpectedValueException 调用动态缓存时抛出
	 */
	public static function __callStatic(string $method, array $args): mixed {
		throw new UnexpectedValueException(
			"禁止通过静态方法调用缓存【{$method}】，请使用 Cache::get('{$method}') 方法",
			403
		);
	}
}
