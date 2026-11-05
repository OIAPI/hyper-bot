<?php
namespace Core\Bot;

use Core\App;
use Core\Config;
use Core\Store\Store;

/**
 * QQ 成员权限（机器人使用权限，与后台登录账号无关）。
 *
 * 模型是「角色 + 能力」（见 MemberRoles）：存储里的 memberPermission.<QQ> 存角色名，
 * v1 的 supervisor/director/manager/default 读出来会自动映射。
 *
 * 为什么要读三个地方：
 *  1. WebUI store（文件或 Redis，取决于 [store] driver）—— 面板自己写的；
 *  2. store 里的 dbN: 前缀键 —— v1 插件调用过 $redis->select(N) 时，StoreBridge
 *     会把键写成 dbN:memberPermission.<QQ>，不带前缀扫会漏；
 *  3. 真正的 Redis（ext-redis + [Redis] host/port/auth/select）—— v1 直接在
 *     机器人那份 Redis 里写过，而面板后来可能因为 Redis 不可用降级成了文件存储，
 *     两边根本不是同一个后端，只读 store 就会出现「插件认得、面板是空列表」。
 *
 * 写的时候三个地方都写，保证面板改完插件立即生效。
 */
final class MemberPermissions
{
    private const PREFIX = 'memberPermission.';

    private static function accountId(): string {
        return AccountScope::id();
    }

    /** 账号作用域前缀（含 acct:） */
    private static function scopedPrefix(): string {
        return AccountScope::prefix();
    }

    /** 面板可选的等级（顺序 = 从高到低） */
    public const LEVELS = [
        'super'   => '主人（最高权限）',
        'owner'   => '所有者',
        'admin'   => '管理员',
        'editor'  => '编辑',
        'member'  => '普通成员',
    ];

    private static ?bool $redisOk = null;
    private static string $redisError = '';
    /** 真正读到数据的那一库（-1 = 没读到） */
    private static int $redisDataDb = -1;

    private static function store(): Store
    {
        return App::boot()->store;
    }

    /**
     * 直连 [Redis] 配置里的那个 Redis。
     * 不复用 Controller\Api::redis —— 那是 StoreBridge（长得像 Redis，实际是 store），
     * 拿它去读 v1 的历史数据永远是空的。
     */
    private static function realRedis(): ?\Redis
    {
        if (!class_exists('Redis')) {
            self::$redisOk = false;
            self::$redisError = '没有 php-redis 扩展';
            return null;
        }
        $client = new \Redis();
        $host = Config::string('Redis.host', '127.0.0.1');
        $port = Config::int('Redis.port', 6379);
        try {
            if (@$client->connect($host, $port, 0.6) !== true) {
                self::$redisOk = false;
                self::$redisError = '连不上 ' . $host . ':' . $port;
                return null;
            }
            $auth = Config::string('Redis.auth', '');
            if ($auth !== '') {
                try {
                    @$client->auth($auth);
                } catch (\Throwable $e) {
                    if (stripos($e->getMessage(), 'no password is set') === false) {
                        self::$redisOk = false;
                        self::$redisError = 'AUTH 失败：' . $e->getMessage();
                        return null;
                    }
                }
            }
            $select = Config::int('Redis.select', 0);
            if ($select > 0) {
                try {
                    @$client->select($select);
                } catch (\Throwable $e) {
                    self::$redisOk = false;
                    self::$redisError = 'SELECT ' . $select . ' 失败：' . $e->getMessage();
                    return null;
                }
            }
            if (@$client->ping() === false) {
                self::$redisOk = false;
                self::$redisError = 'PING 无响应（对端可能不是 Redis）';
                return null;
            }
            self::$redisOk = true;
            self::$redisError = '';
            return $client;
        } catch (\Throwable $e) {
            self::$redisOk = false;
            self::$redisError = $e->getMessage();
            return null;
        }
    }

    /** @return array<string, string> 扫当前库里的 memberPermission.* */
    private static function scanRedis(\Redis $client): array
    {
        $out = [];
        $keys = $client->keys(self::PREFIX . '*') ?: [];
        foreach ($keys as $key) {
            $qq = self::qqOf((string)$key);
            if ($qq === '') {
                continue;
            }
            $value = $client->get((string)$key);
            if ($value !== false && $value !== null) {
                $out[$qq] = (string)$value;
            }
        }
        return $out;
    }

