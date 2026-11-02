<?php
namespace Utils;

use Core\Bot\Context;
use Core\Bot\Event;

/**
 * 上下文/缓存的兼容外壳（v2）。
 *
 * v1 的 Cache 承担了三种互不相干的职责：
 *   1. 协程私有的「事件上下文」（param / adapter / server / api / replys / http）
 *   2. 进程内动态缓存（带过期）
 *   3. 静态单例寄存（$api、$http）
 *
 * v2 把第 1 类彻底迁到 `Core\Bot\Context`（不可变对象 + 栈 + 协程隔离），
 * 本类只保留第 2、3 类，并把第 1 类改成**委托**：
 *
 *   Cache::get('param')   → Context::event() 的数组形态
 *   Cache::get('adapter') → Context::adapter()
 *
 * 这样做的收益是消除「双写不同步」——v1 里有 6 个地方手工 set 这些键，
 * 漏一个就是静默 bug（插件消息发到错误的协议端）。现在只有一个真源。
 *
 * 保留这个类名，是因为它在 40+ 处老代码里被直接调用；新代码请直接用 Context。
 */
class Cache {
	// ---------------------- 静态单例（保持 v1 语义）----------------------
	public static ?object $api = null;			// API 容器
	public static ?object $http = null;		   // Swoole HTTP Server
	public static ?array $replys = null;		  // 回复收集列表

	// ---------------------- 动态缓存（进程内、带过期）----------------------
	/** @var array<string,array{0:mixed,1:int}> */
	private static array $dynamicCache = [];
	private const LOCAL_KEY = '__local';
	private static array $localFallback = [];
	/** 仍保留的「上下文」键名（其余键走动态缓存） */
	private static array $reservedKeys = ['param', 'server', 'http', 'api', 'replys', 'adapter'];

	/**
	 * 获取缓存/上下文值
	 * @param string $name 缓存名（前缀 "?" 表示仅判断是否存在）
	 * @param mixed $default 默认值
	 * @param string|array $filter 过滤函数
	 */
	public static function get(string $name, mixed $default = null, string|array $filter = []): mixed {
		$checkExists = false;
		if (str_starts_with($name, '?')) {
			$name = substr($name, 1);
			$checkExists = true;
		}
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('缓存名称不能为空', 400);
		}

		// 1. 上下文键：委托给 Context（单一真源）
		if (in_array($name, self::$reservedKeys, true)) {
			$value = self::contextValue($name);
			if ($checkExists) {
				return $value !== null;
			}
			return self::applyFilters($value ?? $default, $filter);
		}

