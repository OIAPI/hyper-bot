<?php
namespace Protocol\Core;

use Utils\Cache;
use Utils\Logger;

/**
 * API 转发桥：所有 Api\* 类的 HTTP 调用最终都经过这里，
 * 由当前事件上下文对应的协议适配器去执行，未命中时回落到 HTTP 协议端。
 */
class Bridge {
	private static ?HttpAdapter $fallback = null;

	public static function call(string $action, array $params = [], string $method = 'POST'): object {
		$adapter = Context::current();
		if ($adapter !== null && $adapter->handles($action)) {
			try {
				return $adapter->call($action, $params, $method);
			} catch (\Throwable $e) {
				Logger::error("[协议桥] 适配器 {$adapter->name()} 调用 {$action} 失败：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
			}
		}

		$fallback = self::fallback();
		try {
			return $fallback->call($action, $params, $method);
		} catch (\Throwable $e) {
			Logger::error("[协议桥] 默认 HTTP 协议端调用 {$action} 失败：{$e->getMessage()}");
			return (object)['status' => 'failed', 'retcode' => -1, 'data' => null, 'message' => $e->getMessage()];
		}
	}

	private static function fallback(): HttpAdapter {
		if (self::$fallback === null) {
			self::$fallback = new HttpAdapter();
		}
		return self::$fallback;
	}

	/** 当前是否绑定了非 HTTP 适配器 */
	public static function hasAdapter(): bool {
		return Cache::$adapter !== null && Cache::$adapter->name() !== 'http';
	}
}
