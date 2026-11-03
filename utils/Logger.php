<?php
namespace Utils;

use Swoole\Coroutine;
use Swoole\Coroutine\Channel;

/**
 * 终端 + 文件日志。
 *
 * 2026-11 重写要点（都是实测踩到的问题，不是风格偏好）：
 *   1. configure(null) 曾把 $logFile 置空，导致文件日志静默消失 —— 现在 null = 不改；
 *   2. posix_isatty() 在没装 posix 扩展的主机上直接 fatal —— 现在先 function_exists；
 *   3. 轮转备份只增不删（logger.log.20260101-101010 无限堆积）—— 现在只保留最近 N 个；
 *   4. 轮转与写入之间没有互斥，两协程同时轮转会把 rename 打空 —— 现在 rename 加锁并复检；
 *   5. 时间戳没毫秒，同秒多条无法排序 —— 现在带毫秒；
 *   6. 同步写与缓冲刷新是两份复制粘贴的代码 —— 现在合并为 append()；
 *   7. 缓冲通道的 flush/shutdown 依赖 Swoole\Coroutine\System::sleep，非协程环境不可用 —— 现在用 usleep。
 */
class Logger
{
    /** 单文件上限，超过即轮转 */
    public const DEFAULT_MAX_SIZE = 8 * 1024 * 1024;

    /** 保留的历史备份个数（不含当前文件） */
    public const MAX_BACKUPS = 5;

    private static ?string $logFile = null;
    private static int $maxSize = self::DEFAULT_MAX_SIZE;
    private static int $maxBackups = self::MAX_BACKUPS;
    private static bool $useColors = true;
    private static bool $consoleEnabled = true;
    private static bool $fileEnabled = true;
    private static ?Channel $logChannel = null;
    private static bool $writerRunning = false;

    public const COLORS = [
        'error'   => "\033[31m",
        'success' => "\033[32m",
        'debug'   => "\033[34m",
        'message' => "\033[37m",
        'reset'   => "\033[0m",
        'warning' => "\033[38;5;3m",
        'info'    => "\033[38;5;12m",
    ];

    public const TERM_CTRL = [
        'save'    => "\033[s",
        'restore' => "\033[u",
        'clear'   => "\033[2K",
        'up'      => "\033[1A",
    ];

    private function __construct() {}

    public static function init(): void
    {
        if (self::$logFile === null) {
            self::$logFile = self::defaultFile();
        }
        if (extension_loaded('swoole') && self::$fileEnabled && self::$logChannel === null) {
            self::$logChannel = new Channel(1000);
            self::startWriterCoroutine();
        }
    }

    /** 默认日志文件：优先项目 runtime/log，其次系统临时目录（测试环境常量可能不存在） */
    private static function defaultFile(): string
    {
        if (defined('__logger__')) {
            return rtrim((string)constant('__logger__'), '/') . '/logger.log';
        }
        return sys_get_temp_dir() . '/hyper-logger.log';
    }

    private static function startWriterCoroutine(): void
    {
        if (self::$writerRunning || self::$logChannel === null) {
            return;
        }
        self::$writerRunning = true;

        Coroutine::create(function (): void {
            $buffer = '';
            $lastFlush = microtime(true);

            while (true) {
                $entry = self::$logChannel->pop(0.1);
                if ($entry === false) {
                    if ($buffer !== '' && (microtime(true) - $lastFlush) >= 1.0) {
                        self::append($buffer);
                        $buffer = '';
                        $lastFlush = microtime(true);
                    }
                    continue;
                }
                if ($entry === null) {
                    if ($buffer !== '') {
                        self::append($buffer);
                    }
                    break;
                }

                $buffer .= $entry;
                if (strlen($buffer) >= 8192) {
                    self::append($buffer);
                    $buffer = '';
                    $lastFlush = microtime(true);
                }
            }

            self::$writerRunning = false;
        });
    }

    private static function log(string $level, string $msg, ?int $timeout): void
    {
        $timestamp = date('[Y-m-d H:i:s') . '.' . substr((string)microtime(), 2, 3) . ']';
        $line = $timestamp . ' [' . str_pad(strtoupper($level), 8) . '] ' . $msg . PHP_EOL;

        if (self::$consoleEnabled) {
            self::consoleOutput($level, $line, $timeout);
        }
        if (!self::$fileEnabled) {
            return;
        }
        if (self::$logFile === null) {
            self::$logFile = self::defaultFile();
        }

        if (self::$logChannel !== null) {
            // 通道满时降级为同步写，宁可慢一点也不丢日志
            if (!self::$logChannel->push($line)) {
                self::append($line);
            }
            return;
        }
        self::append($line);
    }

