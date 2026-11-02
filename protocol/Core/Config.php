<?php
namespace Protocol\Core;

use Core\Config as KernelConfig;

/**
 * 协议层配置读取器（兼容外壳）。
 *
 * 历史上这是**第二套** INI 解析器，专门为了绕开 `Utils\Env` 无法处理
 * 「带点号小节名」的限制。现在那个限制已经在内核层解决，因此本类只做委托。
 *
 * 保留 `Protocol\Core\Config` 这个类名，是为了不动协议层的既有调用点。
 */
class Config {
	public static function get(string $key, mixed $default = null): mixed {
		// 历史调用点会带 protocol. 前缀，内核两种写法都接受
		return KernelConfig::get($key, $default);
	}

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

	/** 该协议是否启用 */
	public static function enabled(string $backend): bool {
		return self::bool($backend . '.enable', false);
	}

	/** 默认后端名称（http / onebot / milky / qqbot） */
	public static function defaultBackend(): string {
		return strtolower(self::string('default', 'http'));
	}
}
