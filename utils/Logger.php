<?php
namespace Utils;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Coroutine\System;

class Logger
{
	// 静态属性
	private static $logFile = __logger__ . '/logger.log';
	private static $maxSize = 542880;
	private static $useColors = true;
	private static $consoleEnabled = true;
	private static $fileEnabled = true;
	private static $logChannel;
	private static $writerRunning = false;
	private static $shutdownRegistered = false;

	// ANSI 颜色代码
	const COLORS = [
		'error'	=> "\033[31m",
		'success'  => "\033[32m",
		'debug'	=> "\033[34m",
		'message'  => "\033[37m",
		'reset'	=> "\033[0m",
		"warning"  => "\033[38;5;3m",
		"info"	 => "\033[38;5;12m",
	];

	const TERM_CTRL = [
		'save'	 => "\033[s",
		'restore'  => "\033[u",
		'clear'	=> "\033[2K",
		'up'	   => "\033[1A"
	];

	private function __construct() {}

	public static function init() {
		// 初始化日志通道和写入器协程
		if (extension_loaded('swoole') && self::$fileEnabled) {
			self::$logChannel = new Channel(1000); // 1000条日志的容量
			self::startWriterCoroutine();
		}
	}

	/**
	 * 启动日志写入器协程
	 */
	private static function startWriterCoroutine(): void
	{
		if (self::$writerRunning) {
			return;
		}

		Coroutine::create(function () {
			self::$writerRunning = true;
			$buffer = '';
			$lastFlushTime = time();

			while (true) {
				// 设置超时时间，定期刷新缓冲区
				$logEntry = self::$logChannel->pop(0.1); // 100ms超时

				if ($logEntry === false) {
					// 超时，检查是否需要刷新缓冲区
					$currentTime = time();
					if (!empty($buffer) && ($currentTime - $lastFlushTime) >= 1) {
						self::flushToFile($buffer);
						$buffer = '';
						$lastFlushTime = $currentTime;
					}
					continue;
				}

				if ($logEntry === null) {
					// 通道关闭，刷新剩余缓冲区并退出
					if (!empty($buffer)) {
						self::flushToFile($buffer);
					}
					break;
				}

				// 添加到缓冲区
				$buffer .= $logEntry;

				// 缓冲区达到一定大小立即刷新
				if (strlen($buffer) >= 8192) {
					self::flushToFile($buffer);
					$buffer = '';
					$lastFlushTime = time();
				}
			}
			
			self::$writerRunning = false;
		});
	}

	/**
	 * 核心日志方法
	 */
	private static function log(string $level, string $msg, ?int $timeout): void
	{
		$timestamp = date('[Y-m-d H:i:s]');
		$formatted = "{$timestamp} [".strtoupper($level)."] {$msg}" . PHP_EOL;

		// 控制台输出
		if (self::$consoleEnabled) {
			self::consoleOutput($level, $formatted, $timeout);
		}

		// 文件记录
		if (self::$fileEnabled && self::$logFile) {
			if (extension_loaded('swoole') && self::$logChannel) {
				// 使用协程通道异步写入
				if (!self::$logChannel->push($formatted)) {
					// 通道已满，直接同步写入（确保不丢失日志）
					self::syncWriteToFile($formatted);
				}
			} else {
				// 非协程环境同步写入
				self::syncWriteToFile($formatted);
			}
		}
	}

	/**
	 * 同步写入文件（确保日志不丢失）
	 */
	private static function syncWriteToFile(string $data): void
	{
		try {
			// 检查文件是否可写
			if (!is_writable(dirname(self::$logFile))) {
				error_log("Logger: Directory not writable: " . dirname(self::$logFile));
				return;
			}
			
			// 检查是否需要日志轮换
			if (self::needRotate()) {
				self::rotateLog();
			}
			
			$result = file_put_contents(self::$logFile, $data, FILE_APPEND);
			
			if ($result === false) {
				error_log("Logger error: " . error_get_last()['message'] ?? 'Unknown error');
			}
		} catch (\Exception $e) {
			error_log("Logger exception: " . $e->getMessage());
		}
	}

	/**
	 * 刷新缓冲区到文件
	 */
	private static function flushToFile(string $data): void
	{
		try {
			if (self::needRotate()) {
				self::rotateLog();
			}
			
			$result = file_put_contents(self::$logFile, $data, FILE_APPEND);
			
			if ($result === false) {
				error_log("Logger flush failed: " . self::$logFile);
			}
		} catch (\Exception $e) {
			error_log("Logger flush error: " . $e->getMessage());
		}
	}

	/**
	 * 文件轮换检测
	 */
	private static function needRotate(): bool
	{
		if (!file_exists(self::$logFile)) {
			return false;
		}
		
		clearstatcache(true, self::$logFile);
		return filesize(self::$logFile) > self::$maxSize;
	}

	/**
	 * 日志轮换
	 */
	private static function rotateLog(): void
	{
		try {
			if (file_exists(self::$logFile)) {
				$backupFile = self::$logFile . '.' . date('Ymd-His');
				rename(self::$logFile, $backupFile);
			}
		} catch (\Exception $e) {
			error_log("Log rotation failed: " . $e->getMessage());
		}
	}

	/**
	 * 安全关闭
	 */
	public static function shutdown(): void
	{
		if (self::$logChannel && !self::$logChannel->isEmpty()) {
			// 等待所有日志写入完成
			$timeout = 3; // 3秒超时
			$startTime = time();
			
			while (!self::$logChannel->isEmpty() && (time() - $startTime) < $timeout) {
				System::sleep(0.5); // 500ms
			}
		}
		
		// 关闭通道，通知写入器协程退出
		if (self::$logChannel) {
			self::$logChannel->close();
		}
	}

	// 其他方法保持不变...
	public static function configure(
		string $logFile = null,
		int $maxSize = 1048576,
		bool $useColors = true
	): void {
		self::$logFile = $logFile;
		self::$maxSize = $maxSize;
		self::$useColors = $useColors && posix_isatty(STDOUT);
		Operate::dir($logFile);
	}

	public static function message(string $msg, int $timeout = null): void {
		self::log('message', $msg, $timeout);
	}

	public static function success(string $msg, int $timeout = null): void {
		self::log('success', $msg, $timeout);
	}

	public static function error(string $msg, int $timeout = null): void {
		self::log('error', $msg, $timeout);
	}

	public static function info(string $msg, int $timeout = null): void {
		self::log('info', $msg, $timeout);
	}

	public static function warning(string $msg, int $timeout = null): void {
		self::log('warning', $msg, $timeout);
	}

	public static function debug(string $msg, int $timeout = null): void {
		if(!defined('debug') || debug === false) return;
		self::log('debug', $msg, $timeout);
	}

	public static function tput(string $msg, int $timeout = 3) {
		self::log('info', $msg, $timeout);
	}

	private static function consoleOutput(string $level, string $msg, ?int $timeout): void {
		$output = self::$useColors 
			? self::COLORS[$level].$msg.self::COLORS['reset']
			: $msg;

		if ($timeout) {
			self::showTemporaryMessage($output, $timeout);
		} else {	
			echo $output;
		}
	}

	private static function showTemporaryMessage(string $msg, int $seconds): void {
		echo self::TERM_CTRL['save'] 
			 . str_replace(PHP_EOL, '', $msg) . str_repeat(' ', 20) 
			 . self::TERM_CTRL['restore'];

		// 协程兼容的睡眠
		if (extension_loaded('swoole')) {
			\Swoole\Coroutine::sleep($seconds);
		} else {
			sleep($seconds);
		}

		echo self::TERM_CTRL['clear']."\r";
	}
}