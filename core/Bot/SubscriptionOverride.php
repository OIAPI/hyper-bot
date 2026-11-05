<?php
namespace Core\Bot;

use Core\Fs;

/**
 * 按群覆盖（subscription group overrides）。
 *
 * 订阅原本是全局开关：一个群静音，只能整类关闭。这里给每个群一份小覆盖表，
 * 落盘 runtime/subscription-groups.json，不写 Config.ini（避免 ini 里堆一大段 JSON）。
 *
 * 覆盖字段（缺省 = 跟随全局）：
 *   mute          true = 这个群什么事件都不收
 *   message       false = 这个群的消息不收（通知仍然按全局/覆盖走）
 *   notice_types  非空 = 这个群只收这些通知类型（白名单）
 */
final class SubscriptionOverride
{
    private static string $path = '';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;
    /** @var array<string, array<string, array<string, mixed>>> 按账号缓存 */
    private static array $cacheByAccount = [];

    /** 数据文件路径（测试可改） */
    public static function path(?string $path = null): string
    {
        if ($path !== null) {
            self::$path = $path;
            self::$cache = null;
            self::$cacheByAccount = [];
            return $path;
        }
        if (self::$path === '') {
            self::$path = (defined('__root__') ? __root__ : getcwd()) . '/runtime/subscription-groups.json';
        }
        return self::$path;
    }

    /** 当前账号的数据文件（单账号仍回退旧的全局文件） */
    private static function scopedPath(): string
    {
        $basePath = self::path();
        $id = AccountScope::id();
        if ($id === '') {
            return $basePath;
        }
        $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $id) ?? $id;
        return preg_replace('/\.json$/', '.' . $safe . '.json', $basePath) ?? $basePath;
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $id = AccountScope::id();
        if (isset(self::$cacheByAccount[$id])) {
            return self::$cacheByAccount[$id];
        }
        $data = self::load(self::scopedPath());
        if ($data === [] && $id !== '' && AccountScope::legacyAllowed()) {
            $data = self::load(self::path());
        }
        self::$cacheByAccount[$id] = $data;
        self::$cache = $data;
        return $data;
    }

    /** @return array<string, mixed>|null */
    public static function get(string $groupId): ?array
    {
        $groupId = trim($groupId);
        if ($groupId === '') {
            return null;
        }
        return self::all()[$groupId] ?? null;
    }

    /**
     * 写入一个群的覆盖。
     * @param array{mute?:bool,message?:bool,notice_types?:array<int,string>|string} $data
     * @return array{ok:bool,msg:string,data:array<string,mixed>|null}
     */
    public static function set(string $groupId, array $data): array
    {
        $groupId = trim($groupId);
        if ($groupId === '' || mb_strlen($groupId) > 64) {
            return ['ok' => false, 'msg' => '群号不能为空且不超过 64 字符', 'data' => null];
        }
        if (preg_match('/[\x00-\x1f\x7f]/', $groupId) === 1) {
            return ['ok' => false, 'msg' => '群号包含非法字符', 'data' => null];
        }

        $known = SubscriptionCatalog::types();
        $types = $data['notice_types'] ?? [];
        if (is_string($types)) {
            $types = preg_split('/[,，\s]+/u', $types) ?: [];
        }
        $clean = [];
        foreach ((array)$types as $type) {
            $type = strtolower(trim((string)$type));
            if ($type === '') {
                continue;
            }
            if (!in_array($type, $known, true)) {
                return ['ok' => false, 'msg' => '未知的通知类型：' . $type, 'data' => null];
            }
            if (!in_array($type, $clean, true)) {
                $clean[] = $type;
            }
        }

        $entry = [
            'mute'         => (bool)($data['mute'] ?? false),
            'message'      => (bool)($data['message'] ?? true),
            'notice_types' => $clean,
        ];

        $all = self::all();
        $all[$groupId] = $entry;
        if (!self::persist($all)) {
            return ['ok' => false, 'msg' => '写入失败：runtime 目录不可写', 'data' => null];
        }
        self::$cacheByAccount[AccountScope::id()] = $all;
        self::$cache = $all;
        return ['ok' => true, 'msg' => '已保存群 ' . $groupId . ' 的覆盖配置', 'data' => $entry];
    }

    /** @return array{ok:bool,msg:string} */
    public static function remove(string $groupId): array
    {
        $groupId = trim($groupId);
        $all = self::all();
        if (!array_key_exists($groupId, $all)) {
            return ['ok' => false, 'msg' => '该群没有覆盖配置'];
        }
        unset($all[$groupId]);
        if (!self::persist($all)) {
            return ['ok' => false, 'msg' => '写入失败：runtime 目录不可写'];
        }
        self::$cacheByAccount[AccountScope::id()] = $all;
        self::$cache = $all;
        return ['ok' => true, 'msg' => '已删除群 ' . $groupId . ' 的覆盖配置'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function load(string $path): array
    {
        $path = $path;
        if (!is_file($path)) {
            return [];
        }
        try {
            $data = json_decode(Fs::read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($data) || !is_array($data['groups'] ?? null)) {
            return [];
        }
        $groups = [];
        foreach ($data['groups'] as $groupId => $entry) {
            $groupId = trim((string)$groupId);
            if ($groupId === '' || !is_array($entry)) {
                continue;
            }
            $groups[$groupId] = [
                'mute'         => (bool)($entry['mute'] ?? false),
                'message'      => (bool)($entry['message'] ?? true),
                'notice_types' => array_values(array_filter(array_map(
                    static fn($v) => strtolower(trim((string)$v)),
                    (array)($entry['notice_types'] ?? [])
                ))),
            ];
        }
        return $groups;
    }

    private static function persist(array $groups): bool
    {
        $path = self::scopedPath();
        $dir = dirname($path);
        if (!is_dir($dir) && !Fs::ensureDir($dir)) {
            return false;
        }
        $json = json_encode(['groups' => $groups], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        $ok = Fs::write($path, $json);
        // 单账号：同时写旧文件，旧插件/外部脚本仍能读到
        if (AccountScope::legacyAllowed() && $path !== self::path()) {
            Fs::write(self::path(), $json);
        }
        return $ok;
    }
}
