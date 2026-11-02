<?php
namespace Utils;

use Core\Config as KernelConfig;
use InvalidArgumentException;

/**
 * 配置读取的兼容外壳。
 *
 * 保留 `Utils\Env` 这个类名，是因为它在 20+ 处业务代码里被直接调用；
 * 但内部实现已完全委托给 `Core\Config`，不再自己 `parse_ini_file`。
 *
 * 这样做的收益：全项目只有一份配置树，`[protocol.qqbot]` 这类带点号的小节名
 * 只有一种解析方式，「同一个键在不同地方读到不同值」的历史问题从根上消失。
 */
class Env {
	/**
	 * 获取配置值
	 * @param string $key 配置键（如 Redis.host / protocol.qqbot.api）
	 * @param mixed $default 默认值
	 */
	public static function get(string $key, mixed $default = null): mixed {
		$key = trim($key);
		if ($key === '') {
			throw new InvalidArgumentException('配置键不能为空', 400);
		}
		return KernelConfig::get($key, $default);
	}

	/** 强制重新解析配置文件 */
	public static function refreshCache(): bool {
		KernelConfig::refresh();
		Logger::info('[Env] 配置缓存已刷新（委托 Core\Config）');
		return true;
	}

	/**
	 * 获取全部配置（已脱敏的树，避免把 token 写进日志 / 响应）
	 */
	public static function getAll(): array {
		return KernelConfig::all(true);
	}

	/**
	 * @deprecated 请直接使用 get()
	 */
	public static function Config(string $key, mixed $default = null): mixed {
		return self::get($key, $default);
	}

	/** 配置类型化读取（委托内核，供新代码使用） */
	public static function bool(string $key, bool $default = false): bool {
		return KernelConfig::bool($key, $default);
	}

	public static function int(string $key, int $default = 0): int {
		return KernelConfig::int($key, $default);
	}

	public static function string(string $key, string $default = ''): string {
		return KernelConfig::string($key, $default);
	}

	public static function array(string $key, array $default = []): array {
		return KernelConfig::array($key, $default);
	}
}
