<?php
namespace Protocol\Core;

use Utils\Env;

/**
 * 协议配置读取器：统一从 Config.ini 的 [protocol] 段读取，支持点号多级键。
 */
class Config {
	/**
	 * @param string $key 例如 onebot.ws_forward.enable
	 * @param mixed $default 默认值
	 */
	public static function get(string $key, mixed $default = null): mixed {
		return Env::get('protocol.' . $key, $default);
	}

	public static function bool(string $key, bool $default = false): bool {
		$value = self::get($key, $default);
		if (is_bool($value)) return $value;
		if (is_string($value)) {
			$value = strtolower(trim($value));
			if (in_array($value, ['1', 'true', 'on', 'yes'], true)) return true;
			if (in_array($value, ['0', 'false', 'off', 'no', ''], true)) return false;
		}
		return (bool)$value;
	}

	public static function int(string $key, int $default = 0): int {
		$value = self::get($key, $default);
		return is_numeric($value) ? (int)$value : $default;
	}

	public static function string(string $key, string $default = ''): string {
		$value = self::get($key, $default);
		return is_scalar($value) ? (string)$value : $default;
	}

	public static function array(string $key, array $default = []): array {
		$value = self::get($key, $default);
		return is_array($value) ? $value : $default;
	}

	/** 默认后端名称（http / onebot / milky / qqbot） */
	public static function defaultBackend(): string {
		return strtolower(self::string('default', 'http'));
	}
}