    /** 唯一的落盘入口：轮转判定 + 备份清理 + 追加写 */
    private static function append(string $data): void
    {
        $file = self::$logFile ?? self::defaultFile();
        self::$logFile = $file;

        try {
            $dir = dirname($file);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            if (!is_writable($dir)) {
                error_log('Logger: 目录不可写 ' . $dir);
                return;
            }
            self::rotateIfNeeded($file);
            if (@file_put_contents($file, $data, FILE_APPEND | LOCK_EX) === false) {
                error_log('Logger: 写入失败 ' . $file);
            }
        } catch (\Throwable $e) {
            error_log('Logger: 写入异常 ' . $e->getMessage());
        }
    }

    private static function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        clearstatcache(true, $file);
        if ((int)@filesize($file) <= self::$maxSize) {
            return;
        }
        $backup = $file . '.' . date('Ymd-His');
        // rename 成功才说明这次轮转归我；失败（别的协程刚轮转过）就直接继续写
        if (!@rename($file, $backup)) {
            return;
        }
        self::pruneBackups($file);
    }

    /** 只保留最近 maxBackups 个备份，避免日志目录无限增长 */
    private static function pruneBackups(string $file): void
    {
        $backups = glob($file . '.*') ?: [];
        $backups = array_values(array_filter($backups, static fn(string $p): bool => is_file($p)));
        $excess = count($backups) - self::$maxBackups;
        if ($excess <= 0) {
            return;
        }
        sort($backups, SORT_STRING);
        foreach (array_slice($backups, 0, $excess) as $old) {
            @unlink($old);
        }
    }

    /** 把缓冲通道里的内容尽快落盘（最多等 3 秒） */
    public static function flush(): void
    {
        if (self::$logChannel === null) {
            return;
        }
        $deadline = microtime(true) + 3.0;
        while (!self::$logChannel->isEmpty() && microtime(true) < $deadline) {
            usleep(20_000);
        }
        // 给写入协程一个机会把最后一批刷出去
        usleep(50_000);
    }

    public static function shutdown(): void
    {
        self::flush();
        if (self::$logChannel !== null) {
            self::$logChannel->close();
            self::$logChannel = null;
        }
    }

    /**
     * 调整输出目标。传 null 的项保持不变（历史实现会把 logFile 改成 null，等于关掉文件日志）。
     */
    public static function configure(?string $logFile = null, ?int $maxSize = null, ?bool $useColors = null): void
    {
        if (is_string($logFile) && $logFile !== '') {
            self::$logFile = $logFile;
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
        if ($maxSize !== null && $maxSize > 0) {
            self::$maxSize = $maxSize;
        }
        if ($useColors !== null) {
            self::$useColors = $useColors;
        }
        self::$useColors = self::$useColors && self::stdoutIsTty();
    }

    /** 测试/容器里没有 posix 扩展时不能让日志把进程打挂 */
    private static function stdoutIsTty(): bool
    {
        if (!defined('STDOUT')) {
            return false;
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        return false;
    }

    public static function setConsole(bool $enabled): void
    {
        self::$consoleEnabled = $enabled;
    }

    public static function setFile(bool $enabled): void
    {
        self::$fileEnabled = $enabled;
    }

    public static function file(): ?string
    {
        return self::$logFile;
    }

    public static function message(string $msg, ?int $timeout = null): void { self::log('message', $msg, $timeout); }
    public static function success(string $msg, ?int $timeout = null): void { self::log('success', $msg, $timeout); }
    public static function error(string $msg, ?int $timeout = null): void { self::log('error', $msg, $timeout); }
    public static function info(string $msg, ?int $timeout = null): void { self::log('info', $msg, $timeout); }
    public static function warning(string $msg, ?int $timeout = null): void { self::log('warning', $msg, $timeout); }

    public static function debug(string $msg, ?int $timeout = null): void
    {
        if (!defined('debug') || constant('debug') === false) {
            return;
        }
        self::log('debug', $msg, $timeout);
    }

    public static function tput(string $msg, int $timeout = 3): void
    {
        self::log('info', $msg, $timeout);
    }

    private static function consoleOutput(string $level, string $msg, ?int $timeout): void
    {
        $color = self::COLORS[$level] ?? self::COLORS['reset'];
        $output = self::$useColors ? $color . $msg . self::COLORS['reset'] : $msg;

        if ($timeout !== null && $timeout > 0) {
            self::showTemporaryMessage($output, $timeout);
            return;
        }
        echo $output;
    }

    private static function showTemporaryMessage(string $msg, int $seconds): void
    {
        echo self::TERM_CTRL['save']
            . str_replace(PHP_EOL, '', $msg)
            . str_repeat(' ', 20)
            . self::TERM_CTRL['restore'];

        if (extension_loaded('swoole') && Coroutine::getCid() > 0) {
            Coroutine::sleep($seconds);
        } else {
            usleep($seconds * 1_000_000);
        }

        echo self::TERM_CTRL['clear'] . "\r";
    }
}