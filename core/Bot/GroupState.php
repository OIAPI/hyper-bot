<?php
namespace Core\Bot;

use Core\Store\Store;

/**
 * 群配置/群元数据的账号作用域读写。
 *
 * 存储：
 *   acct:{账号}:{群}.info      群开关 + 群级插件开关
 *   acct:{账号}:webui:groups   群名称/备注/标签
 *
 * 单账号部署回退旧全局键，升级后无需迁移也能看到原群列表。
 */
final class GroupState
{
    /** @return array<string,mixed>|null */
    public static function getInfo(Store $store, string $group, ?string $accountId = null): ?array
    {
        $raw = AccountScope::get($store, $group . '.info', null, $accountId);
        if (is_string($raw)) {
            $raw = json_decode($raw, true);
        }
        return is_array($raw) ? $raw : null;
    }

    public static function setInfo(Store $store, string $group, array $info, ?string $accountId = null): bool
    {
        return AccountScope::set($store, $group . '.info', $info, $accountId);
    }

    public static function delInfo(Store $store, string $group, ?string $accountId = null): int
    {
        return AccountScope::del($store, $group . '.info', $accountId);
    }

    /** @return array<string,array<string,mixed>> */
    public static function meta(Store $store, ?string $accountId = null): array
    {
        $meta = AccountScope::get($store, 'webui:groups', [], $accountId);
        return is_array($meta) ? $meta : [];
    }

    public static function setMeta(Store $store, array $meta, ?string $accountId = null): bool
    {
        return AccountScope::set($store, 'webui:groups', $meta, $accountId);
    }

    /**
     * 当前账号下出现过的群 ID（合并群配置键 + 群元数据）。
     * @return list<string>
     */
    public static function ids(Store $store, ?string $accountId = null): array
    {
        $account = AccountScope::id($accountId);
        $ids = [];

        if ($account !== '') {
            $prefix = AccountScope::prefix($account);
            foreach ($store->keys($prefix . '*.info') as $key) {
                $id = substr((string)$key, strlen($prefix), -5);
                if ($id !== '' && !str_contains($id, ':')) {
                    $ids[$id] = true;
                }
            }
        }

        if (AccountScope::legacyAllowed($account)) {
            foreach ($store->keys('*.info') as $key) {
                $key = (string)$key;
                if (str_starts_with($key, 'acct:')) {
                    continue;
                }
                $id = substr($key, 0, -5);
                if ($id !== '') {
                    $ids[$id] = true;
                }
            }
        }

        foreach (array_keys(self::meta($store, $account)) as $id) {
            $id = (string)$id;
            if ($id !== '') {
                $ids[$id] = true;
            }
        }
        return array_map('strval', array_keys($ids));
    }
}