		// 2. 动态缓存（处理过期）
		if (!isset(self::$dynamicCache[$name])) {
			return $checkExists ? false : $default;
		}
		[$value, $expireTime] = self::$dynamicCache[$name];
		if ($expireTime > 0 && time() > $expireTime) {
			unset(self::$dynamicCache[$name]);
			return $checkExists ? false : $default;
		}
		if ($checkExists) {
			return true;
		}
		return self::applyFilters($value ?? $default, $filter);
	}

	/**
	 * 设置缓存/上下文值
	 *
	 * 注意：写入 param / adapter / server 会**整体替换**栈顶上下文对象，
	 * 因此调用方必须意识到自己在改「当前事件」。新代码请优先使用 Context::run()。
	 */
	public static function set(string $name, mixed $value, int $expire = 0): void {
		$name = trim($name);
		if ($name === '') {
			throw new \InvalidArgumentException('缓存名称不能为空', 400);
		}

		if (in_array($name, self::$reservedKeys, true)) {
			self::setContextValue($name, $value);
			return;
		}

		$expireTime = $expire > 0 ? (time() + $expire) : 0;
		self::$dynamicCache[$name] = [$value, $expireTime];
	}

	/**
	 * 上下文快照（兼容 v1 调用点；返回 Context 栈副本 + 协程私有数据）
	 * @return array
	 */
	public static function snapshot(): array {
		return ['__ctx' => Context::snapshot(), self::LOCAL_KEY => self::localAll()];
	}

	/**
	 * 恢复上下文快照（兼容 v1 调用点）
	 */
	public static function restore(array $snapshot): void {
		Context::restore((array)($snapshot['__ctx'] ?? []));
		foreach ((array)($snapshot[self::LOCAL_KEY] ?? []) as $key => $value) {
			self::setLocal((string)$key, $value);
		}
	}

	/** 协程/请求私有数据（原始 body、headers） */
	public static function setLocal(string $name, mixed $value): void {
		$local = self::localAll();
		$local[$name] = $value;
		self::writeLocal($local);
	}

	public static function getLocal(string $name, mixed $default = null): mixed {
		$local = self::localAll();
		return array_key_exists($name, $local) ? $local[$name] : $default;
	}

	/**
	 * 删除缓存/重置
	 */
	public static function del(string ...$names): void {
		foreach ($names as $name) {
			$name = trim($name);
			if ($name === '') {
				continue;
			}
			if ($name === 'data') {
				self::$dynamicCache = [];
				continue;
			}
			if (in_array($name, self::$reservedKeys, true)) {
				self::clearContextValue($name);
				continue;
			}
			unset(self::$dynamicCache[$name]);
		}
	}

	public static function getBatch(array $names, mixed $default = null): array {
		$result = [];
		foreach ($names as $name) {
			$result[$name] = self::get($name, $default);
		}
		return $result;
	}

	public static function setBatch(array $data, int $expire = 0): void {
		foreach ($data as $name => $value) {
			self::set($name, $value, $expire);
		}
	}

	// ---------------- 上下文映射 ----------------

	/** 读取某个上下文键（返回 null 表示当前无事件） */
	private static function contextValue(string $name): mixed {
		$context = Context::current();
		return match ($name) {
			'param' => $context === null ? null : (object)$context->event->toArray(),
			'adapter' => $context?->adapter,
			'server' => $context?->responder,
			'api' => self::$api,
			'http' => self::$http,
			'replys' => self::$replys,
			default => null,
		};
	}

	/** 写入某个上下文键（整体重建不可变 Context） */
	private static function setContextValue(string $name, mixed $value): void {
		$context = Context::current();

		switch ($name) {
			case 'param':
				$event = is_array($value) ? Event::fromArray($value) : Event::fromArray((array)$value);
				self::replaceContext(new Context($event, $context?->adapter, $context?->responder));
				return;

			case 'adapter':
				$base = $context?->event ?? Event::fromArray(['post_type' => '']);
				$adapter = $value instanceof \Protocol\Core\Adapter ? $value : null;
				self::replaceContext(new Context($base, $adapter, $context?->responder));
				return;

			case 'server':
				$base = $context?->event ?? Event::fromArray(['post_type' => '']);
				self::replaceContext(new Context($base, $context?->adapter, $value));
				return;

			case 'api':
				self::$api = is_object($value) ? $value : null;
				return;

			case 'http':
				self::$http = is_object($value) ? $value : null;
				return;

			case 'replys':
				self::$replys = is_array($value) ? $value : null;
				return;
		}
	}

	/** 用新上下文替换栈顶 */
	private static function replaceContext(Context $new): void {
		$stack = Context::snapshot();
		if ($stack === []) {
			Context::restore([$new]);
			return;
		}
		$stack[count($stack) - 1] = $new;
		Context::restore($stack);
	}

	private static function clearContextValue(string $name): void {
		if ($name === 'api') {
			self::$api = null;
			return;
		}
		if ($name === 'http') {
			self::$http = null;
			return;
		}
		if ($name === 'replys') {
			self::$replys = null;
			return;
		}
		$context = Context::current();
		$base = $context?->event ?? Event::fromArray(['post_type' => '']);
		self::replaceContext(new Context(
			$base,
			$name === 'adapter' ? null : $context?->adapter,
			$name === 'server' ? null : $context?->responder
		));
	}

	// ---------------- 协程私有存储 ----------------

	private static function localAll(): array {
		if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
			$ctx = \Swoole\Coroutine::getContext();
			/** @var array $local */
			$local = $ctx[self::LOCAL_KEY] ?? [];
			return $local;
		}
		return self::$localFallback;
	}

	private static function writeLocal(array $local): void {
		if (extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0) {
			$ctx = \Swoole\Coroutine::getContext();
			$ctx[self::LOCAL_KEY] = $local;
			return;
		}
		self::$localFallback = $local;
	}

	private static function applyFilters(mixed $value, string|array $filter): mixed {
		$filter = is_string($filter) ? array_filter(explode(',', $filter)) : (array)$filter;
		foreach ($filter as $func) {
			$func = trim((string)$func);
			if ($func === '' || !function_exists($func)) {
				throw new \InvalidArgumentException("过滤函数不存在：{$func}", 400);
			}
			$value = $func($value);
		}
		return $value;
	}

	/** 禁止静态调用动态缓存（避免语义混淆） */
	public static function __callStatic(string $method, array $args): mixed {
		throw new \UnexpectedValueException(
			"禁止通过静态方法调用缓存【{$method}】，请使用 Cache::get('{$method}') 方法",
			403
		);
	}
}
