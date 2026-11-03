<?php
namespace Utils;

/**
 * 消息/事件审计日志（按天分目录，JSON Lines）。
 *
 * 2026-11 重写要点（均为实测缺陷）：
 *   1. read($date) 曾完全忽略 $date，永远读当天 —— 面板查历史日期拿到的是今天的数据；
 *   2. read() 直接调 Swoole\Coroutine\System::readFile，非协程/无 Swoole 环境直接 fatal；
 *   3. catch (Exception) 在 Utils 命名空间下匹配的是 Utils\Exception（不存在），
 *      所以异常分支从来没生效过，且异常分支自身又调了一次 System::writeFile 同样会 fatal；
 *   4. 脱敏方法写好了却被注释掉，$sensitiveKeys 永远是空数组 —— 现在默认生效；
 *   5. 单条超大 payload 会把一行写成几 MB，面板读取时整行载入内存 —— 现在截断；
 *   6. 日志文件只增不减 —— 现在按大小轮转并只保留最近 N 个备份。
 */
class MessageLog
{
    /** 单行上限：超过就截断（默认 256KB） */
    public const MAX_LINE_BYTES = 262144;

    /** 单个日志文件轮转阈值（默认 32MB） */
    public const ROTATE_BYTES = 33554432;

    /** 保留的轮转备份个数 */
    public const MAX_BACKUPS = 3;

    private static ?string $logDir = null;
    private static int $maxLineBytes = self::MAX_LINE_BYTES;
    private static int $rotateBytes = self::ROTATE_BYTES;
    private static int $maxBackups = self::MAX_BACKUPS;

    /** 敏感字段（小写匹配，命中值替换为 ******） */
    private static array $sensitiveKeys = [
        'password', 'passwd', 'pwd', 'token', 'access_token', 'refresh_token',
        'secret', 'app_secret', 'client_secret', 'authorization', 'cookie',
        'session', 'sign', 'signature', 'apikey', 'api_key',
    ];

    private function __construct() {}

    public static function configure(
        ?string $logDir = null,
        ?int $maxLineBytes = null,
        ?int $rotateBytes = null,
        ?int $maxBackups = null
    ): void {
        if (is_string($logDir) && $logDir !== '') {
            self::$logDir = rtrim($logDir, '/');
            self::ensureDir(self::$logDir);
        }
        if ($maxLineBytes !== null && $maxLineBytes > 0) {
            self::$maxLineBytes = $maxLineBytes;
        }
        if ($rotateBytes !== null && $rotateBytes > 0) {
            self::$rotateBytes = $rotateBytes;
        }
        if ($maxBackups !== null && $maxBackups >= 0) {
            self::$maxBackups = $maxBackups;
        }
    }

    public static function dir(): string
    {
        if (self::$logDir === null) {
            self::$logDir = defined('__message__')
                ? rtrim((string)constant('__message__'), '/')
                : sys_get_temp_dir() . '/hyper-message';
        }
        return self::$logDir;
    }

    /**
     * 写入一条日志。
     * @param array|object $logData 事件或业务数据
     * @param string $logType 文件前缀（message / notice / request ...）
     */
    public static function write(array|object $logData, string $logType = 'message'): bool
    {
        $logType = self::safeType($logType);
        $dir = self::dayDir(date('Ymd'), $logType);

        try {
            if ($logType !== 'message') {
                // 先写 message.log 之外的分类文件时，目录可能还没建
                self::ensureDir(dirname($dir));
            }

            $base = [
                'log_time' => date('Y-m-d H:i:s') . '.' . substr((string)microtime(), 2, 3),
                'log_type' => $logType,
            ];
            $data = is_array($logData) ? array_merge($base, $logData) : self::objectWith($logData, $base);

            self::desensitize($data);
            $json = self::encode($data);
            if ($json === null) {
                return false;
            }

            self::rotateIfNeeded($dir);
            return self::append($dir, $json . PHP_EOL);
        } catch (\Throwable $e) {
            self::writeError($e->getMessage());
            return false;
        }
    }

