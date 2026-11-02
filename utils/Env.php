<?php
namespace Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * 配置工具类：支持INI文件解析、内存缓存、多维配置读取，仅首次读取文件
 */
class Env {
	// ---------------------- 配置常量（可根据需求调整）----------------------
	private const CONFIG_PATH = __DIR__ . '/../Config.ini'; // 配置文件路径
	private const ESCAPE_CHAR = "'";						// 键名转义字符（避免点被解析为层级）
	private const SEPARATOR = '.';						  // 多维配置分隔符（如"Redis.host"）
	private const SCANNER_MODE = INI_SCANNER_NORMAL;		// INI解析模式

	// ---------------------- 内存缓存（静态变量，全局共享）----------------------
	private static ?array $configCache = null; // 配置缓存：null=未初始化，array=已缓存


	/**
	 * 获取配置值（优先读内存缓存，未初始化则读取文件并缓存）
	 * @param string $key 配置键（支持多维，如"Redis.host"）
	 * @param mixed $default 默认值（配置不存在时返回）
	 * @return mixed 配置值
	 * @throws InvalidArgumentException 键格式错误时抛出
	 */
	public static function get(string $key, mixed $default = null): mixed {
		// 1. 初始化配置缓存（仅首次调用时读取文件）
		self::initConfigCache();

		// 2. 校验配置键格式（非空）
		$key = trim($key);
		if (empty($key)) {
			throw new InvalidArgumentException('配置键不能为空', 400);
		}

		// 3. 解析多维配置键（如"Redis.host" → ['Redis', 'host']）
		$keySegments = explode(self::SEPARATOR, $key);
		$config = self::$configCache;

		// 4. 逐级获取配置值
		foreach ($keySegments as $segment) {
			// 转义键名处理（如"'Redis.host'" → "Redis.host"）
			$segment = trim($segment, self::ESCAPE_CHAR);

			// 层级不存在：返回默认值
			if (!is_array($config) || !isset($config[$segment])) {
				return $default;
			}

			$config = $config[$segment];
		}

		// 5. 返回最终配置值（空值返回默认值）
		return $config === null || $config === '' ? $default : $config;
	}


	/**
	 * 初始化配置缓存：仅首次调用时读取并解析INI文件
	 * @throws RuntimeException 文件读取/解析失败时抛出
	 */
	private static function initConfigCache(): void {
		// 已缓存：直接返回
		if (self::$configCache !== null) {
			return;
		}

		// 1. 检查配置文件是否存在
		if (!file_exists(self::CONFIG_PATH)) {
			throw new RuntimeException("配置文件不存在：" . self::CONFIG_PATH, 404);
		}

		// 2. 检查文件可读性
		if (!is_readable(self::CONFIG_PATH)) {
			throw new RuntimeException("配置文件不可读：" . self::CONFIG_PATH, 403);
		}

		// 3. 解析INI文件（支持多维配置、JSON值自动解析）
		$rawConfig = parse_ini_file(self::CONFIG_PATH, true, self::SCANNER_MODE);
		if ($rawConfig === false) {
			throw new RuntimeException("配置文件解析失败：" . self::CONFIG_PATH, 500);
		}

		// 4. 处理多维配置（点分隔键→层级数组）和JSON值
		$parsedConfig = self::processConfig($rawConfig);

		// 5. 存入内存缓存
		self::$configCache = $parsedConfig;
		Logger::debug("[Env] 配置文件加载成功，缓存配置项 " . count($parsedConfig) . " 个");
	}


	/**
	 * 处理配置：转换点分隔键为多维数组、自动解析JSON格式值
	 * @param array $rawConfig 原始INI解析结果
	 * @return array 处理后的多维配置数组
	 */
	private static function processConfig(array $rawConfig): array {
		$processed = [];

		foreach ($rawConfig as $sectionKey => $sectionValue) {
			// 处理section内部配置（支持section为数组或单个值）
			if (is_array($sectionValue)) {
				$processed[$sectionKey] = self::processSection($sectionValue);
			} else {
				// 单个值：尝试解析JSON（如"[1,2,3]" → 数组）
				$processed[$sectionKey] = self::parseJsonValue($sectionValue);
			}
		}

		return $processed;
	}


	/**
	 * 处理INI Section：将点分隔键转换为多维数组
	 * @param array $section Section原始配置
	 * @return array 处理后的Section数组
	 */
	private static function processSection(array $section): array {
		$result = [];

		foreach ($section as $key => $value) {
			// 1. 处理转义键（如"'Redis.host'" → 保留原键名，不解析为层级）
			if (str_starts_with($key, self::ESCAPE_CHAR) && str_ends_with($key, self::ESCAPE_CHAR)) {
				$realKey = trim($key, self::ESCAPE_CHAR);
				$result[$realKey] = self::parseJsonValue($value);
				continue;
			}

			// 2. 处理点分隔键（如"Redis.host" → $result['Redis']['host']）
			if (str_contains($key, self::SEPARATOR)) {
				self::setMultiDimensionValue($result, $key, self::parseJsonValue($value));
			} else {
				// 普通键：直接赋值
				$result[$key] = self::parseJsonValue($value);
			}
		}

		return $result;
	}


	/**
	 * 给多维数组设置值（支持点分隔键）
	 * @param array $array 目标数组（引用传递）
	 * @param string $key 点分隔键（如"a.b.c"）
	 * @param mixed $value 要设置的值
	 */
	private static function setMultiDimensionValue(array &$array, string $key, mixed $value): void {
		$segments = explode(self::SEPARATOR, $key);
		$current = &$array;

		// 遍历键段，创建层级数组
		foreach ($segments as $index => $segment) {
			// 最后一个段：赋值
			if ($index === count($segments) - 1) {
				$current[$segment] = $value;
				break;
			}

			// 非最后一个段：确保是数组
			if (!isset($current[$segment]) || !is_array($current[$segment])) {
				$current[$segment] = [];
			}

			$current = &$current[$segment];
		}
	}


	/**
	 * 自动解析JSON格式的值（如"{"host":"127.0.0.1"}" → 数组）
	 * @param mixed $value 原始值
	 * @return mixed 解析后的值（非JSON则返回原值）
	 */
	private static function parseJsonValue(mixed $value): mixed {
		// 仅处理字符串类型的值
		if (!is_string($value)) {
			return $value;
		}

		// 尝试解析JSON（支持数组和对象）
		$jsonValue = json_decode($value, true); // 解析为关联数组
		// JSON解析成功（排除解析结果为null且原始值不是"null"的情况）
		if (json_last_error() === JSON_ERROR_NONE) {
			return $jsonValue;
		}

		// 解析失败：返回原值
		return $value;
	}


	/**
	 * 手动刷新配置缓存（强制重新读取文件，用于动态更新配置）
	 * @return bool 刷新结果
	 */
	public static function refreshCache(): bool {
		self::$configCache = null; // 清空缓存
		self::initConfigCache();   // 重新加载文件
		Logger::info("[Env] 配置缓存已手动刷新");
		return true;
	}


	/**
	 * 获取全部配置（仅调试/特殊场景使用，避免暴露敏感配置）
	 * @return array 全部配置数组
	 */
	public static function getAll(): array {
		self::initConfigCache();
		return self::$configCache;
	}


	/**
	 * 兼容原Config方法（保持向下兼容）
	 * @param string $key 配置键
	 * @param mixed $default 默认值
	 * @return mixed 配置值
	 */
	public static function Config(string $key, mixed $default = null): mixed {
		Logger::warning("[Env] Config() 方法已废弃，请使用 get() 方法");
		return self::get($key, $default);
	}
}