    /** 从 key 里取出 QQ（兼容 acct:/dbN: 前缀） */
    private static function qqOf(string $key): string
    {
        $key = preg_replace('/^acct:[^:]+:/', '', $key) ?? $key;
        $key = preg_replace('/^db\d+:/', '', $key) ?? $key;
        $qq = substr($key, strlen(self::PREFIX));
        return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $qq) === 1 ? $qq : '';
    }

    /** @return array<string, string> qq => 原始值 */
    private static function readStore(): array
    {
        $out = [];
        $prefix = self::scopedPrefix();
        $patterns = $prefix !== ''
            ? [$prefix . self::PREFIX . '*', $prefix . 'db*:' . self::PREFIX . '*']
            : [self::PREFIX . '*', 'db*:' . self::PREFIX . '*'];
        if ($prefix !== '' && AccountScope::legacyAllowed()) {
            $patterns[] = self::PREFIX . '*';
            $patterns[] = 'db*:' . self::PREFIX . '*';
        }
        foreach ($patterns as $pattern) {
            foreach (self::store()->keys($pattern) as $key) {
                $key = (string)$key;
                if ($prefix !== '' && str_starts_with($key, 'acct:') && !str_starts_with($key, $prefix)) {
                    continue;
                }
                $qq = self::qqOf($key);
                if ($qq !== '' && !isset($out[$qq])) {
                    $out[$qq] = (string)self::store()->get($key);
                }
            }
        }
        return $out;
    }

    /** @return array<string, string> */
    private static function readRedis(): array
    {
        $client = self::realRedis();
        if ($client === null) {
            return [];
        }
        $out = [];
        try {
            $prefix = self::scopedPrefix();
            $patterns = [$prefix . self::PREFIX . '*'];
            if ($prefix !== '' && AccountScope::legacyAllowed()) {
                $patterns[] = self::PREFIX . '*';
            }
            $keys = [];
            foreach ($patterns as $pattern) {
                foreach ($client->keys($pattern) ?: [] as $key) {
                    $keys[] = $key;
                }
            }
            foreach ($keys as $key) {
                $qq = self::qqOf((string)$key);
                if ($qq === '') {
                    continue;
                }
                $value = $client->get((string)$key);
                if ($value !== false && $value !== null) {
                    $out[$qq] = (string)$value;
                }
            }
        } catch (\Throwable $e) {
            self::$redisError = $e->getMessage();
        } finally {
            try { $client->close(); } catch (\Throwable) {}
        }
        return $out;
    }

    /** @return array<int, array{qq:string, role:string, level:int, label:string, capabilities:array}> */
    public static function all(): array
    {
        $rows = [];
        foreach (self::readStore() + self::readRedis() as $qq => $raw) {
            $role = MemberRoles::normalize($raw);
            $rows[] = [
                'qq'           => (string)$qq,
                'role'         => $role,
                'level'        => MemberRoles::level($role),
                'label'        => MemberRoles::label($role),
                'capabilities' => MemberRoles::capabilities($role),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['qq'], $b['qq']));
        return $rows;
    }

    public static function get(string $qq): ?string
    {
        $qq = trim($qq);
        if ($qq === '') {
            return null;
        }
        $store = self::readStore();
        if (isset($store[$qq])) {
            return MemberRoles::normalize($store[$qq]);
        }
        $redis = self::readRedis();
        return isset($redis[$qq]) ? MemberRoles::normalize($redis[$qq]) : null;
    }

    /** @return array{ok:bool,msg:string} */
    public static function set(string $qq, string $role): array
    {
        $qq = trim($qq);
        if (preg_match('/^[A-Za-z0-9_-]{5,64}$/', $qq) !== 1) {
            return ['ok' => false, 'msg' => 'QQ 号 / 官方成员 openid 格式不正确'];
        }
        if (!MemberRoles::known($role)) {
            return ['ok' => false, 'msg' => '角色只能是 ' . implode(' / ', array_keys(MemberRoles::ROLES)) . '（也接受旧等级名）'];
        }
        $normalized = MemberRoles::normalize($role);
        if (!MemberRoles::exists($normalized)) {
            return ['ok' => false, 'msg' => '角色只能是 ' . implode(' / ', array_keys(MemberRoles::ROLES)) . '（也接受旧等级名）'];
        }

        // 1) store：账号作用域键；单账号同时写旧键兼容旧插件
        $prefix = self::scopedPrefix();
        self::store()->set($prefix . self::PREFIX . $qq, $normalized);
        $select = Config::int('Redis.select', 0);
        if ($select > 0) {
            self::store()->set($prefix . 'db' . $select . ':' . self::PREFIX . $qq, $normalized);
        }
        if ($prefix !== '' && AccountScope::legacyAllowed()) {
            self::store()->set(self::PREFIX . $qq, $normalized);
        }

        // 2) 真 Redis：v1 插件读的是它
        $client = self::realRedis();
        if ($client !== null) {
            try {
                $client->set($prefix . self::PREFIX . $qq, $normalized);
                if ($prefix !== '' && AccountScope::legacyAllowed()) {
                    $client->set(self::PREFIX . $qq, $normalized);
                }
            } catch (\Throwable $e) {
                self::$redisError = $e->getMessage();
            } finally {
                try { $client->close(); } catch (\Throwable) {}
            }
        }
        return ['ok' => true, 'msg' => '已设置为 ' . MemberRoles::label($normalized)];
    }

    public static function remove(string $qq): bool
    {
        $qq = trim($qq);
        if ($qq === '') {
            return false;
        }
        $existed = false;
        $prefix = self::scopedPrefix();
        $key = $prefix . self::PREFIX . $qq;
        if (self::store()->has($key)) {
            $existed = true;
            self::store()->del($key);
        }
        foreach (self::store()->keys($prefix . 'db*:' . self::PREFIX . $qq) as $prefixed) {
            $existed = true;
            self::store()->del((string)$prefixed);
        }
        if ($prefix !== '' && AccountScope::legacyAllowed()) {
            $legacy = self::PREFIX . $qq;
            if (self::store()->has($legacy)) {
                $existed = true;
                self::store()->del($legacy);
            }
            foreach (self::store()->keys('db*:' . $legacy) as $prefixed) {
                $existed = true;
                self::store()->del((string)$prefixed);
            }
        }
        $client = self::realRedis();
        if ($client !== null) {
            try {
                if ($client->exists($key)) {
                    $existed = true;
                    $client->del($key);
                }
                if ($prefix !== '' && AccountScope::legacyAllowed() && $client->exists(self::PREFIX . $qq)) {
                    $existed = true;
                    $client->del(self::PREFIX . $qq);
                }
            } catch (\Throwable $e) {
                self::$redisError = $e->getMessage();
            } finally {
                try { $client->close(); } catch (\Throwable) {}
            }
        }
        return $existed;
    }

    /** 面板顶部的统计 */
    public static function stats(): array
    {
        $rows = self::all();
        $byRole = [];
        foreach ($rows as $row) {
            $byRole[$row['role']] = ($byRole[$row['role']] ?? 0) + 1;
        }
        return ['total' => count($rows), 'by_role' => $byRole, 'by_level' => $byRole];
    }

    /** 列表为空时告诉面板「为什么空」 */
    public static function diagnose(): array
    {
        $store = self::readStore();
        $redis = self::readRedis();
        return [
            'store_keys'   => count($store),
            'redis_keys'   => count($redis),
            'redis_ok'     => self::$redisOk === true,
            'redis_error'  => self::$redisError,
            'redis_target' => Config::string('Redis.host', '127.0.0.1') . ':' . (string)Config::int('Redis.port', 6379)
                . '/db' . (string)Config::int('Redis.select', 0),
            'redis_data_db' => self::$redisDataDb,
            'store_driver' => (new \ReflectionClass(self::store()))->getShortName(),
        ];
    }

    public static function model(): array
    {
        $roles = [];
        foreach (array_keys(MemberRoles::ROLES) as $role) {
            $roles[$role] = MemberRoles::label($role);
        }
        return [
            'roles'        => $roles,
            'capabilities' => MemberRoles::CAPABILITIES,
            'matrix'       => MemberRoles::matrix(),
            'legacy'       => MemberRoles::LEGACY,
        ];
    }
}