    /**
     * 读取某天的日志。
     * @param string $date Ymd（20240520）或 Y-m-d，空 = 今天
     * @param string $logType 文件前缀
     * @param int $limit 最多返回多少条（0 = 全部，倒序由调用方决定）
     */
    public static function read(string $date = '', string $logType = 'message', int $limit = 0): array
    {
        $logType = self::safeType($logType);
        $file = self::dayDir($date === '' ? date('Ymd') : $date, $logType);
        if (!is_file($file)) {
            return [];
        }

        $content = self::readFile($file);
        if ($content === '') {
            return [];
        }

        $list = [];
        foreach (explode(PHP_EOL, trim($content)) as $line) {
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $list[] = $decoded;
            }
        }
        if ($limit > 0 && count($list) > $limit) {
            $list = array_slice($list, -$limit);
        }
        return $list;
    }

    /** 有日志的日期列表（倒序，最多 $limit 天），供面板筛选 */
    public static function dates(string $logType = 'message', int $limit = 60): array
    {
        $logType = self::safeType($logType);
        $root = self::dir();
        $dates = [];
        $files = glob($root . '/*/*/*/' . $logType . '.log') ?: [];
        foreach ($files as $file) {
            // .../YYYY/MM/DD/message.log
            $parts = explode('/', str_replace('\\', '/', $file));
            if (count($parts) < 4) {
                continue;
            }
            $dates[] = $parts[count($parts) - 4] . $parts[count($parts) - 3] . $parts[count($parts) - 2];
        }
        $dates = array_values(array_unique($dates));
        rsort($dates, SORT_STRING);
        return $limit > 0 ? array_slice($dates, 0, $limit) : $dates;
    }

    /** 文件绝对路径：$date 为空 = 今天 */
    public static function path(string $date = '', string $logType = 'message'): string
    {
        return self::dayDir($date === '' ? date('Ymd') : $date, self::safeType($logType));
    }

    /** 目录 + 文件大小统计 */
    public static function stats(string $logType = 'message'): array
    {
        $logType = self::safeType($logType);
        $files = glob(self::dir() . '/*/*/*/' . $logType . '*.log') ?: [];
        $bytes = 0;
        foreach ($files as $file) {
            $bytes += (int)@filesize($file);
        }
        return ['files' => count($files), 'bytes' => $bytes, 'dir' => self::dir()];
    }

    public static function setSensitiveKeys(array $keys): void
    {
        self::$sensitiveKeys = array_values(array_unique(array_map(
            static fn($k): string => strtolower(trim((string)$k)),
            $keys
        )));
    }

    public static function sensitiveKeys(): array
    {
        return self::$sensitiveKeys;
    }

    // ---------------------------------------------------------------- 内部

    private static function safeType(string $logType): string
    {
        $type = strtolower(trim($logType));
        $type = preg_replace('/[^a-z0-9_-]+/', '_', $type) ?? 'message';
        return $type === '' ? 'message' : $type;
    }

    /** 解析 Ymd / Y-m-d / 空 为具体文件路径 */
    private static function dayDir(string $date, string $logType): string
    {
        $date = trim($date);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)) {
            $date = $m[1] . $m[2] . $m[3];
        }
        $root = self::dir();
        if (!preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            $m = [(string)date('Y'), date('Y'), date('m'), date('d')];
        }
        return $root . '/' . $m[1] . '/' . $m[2] . '/' . $m[3] . '/' . $logType . '.log';
    }

    private static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private static function append(string $file, string $line): bool
    {
        $dir = dirname($file);
        self::ensureDir($dir);
        if (!is_writable($dir)) {
            error_log('MessageLog: 目录不可写 ' . $dir);
            return false;
        }
        if (@file_put_contents($file, $line, FILE_APPEND | LOCK_EX) !== false) {
            return true;
        }
        // 极端情况下（跨卷 / FUSE）退回内核的原子追加实现
        $fs = '\\Core\\Fs';
        if (class_exists($fs) && method_exists($fs, 'append')) {
            return (bool)$fs::append($file, $line);
        }
        return false;
    }

    private static function readFile(string $file): string
    {
        $content = @file_get_contents($file);
        return is_string($content) ? $content : '';
    }

    private static function encode(array $data): ?string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return null;
        }
        $size = strlen($json);
        if ($size > self::$maxLineBytes) {
            $json = substr($json, 0, self::$maxLineBytes)
                . '... [truncated ' . ($size - self::$maxLineBytes) . ' bytes]';
        }
        return $json;
    }

    private static function objectWith(object $logData, array $base): array
    {
        $data = get_object_vars($logData);
        $data['log_time'] = $base['log_time'];
        $data['log_type'] = $base['log_type'];
        return $data;
    }

    private static function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        clearstatcache(true, $file);
        if ((int)@filesize($file) <= self::$rotateBytes) {
            return;
        }
        if (@rename($file, $file . '.' . date('Ymd-His'))) {
            self::prune($file);
        }
    }

    private static function prune(string $file): void
    {
        $backups = array_values(array_filter(glob($file . '.*') ?: [], 'is_file'));
        $excess = count($backups) - self::$maxBackups;
        if ($excess <= 0) {
            return;
        }
        sort($backups, SORT_STRING);
        foreach (array_slice($backups, 0, $excess) as $old) {
            @unlink($old);
        }
    }

    private static function writeError(string $message): void
    {
        try {
            $dir = self::dir();
            self::ensureDir($dir);
            $line = date('Y-m-d H:i:s') . ' | 日志写入异常：' . $message . PHP_EOL;
            @file_put_contents($dir . '/log_error_' . date('Ymd') . '.log', $line, FILE_APPEND | LOCK_EX);
        } catch (\Throwable) {
            // 日志系统自身失败时不再抛出
        }
    }

    /** 递归脱敏：命中敏感键名的值替换为 ****** */
    private static function desensitize(array|object &$data): void
    {
        foreach ($data as $key => &$value) {
            if (is_string($key) && in_array(strtolower($key), self::$sensitiveKeys, true)) {
                $value = '******';
                continue;
            }
            if (is_array($value) || is_object($value)) {
                self::desensitize($value);
            }
        }
        unset($value);
    }
}
