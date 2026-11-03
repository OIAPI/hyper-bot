<?php
namespace Core\Bot;

/**
 * 插件全局启停状态。
 *
 * v1 只有「某个群里启用哪些插件」，没有「这个插件到底要不要加载」这一层；
 * 结果是一个插件写坏了只能把文件删掉。这里把启停从群维度提升到全局维度：
 * 关闭后注册表不索引它的钩子，事件自然不会再进它，文件仍留在原地方便随时打开。
 *
 * 存储：runtime/plugins-state.json，结构 {"disabled": ["App\\Plugins\\Sys"]}。
 * 默认「在列表里 = 启用」，所以只有被显式关闭的类才落盘。
 */
final class PluginState
{
    private const FILE = '/runtime/plugins-state.json';

    private static ?array $cache = null;

    public static function path(): string
    {
        $root = defined('__root__') ? __root__ : dirname(__DIR__, 2);
        return $root . self::FILE;
    }

    /** @return list<string> 已关闭的插件类名 */
    public static function disabled(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = self::path();
        if (!is_file($file)) {
            return self::$cache = [];
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        $list = is_array($decoded['disabled'] ?? null) ? $decoded['disabled'] : [];
        return self::$cache = array_values(array_unique(array_map('strval', $list)));
    }

    public static function isEnabled(string $class): bool
    {
        // 大小写不敏感：文件名的类名与声明的类名可能只是大小写不同
        $target = strtolower(ltrim($class, '\\'));
        foreach (self::disabled() as $disabled) {
            if (strtolower($disabled) === $target) {
                return false;
            }
        }
        return true;
    }

    public static function setEnabled(string $class, bool $enabled): bool
    {
        $class = ltrim($class, '\\');
        $list = self::disabled();
        $list = array_values(array_filter($list, static fn($c) => strcasecmp($c, $class) !== 0));
        if (!$enabled) {
            $list[] = $class;
        }
        sort($list);
        $ok = \Core\Fs::write(self::path(), (string)json_encode(
            ['disabled' => $list, 'updated_at' => time()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        ));
        if ($ok) {
            self::$cache = $list;
        }
        return $ok;
    }

    /** 测试用：丢掉内存缓存 */
    public static function flush(): void
    {
        self::$cache = null;
    }
}