<?php
namespace Core\Bot;

use Core\Store\Store;

/**
 * 账号作用域：把「群配置 / 权限 / 插件缓存」等按机器人账号隔离。
 *
 * 存储键统一加前缀 acct:{账号id}:，例如：
 *   acct:qqbot-123:130558695.info
 *   acct:qqbot-123:memberPermission.10001
 *   acct:qqbot-123:webui:groups
 *
 * 单账号部署（或还没有账号）仍回退读取旧的全局键，避免升级后数据「消失」；
 * 一旦有多个启用账号，旧全局键不再参与，防止两个号互相串群/串权限。
 */
final class AccountScope
{
    public static function id(?string $accountId = null): string
    {
        return trim($accountId ?? AccountContext::id());
    }

    public static function prefix(?string $accountId = null): string
    {
        $id = self::id($accountId);
        return $id === '' ? '' : 'acct:' . $id . ':';
    }

    public static function key(string $key, ?string $accountId = null): string
    {
        $prefix = self::prefix($accountId);
        return $prefix === '' ? $key : $prefix . $key;
    }

    /**
     * 只有单账号部署才回退旧全局键：
     * 多账号时旧数据无法判断属于谁，继续合并只会串数据。
     */
    public static function legacyAllowed(?string $accountId = null): bool
    {
        $id = self::id($accountId);
        if ($id === '') {
            return true;
        }
        return count(Accounts::enabled()) <= 1;
    }

    public static function get(Store $store, string $key, mixed $default = null, ?string $accountId = null): mixed
    {
        $id = self::id($accountId);
        if ($id !== '') {
            $value = $store->get(self::key($key, $id));
            if ($value !== null) {
                return $value;
            }
        }
        if (self::legacyAllowed($id)) {
            $value = $store->get($key);
            if ($value !== null) {
                return $value;
            }
        }
        return $default;
    }

    public static function set(Store $store, string $key, mixed $value, ?string $accountId = null): bool
    {
        return $store->set(self::key($key, $accountId), $value);
    }

    public static function del(Store $store, string $key, ?string $accountId = null): int
    {
        $id = self::id($accountId);
        $count = $id !== '' ? $store->del(self::key($key, $id)) : 0;
        if (self::legacyAllowed($id)) {
            $count += $store->del($key);
        }
        return $count;
    }
}
