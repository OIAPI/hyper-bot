<?php
namespace Protocol\Core;

use Utils\Cache;

/**
 * 当前处理上下文：记录事件来源于哪个适配器，供 API 转发时选择后端。
 *
 * 注意：WS / Gateway 是常驻连接，同一进程会并发处理多条事件，
 * 因此这里通过 Cache::set() 写入「协程上下文」，而不是直接给静态属性赋值，
 * 避免 A 协程的 Milky 事件把 B 协程的 OneBot 事件 API 转发到错误的后端。
 */
class Context {
	public static function bind(Adapter $adapter): void {
		Cache::set('adapter', $adapter);
	}

	public static function current(): ?Adapter {
		$adapter = Cache::get('adapter');
		return $adapter instanceof Adapter ? $adapter : null;
	}

	public static function clear(): void {
		Cache::set('adapter', null);
	}
}
