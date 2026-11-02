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
    /** 当前主版本：v1 = 注解 + Swoole 单体；v2 = 内核内核 + 多架构 */
    public const MAJOR = '2.0.0';

    public static function current(): string
    {
        return self::MAJOR;
    }

    /** 架构说明（WebUI 与启动横幅共用） */
    public static function architecture(): string
    {
        return 'v2 内核架构（Core\Bot 订阅层 + Core\Runtime 多运行架构）';
    }
}
