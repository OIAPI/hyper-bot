<?php
namespace Core;

use Utils\Logger;

/**
 * 调度器（v2）：统一「延迟执行」与「周期执行」，两种运行架构下都能用。
 *
 * v1 直接调 `Swoole\Timer::after/tick`，散布在 BaseApp::del()、Api::tick()、
 * TaskManager 里。没有 Swoole 时要么报致命错误，要么整段功能静默失效。
 *
 * v2：
 *   - Swoole 架构：直接映射到 Swoole\Timer（毫秒精度，进程内）；
 *   - PHP 架构：写入文件队列（runtime/schedule.jsonl），由 `bin/hyper worker`
 *     每秒轮询一次并执行到期的任务（精度约 1 秒，够 WebUI 与业务用）。
 *
 * 无论哪种架构，API 都一样：`Scheduler::after($seconds, $callback)`。
 *
 * 注意：PHP 模式下闭包无法持久化（这是 PHP 的固有限制），所以 PHP 模式的延迟任务
 * 只支持「可序列化的回调」（静态方法数组或已注册的命名任务）。传闭包会被拒绝并记日志，
 * 而不是静默丢弃 —— 让问题在开发期就暴露。
 */
final class Scheduler
{
    /** @var array<string,array{0:float,1:callable}> Swoole 模式下的内存任务 */
    private static array $timers = [];

    /** 是否使用 Swoole 定时器 */
    private static function useSwoole(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /**
     * 延迟执行一次。
     * @param float $seconds 延迟秒数（支持小数）
     * @param callable $callback 回调
     */
    public static function after(float $seconds, callable $callback): void
    {
        $seconds = max(0.0, $seconds);

        if (self::useSwoole()) {
            \Swoole\Timer::after((int)($seconds * 1000), function () use ($callback): void {
                try {
                    $callback();
                } catch (\Throwable $e) {
                    Logger::error('[调度器] 延迟任务异常：' . $e->getMessage());
                }
            });
            return;
        }

        // PHP 模式：写入队列，交给 worker 轮询
        $id = bin2hex(random_bytes(6));
        $entry = [
            'id'       => $id,
            'due'      => microtime(true) + $seconds,
            'callback' => self::serializeCallback($callback),
        ];
        if ($entry['callback'] === null) {
            Logger::error('[调度器] PHP 模式下调用了不可持久化的闭包，延迟任务未注册（请改用可序列化的回调或 Swoole 架构）');
            return;
        }
        self::enqueue($entry);
    }

    /**
     * 周期执行。
     * @param float $seconds 间隔秒数
     * @param callable $callback 回调
     * @return string 任务 ID（可用于 cancel）
     */
    public static function every(float $seconds, callable $callback): string
    {
        $seconds = max(0.1, $seconds);
        $id = bin2hex(random_bytes(6));

        if (self::useSwoole()) {
            self::$timers[$id] = [$seconds, $callback];
            \Swoole\Timer::tick((int)($seconds * 1000), function () use ($id, $callback): void {
                if (!isset(self::$timers[$id])) {
                    return;
                }
                try {
                    $callback();
                } catch (\Throwable $e) {
                    Logger::error('[调度器] 周期任务异常：' . $e->getMessage());
                }
            });
            return $id;
        }

        // PHP 模式：用「首次到期 + interval」表达周期任务
        $entry = [
            'id'       => $id,
            'due'      => microtime(true) + $seconds,
            'interval' => $seconds,
            'callback' => self::serializeCallback($callback),
        ];
        if ($entry['callback'] === null) {
            Logger::error('[调度器] PHP 模式下调用了不可持久化的闭包，周期任务未注册');
            return $id;
        }
        self::enqueue($entry);
        return $id;
    }

    public static function cancel(string $id): void
    {
        if (self::useSwoole()) {
            unset(self::$timers[$id]);
            if (function_exists('\Swoole\Timer::clear')) {
                @\Swoole\Timer::clear((int)$id);
            }
            return;
        }
        self::rewriteQueue(array_values(array_filter(
            self::readQueue(),
            static fn(array $entry): bool => ($entry['id'] ?? '') !== $id
        )));
    }

    /**
     * 取出所有已到期的任务（worker 每次轮询调用一次）
     * @return list<array> 每条含 callback 可执行数组
     */
    public static function due(): array
    {
        $now = microtime(true);
        $queue = self::readQueue();
        $due = [];
        $keep = [];

        foreach ($queue as $entry) {
            if (($entry['due'] ?? PHP_FLOAT_MAX) <= $now) {
                $due[] = $entry;
                // 周期任务重新排期
                if (isset($entry['interval'])) {
                    $entry['due'] = $now + (float)$entry['interval'];
                    $keep[] = $entry;
                }
                continue;
            }
            $keep[] = $entry;
        }

        if ($due !== [] || count($keep) !== count($queue)) {
            self::rewriteQueue($keep);
        }
        return $due;
    }

    /** 执行一条队列任务 */
    public static function run(array $entry): void
    {
        $callback = self::deserializeCallback($entry['callback'] ?? null);
        if ($callback === null) {
            Logger::warning('[调度器] 无法还原回调，已跳过：' . ($entry['id'] ?? '?'));
            return;
        }
        try {
            $callback();
        } catch (\Throwable $e) {
            Logger::error('[调度器] 队列任务异常：' . $e->getMessage());
        }
    }

    /** 队列文件路径 */
    public static function path(?string $path = null): string
    {
        static $custom = null;
        if ($path !== null) {
            $custom = $path;
        }
        if ($custom !== null) {
            return $custom;
        }
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__) . '/runtime';
        return $root . '/schedule.jsonl';
    }

    // ---------------- 队列文件 ----------------

    private static function enqueue(array $entry): void
    {
        Fs::append(self::path(), json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n");
    }

    /** @return list<array> */
    private static function readQueue(): array
    {
        $file = self::path();
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /** @param list<array> $entries */
    private static function rewriteQueue(array $entries): void
    {
        $lines = array_map(
            static fn(array $e): string => json_encode($e, JSON_UNESCAPED_UNICODE),
            $entries
        );
        Fs::write(self::path(), implode("\n", $lines) . ($lines === [] ? '' : "\n"));
    }

    // ---------------- 回调序列化 ----------------

    /**
     * 只接受可序列化的回调：
     *   - [类名, 方法名]（可静态调用）
     *   - 'Class::method'
     * 闭包/对象方法一律返回 null（PHP 无法跨进程持久化）。
     */
    private static function serializeCallback(callable $callback): ?array
    {
        if (is_array($callback) && count($callback) === 2) {
            [$target, $method] = $callback;
            if (is_string($target) && is_string($method)) {
                return ['type' => 'static', 'class' => $target, 'method' => $method];
            }
            return null;
        }
        if (is_string($callback) && str_contains($callback, '::')) {
            [$class, $method] = explode('::', $callback, 2);
            return ['type' => 'static', 'class' => $class, 'method' => $method];
        }
        return null;
    }

    private static function deserializeCallback(?array $spec): ?callable
    {
        if (!is_array($spec) || ($spec['type'] ?? '') !== 'static') {
            return null;
        }
        $class = (string)($spec['class'] ?? '');
        $method = (string)($spec['method'] ?? '');
        if ($class === '' || $method === '' || !class_exists($class)) {
            return null;
        }
        return [$class, $method];
    }
}