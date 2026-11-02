<?php
namespace Protocol\Core;

/**
 * 协议配置读取器。
 *
 * Config.ini 使用「带点号的小节名」，例如：
 *   [protocol]
 *   default = http
 *   [protocol.onebot]
 *   forward.enable = false
 *
 * PHP 的 parse_ini_file($file, true) 会把整段名（protocol.onebot）当作**顶层键**，
 * 而 Utils\Env::get('protocol.onebot.forward.enable') 会先按点号拆成
 * ['protocol','onebot','forward','enable']，因此永远取不到 —— 这是 Env 的固有限制。
 *
 * 本类独立解析 Config.ini（只解析一次并缓存），采用「最长小节名匹配」：
 *   1. 先按 protocol.onebot.forward.enable / protocol.onebot.forward / protocol.onebot / protocol 逐个尝试小节名；
 *   2. 命中小节后，把剩余段在小节内部按「点号键」逐级查找（兼容扁平键与嵌套数组两种形态）。
 */
class Config {
	/** 全部小节缓存（parse_ini_file 原始结果） */
	private static ?array $sections = null;

	private static function sections(): array {
		if (self::$sections !== null) {
			return self::$sections;
		}
		$path = (defined('__root__') ? __root__ : dirname(__DIR__, 2)) . '/Config.ini';
		$parsed = is_readable($path) ? parse_ini_file($path, true, INI_SCANNER_NORMAL) : false;
		self::$sections = is_array($parsed) ? $parsed : [];
		return self::$sections;
	}

	/**
	 * @param string $key 例如 onebot.forward.enable / qqbot.api
	 * @param mixed $default 默认值
	 */
	public static function get(string $key, mixed $default = null): mixed {
		// 允许带/不带 protocol. 前缀：protocol.qqbot.api 与 qqbot.api 等价
		$key = trim($key);
		if (str_starts_with($key, 'protocol.')) {
			$key = substr($key, strlen('protocol.'));
		}
		if ($key === '') {
			return $default;
		}
		$segments = explode('.', $key);
		$total = count($segments);
		$sections = self::sections();

		// 从最长的小节名开始尝试（protocol.qqbot.api → protocol.qqbot → protocol）
		for ($i = $total; $i >= 0; $i--) {
			$sectionName = $i === 0 ? 'protocol' : 'protocol.' . implode('.', array_slice($segments, 0, $i));
			if (!array_key_exists($sectionName, $sections)) {
				continue;
			}
			$section = $sections[$sectionName];
			$tail = array_slice($segments, $i);
			$value = self::navigate($section, $tail);
			if ($value !== null) {
				return $value;
			}
		}

		// 兜底：整段作为扁平键直接查（如 Config.ini 里写了 [protocol] 且 key 使用点号）
		$flat = 'protocol.' . $key;
		foreach ($sections as $name => $section) {
			if (is_array($section) && array_key_exists($flat, $section)) {
				return self::normalize($section[$flat], $default);
			}
		}

		return $default;
	}

	/**
	 * 在 {} 小节内按剩余段查找：先尝试把剩余段整体作为扁平键，再逐级下钻。
	 * @param mixed $section 小节内容
	 * @param string[] $tail 剩余键段
	 */
	private static function navigate(mixed $section, array $tail): mixed {
		if ($tail === []) {
			return is_scalar($section) && $section !== '' ? $section : null;
		}
		if (!is_array($section)) {
			return null;
		}

		// 1. 扁平键（INI 中未分层，如 forward.url）
		$flatKey = implode('.', $tail);
		if (array_key_exists($flatKey, $section)) {
			return self::normalize($section[$flatKey], null);
		}

		// 2. 逐级下钻（键可能已被 Env 之类提前分层，或 INI 里写成 section 内嵌）
		$value = $section;
		foreach ($tail as $segment) {
			if (!is_array($value) || !array_key_exists($segment, $value)) {
				return null;
			}
			$value = $value[$segment];
		}
		return self::normalize($value, null);
	}

	/** 空字符串 / null 视为未配置 */
	private static function normalize(mixed $value, mixed $default): mixed {
		if ($value === null || $value === '') {
			return $default;
		}
		return $value;
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

	/** 该协议是否启用 */
	public static function enabled(string $backend): bool {
		return self::bool($backend . '.enable', false);
	}

	/** 默认后端名称（http / onebot / milky / qqbot） */
	public static function defaultBackend(): string {
		return strtolower(self::string('default', 'http'));
	}
}
