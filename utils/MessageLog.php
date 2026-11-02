<?php
namespace Utils;

use Swoole\Coroutine\System;


class MessageLog
{
	/**
	 * 日志存储根目录
	 * @var string
	 */
	private static $logDir = __message__;

	/**
	 * 敏感字段列表（自动脱敏）
	 * @var array
	 */
	private static $sensitiveKeys = [];
	

	/**
	 * 构造函数：初始化日志目录
	 * @param string $logDir 日志目录（默认 __message__）
	 * @throws Exception 目录创建失败时抛出异常
	 */
	private function __construct(string $logDir = __message__)
	{
		self::$logDir = rtrim($logDir, '/') . '/';
		Operate::dir(self::$logDir);
	}

	/**
	 * 写入日志（核心方法）
	 * @param array $logData 日志数据（如请求信息、业务数据）
	 * @param string $logType 日志类型（默认 request，用于区分日志文件前缀）
	 * @return bool 写入成功返回true，失败返回false
	 */
	public static function write(array|object $logData, string $logType = 'message'): bool
	{
		$y = date('Y');
		$m = date('m');
		$d = date('d');
		$dir = self::$logDir . "/{$y}/{$m}/{$d}";
		Operate::dir($dir);
		try {
			if(is_array($logData)) {
				// 1. 补充日志基础信息（时间戳、日志类型）
				$baseData = [
					'log_time' => date('Y-m-d H:i:s.u'), // 精确到微秒
					'log_type' => $logType
				];
				$logData = array_merge($baseData, $logData);
			} else {
				$logData->log_time = date('Y-m-d H:i:s.u'); // 精确到微秒
				$logData->log_type = $logType;
			}

			// 2. 敏感字段脱敏
			// self::desensitizeData($logData);

			// 3. 生成日志行（JSON格式，便于解析）
			$logData = json_encode($logData, JSON_UNESCAPED_UNICODE) . PHP_EOL;

			// 4. 按日期生成日志文件名（如 request_20240520.log）
			$logFilename = $dir . "/{$logType}.log";

			// 5. 协程写入文件（追加模式）
			// Swoole 协程写入在 Android FUSE 上也会因 flock 失败，统一走 Core\Fs
			if (class_exists('\Swoole\Coroutine\System') && \Swoole\Coroutine::getCid() > 0) {
				$ok = \Swoole\Coroutine\System::writeFile($logFilename, $logData, FILE_APPEND);
				if ($ok !== false) {
					return true;
				}
			}
			return \Core\Fs::append($logFilename, $logData);
		} catch (Exception $e) {
			// 异常时写入错误日志（单独文件）
			$errorLog = date('Y-m-d H:i:s') . " | 日志写入异常：" . $e->getMessage() . PHP_EOL;
			System::writeFile(
				$dir . '/log_error_' . date('Ymd') . '.log',
				$errorLog,
				FILE_APPEND
			);
			return false;
		}
	}

	/**
	 * 读取日志（按日期+类型）
	 * @param string $date 日期（格式 Ymd，如 20240520，默认当天）
	 * @param string $logType 日志类型（默认 request）
	 * @return array 日志列表（每条日志为数组格式），读取失败返回空数组
	 */
	public static function read(string $date = '', string $logType = 'message'): array
	{
		$date = $date ?: date('Ymd');
		$y = date('Y');
		$m = date('m');
		$d = date('d');
		$dir = self::$logDir . "/{$y}/{$m}/{$d}";
		Operate::dir($dir);
		$logFilename = $dir . "/{$logType}.log";

		// 检查文件是否存在
		if (!file_exists($logFilename)) {
			return [];
		}

		// 协程读取文件内容
		$logContent = System::readFile($logFilename);
		if (empty($logContent)) {
			return [];
		}

		// 解析每行日志（JSON转数组）
		$logList = [];
		$lines = explode(PHP_EOL, trim($logContent));
		foreach ($lines as $line) {
			$logData = json_decode($line, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				$logList[] = $logData;
			}
		}

		return $logList;
	}

	/**
	 * 敏感数据脱敏（递归处理数组）
	 * @param array $data 待脱敏数据（引用传递，直接修改原数组）
	 */
	private static function desensitizeData(array &$data): void
	{
		foreach ($data as $key => &$value) {
			// 键名匹配敏感字段，直接脱敏
			if (in_array(strtolower($key), self::$sensitiveKeys)) {
				$value = '******';
			}
			// 子元素为数组，递归脱敏
			elseif (is_array($value)) {
				self::desensitizeData($value);
			}
		}
		unset($value); // 释放引用
	}

	/**
	 * 自定义敏感字段（覆盖默认列表）
	 * @param array $keys 新的敏感字段列表
	 */
	public static function setSensitiveKeys(array $keys): void
	{
		self::$sensitiveKeys = array_map('strtolower', $keys);
	}
}