<?php
namespace Core\Bot;

use Utils\Cache;
use Utils\Logger;

/**
 * 插件代码重载：清 OPcache/注册表，并让 Swoole worker 读取新类。
 *
 * Swoole master 不能在 fork worker 前加载插件类；这里只负责在 worker 内
 * invalidate 文件缓存并调度一次 worker 重启。PHP/webui 架构没有常驻 worker，
 * 直接重新加载即可。
 */
final class PluginReloader
{
    /** @return bool 是否调度了 Swoole worker 重启 */
    public static function reload(): bool
    {
        \Core\App::boot()->invalidatePluginCode();
        $scheduled = self::scheduleWorkerReload();
        if (!$scheduled) {
            // PHP / webui：当次请求内重扫，下一次请求读到的就是新文件
            \Core\App::boot()->loadPlugins(true);
        }
        return $scheduled;
    }

    /** 只清缓存，不调度 worker（调用方自己重启 server 时用） */
    public static function invalidate(): void
    {
        \Core\App::boot()->invalidatePluginCode();
    }

    private static function scheduleWorkerReload(): bool
    {
        $server = Cache::$http;
        if (!is_object($server)) {
            return false;
        }
        try {
            // 优先给 master 发 SIGUSR1：worker 内直接 Server::reload() 在部分
            // Swoole 版本不允许；信号让 master 统一重启 worker。
            if (function_exists('posix_kill') && isset($server->master_pid)) {
                $pid = (int)$server->master_pid;
                $signal = defined('SIGUSR1') ? SIGUSR1 : 10;
                if (class_exists(\Swoole\Timer::class)) {
                    // 延迟到响应写完再发信号，避免当前请求被 worker 重载打断
                    \Swoole\Timer::after(250, static function () use ($pid, $signal): void {
                        @posix_kill($pid, $signal);
                    });
                } else {
                    @posix_kill($pid, $signal);
                }
                return true;
            }
            if (method_exists($server, 'reload') && class_exists(\Swoole\Timer::class)) {
                \Swoole\Timer::after(250, static function () use ($server): void {
                    try {
                        $server->reload();
                    } catch (\Throwable $e) {
                        Logger::error('[plugin] Worker reload 失败：' . $e->getMessage());
                    }
                });
                return true;
            }
        } catch (\Throwable $e) {
            Logger::error('[plugin] 调度 Worker reload 失败：' . $e->getMessage());
        }
        return false;
    }
}
