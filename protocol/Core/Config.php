<?php
namespace Protocol\Core;

use Core\Bot\AccountContext;
use Core\Config as KernelConfig;

/**
 * 协议层配置读取器（兼容外壳）。
 *
 * 历史上这是**第二套** INI 解析器，专门为了绕开 Utils\Env 无法处理
 * 「带点号小节名」的限制。现在那个限制已经在内核层解决，因此本类只做委托。
 *
 * 多账号：读取前先问 AccountContext。事件处理期间它持有当前账号，于是
 * milky.api / qqbot.token / onebot.self_id 这些键会解析成「这个账号的」值；
 * 没有账号上下文时（启动、面板、定时任务）行为与单账号完全一致。
 */
class Config {
	private static function pick(string $key): array {
		return AccountContext::get($key);
	}

	public static function get(string $key, mixed $default = null): mixed {
		[$hit, $value] = self::pick($key);
		if ($hit) {
			return $value;
		}
		// 历史调用点会带 protocol. 前缀，内核两种写法都接受
		return KernelConfig::get($key, $default);
	}

	public static function bool(string $key, bool $default = false): bool {
		[$hit, $value] = self::pick($key);
		if ($hit) {
			return (bool)$value;
		}
		return KernelConfig::bool($key, $default);
	}

	public static function int(string $key, int $default = 0): int {
		[$hit, $value] = self::pick($key);
		if ($hit) {
			return (int)$value;
		}
		return KernelConfig::int($key, $default);
	}

	public static function string(string $key, string $default = ''): string {
		[$hit, $value] = self::pick($key);
		if ($hit) {
			return (string)$value;
		}
		return KernelConfig::string($key, $default);
	}

	public static function array(string $key, array $default = []): array {
		[$hit, $value] = self::pick($key);
		if ($hit) {
			return (array)$value;
		}
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
