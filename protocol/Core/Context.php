<?php
namespace Protocol\Core;

use Utils\Cache;

/**
 * 当前处理上下文：记录事件来源于哪个适配器，供 API 转发时选择后端。
 * 由于本项目 Cache::$param 本身为进程级静态变量，这里保持一致的设计。
 */
class Context {
	public static function bind(Adapter $adapter): void {
		Cache::$adapter = $adapter;
	}

	public static function current(): ?Adapter {
		return Cache::$adapter ?? null;
	}

	public static function clear(): void {
		Cache::$adapter = null;
	}
}
