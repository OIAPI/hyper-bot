<?php
namespace Utils;

use Controller\Server\RequestHandler;
use Controller\Api;
use Swoole\Http\Server as SwooleHttpServer;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * 缓存工具类：区分静态核心属性与动态缓存，支持过期、过滤、并发安全
 */
class Cache {
	// ---------------------- 静态核心属性（固定业务依赖，不参与动态缓存）----------------------
	// 注：仅存放全局核心实例，避免随意扩展
	public static ?object $param = null;	   // 请求参数对象
	public static ?RequestHandler $server = null;	  // 业务Server实例
	public static ?SwooleHttpServer $http = null; // Swoole HTTP Server实例
	public static ?Api $api = null;			// API容器实例
	public static ?array $replys = null;	   // 回复内容列表（统一小写命名）

	// ---------------------- 动态缓存区（支持过期、过滤）----------------------
	private static array $dynamicCache = [];   // 动态缓存存储：key=缓存名，value=[value, expireTime]
	private static array $reservedKeys = ['param', 'server', 'http', 'api', 'replys']; // 静态属性预留键名


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

		// 2. 优先检查静态核心属性
		if (in_array($name, self::$reservedKeys, true)) {
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

		// 1. 处理静态核心属性（严格类型校验，避免赋值错误）
		if (in_array($name, self::$reservedKeys, true)) {
			self::setReservedProperty($name, $value);
			return;
		}

		// 2. 处理动态缓存（支持过期时间）
		$expireTime = $expire > 0 ? (time() + $expire) : 0;
		self::$dynamicCache[$name] = [$value, $expireTime];
		Logger::success("[Cache] 设置动态缓存：{$name}，过期时间：" . ($expire > 0 ? "{$expire}秒" : "永久"));
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
		// 类型映射：静态属性名 => 允许的类型
		$typeMap = [
			'param' => 'object',
			'server' => Server::class,
			'http' => SwooleHttpServer::class,
			'api' => Api::class,
			'replys' => 'array',
		];

		$expectedType = $typeMap[$name];
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

		self::$$name = $value;
		Logger::success("[Cache] 设置静态属性：{$name}（类型：{$expectedType}）");
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
