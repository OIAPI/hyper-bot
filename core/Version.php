<?php
namespace Core;

/**
 * 版本信息（v2）。
 *
 * v1 的版本号只出现在插件注释与 composer.json 里，运行时拿不到，
 * WebUI「系统」页只能显示 PID 与启动时间。v2 给它一个单一来源。
 */
final class Version
{
    /**
     * 当前版本（单一来源 = 仓库根目录的 VERSION 文件）。
     *
     * 发布包文件名、启动横幅、WebUI 系统页、/webui/api/health 都读这里，
     * 发版时只改 VERSION 一处，避免"包里显示 2.0 而 zip 叫 2.1"这种对不上。
     */
    public const FALLBACK = '2.1.0';

    private static ?string $cached = null;

    public static function current(): string
    {
        if (self::$cached !== null) {
            return self::$cached;
        }
        $file = dirname(__DIR__) . '/VERSION';
        if (is_file($file)) {
            $read = trim((string)@file_get_contents($file));
            if ($read !== '' && preg_match('/^[0-9]+\.[0-9]+\.[0-9]+([.-][A-Za-z0-9]+)*$/', $read)) {
                return self::$cached = $read;
            }
        }
        return self::$cached = self::FALLBACK;
    }

    /** 架构说明（WebUI 与启动横幅共用） */
    public static function architecture(): string
    {
        return 'v2 内核架构（Core\Bot 订阅层 + Core\Runtime 多运行架构）';
    }
}