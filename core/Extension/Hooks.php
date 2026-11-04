<?php
namespace Core\Extension;

/**
 * 插件钩子（filter / action）。
 *
 * 与 onExtension 的区别：onExtension 只处理「外部事件」（GitHub、RSS）；
 * 这里的钩子直接嵌进框架自己的执行链 —— 请求前后、事件分发前后、发消息前后，
 * 插件可以改值（filter）或旁路做事（action），优先级小的先跑。
 *
 * 命名约定：领域.时机，例如 http.response、event.before_dispatch、onebot.call。
 */
final class Hooks
{
    /** @var array<string, array<int, array{0:int, 1:callable, 2:string}>> */
    private static array $hooks = [];

    /** 记录已注册的插件（用于诊断输出） */
    private static array $owners = [];

    /** 注册一个钩子；priority 小的先执行 */
    public static function on(string $name, callable $handler, int $priority = 10, string $owner = ''): void
    {
        self::$hooks[$name][] = [$priority, $handler, $owner];
        usort(self::$hooks[$name], static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        if ($owner !== '') {
            self::$owners[$name][] = $owner;
        }
    }

    /** 改值：依次把上一个处理器的返回值传给下一个 */
    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::$hooks[$name] ?? [] as [, $handler, $owner]) {
            try {
                $value = $handler($value, ...$args);
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[hook] filter 失败 | ' . $name . ($owner !== '' ? ' | ' . $owner : '') . ' | ' . $e->getMessage());
            }
        }
        return $value;
    }

    /** 旁路动作：不关心返回值 */
    public static function do(string $name, mixed ...$args): void
    {
        foreach (self::$hooks[$name] ?? [] as [, $handler, $owner]) {
            try {
                $handler(...$args);
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[hook] action 失败 | ' . $name . ($owner !== '' ? ' | ' . $owner : '') . ' | ' . $e->getMessage());
            }
        }
    }

    public static function has(string $name): bool
    {
        return isset(self::$hooks[$name]);
    }

    /** 诊断用：每个钩子挂了哪些插件 */
    public static function summary(): array
    {
        $out = [];
        foreach (self::$hooks as $name => $list) {
            $out[] = [
                'name'     => $name,
                'handlers' => count($list),
                'owners'   => array_values(array_unique(self::$owners[$name] ?? [])),
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $out;
    }

    /** 测试用 */
    public static function reset(): void
    {
        self::$hooks = [];
        self::$owners = [];
    }
}
