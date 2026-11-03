<?php
namespace Core\Bot;

use Core\App;
use Core\Store\Store;

/**
 * QQ 成员权限（机器人使用权限，与后台登录账号无关）。
 *
 * 模型是「角色 + 能力」（见 MemberRoles）：存储里的 memberPermission.<QQ> 存角色名，
 * v1 的 supervisor/director/manager/default 读出来会自动映射。
 *
 * 两个存储源都要看：
 *  - WebUI 的 store（文件或 Redis，取决于 [store] driver）；
 *  - 机器人自己那份 Redis（BaseApp::$redis，插件从 v1 起就写这里）。
 * 只读 store 会出现「插件认得、面板列表却是空的」，所以这里合并两边，
 * 写入时也两边都写，保证面板改完插件立即生效。
 */
final class MemberPermissions
{
    private const PREFIX = 'memberPermission.';

    /** 面板可选的等级（顺序 = 从高到低） */
    public const LEVELS = [
        'super'   => '主人（最高权限）',
        'owner'   => '所有者',
        'admin'   => '管理员',
        'editor'  => '编辑',
        'member'  => '普通成员',
    ];

    private static function store(): Store
    {
        return App::boot()->store;
    }

    /**
     * 机器人用的 Redis 连接（v1 插件写的就是它）。
     * 没有扩展、没配置或连接失败时返回 null，所有逻辑自动退回 store。
     */
    private static function redis(): ?object
    {
        try {
            $redis = \Controller\Api::getInstance()->redis;
            return is_object($redis) ? $redis : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<int, array{qq:string, role:string, level:int, label:string, capabilities:array}> */
    public static function all(): array
    {
        $map = [];

        // 1) WebUI store
        foreach (self::store()->keys(self::PREFIX . '*') as $key) {
            $qq = substr((string)$key, strlen(self::PREFIX));
            if ($qq === '' || !ctype_digit($qq)) {
                continue;
            }
            $map[$qq] = (string)self::store()->get($key);
        }

        // 2) 机器人 Redis（v1 数据多半只在这里）
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $keys = $redis->keys(self::PREFIX . '*');
                foreach (is_array($keys) ? $keys : [] as $key) {
                    $key = (string)$key;
                    $qq = substr($key, strlen(self::PREFIX));
                    if ($qq === '' || !ctype_digit($qq) || isset($map[$qq])) {
                        continue;
                    }
                    $map[$qq] = (string)$redis->get($key);
                }
            } catch (\Throwable) {
                // Redis 不可用不影响 store 里的数据
            }
        }

        $rows = [];
        foreach ($map as $qq => $raw) {
            $role = MemberRoles::normalize(strtolower(trim($raw)));
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
        $value = self::store()->get(self::PREFIX . $qq);
        if ($value === null && ($redis = self::redis()) !== null) {
            try {
                $value = $redis->get(self::PREFIX . $qq);
            } catch (\Throwable) {
                $value = null;
            }
        }
        return $value === null || $value === false ? null : MemberRoles::normalize(strtolower(trim((string)$value)));
    }

    /** @return array{ok:bool,msg:string} */
    public static function set(string $qq, string $role): array
    {
        $qq = trim($qq);
        $raw = strtolower(trim($role));
        // 先拒绝未知值，再映射旧名：normalize() 会把未知值兜底成 member，
        // 直接用它会让「传错角色」被静默接受
        $isNumericLevel = $raw !== '' && ctype_digit($raw);
        if (!$isNumericLevel && !isset(MemberRoles::ROLES[$raw]) && !isset(MemberRoles::LEGACY[$raw])) {
            return ['ok' => false, 'msg' => '角色只能是 ' . implode(' / ', array_keys(MemberRoles::ROLES))];
        }
        $role = MemberRoles::normalize($raw);
        if (!ctype_digit($qq) || strlen($qq) < 5 || strlen($qq) > 12) {
            return ['ok' => false, 'msg' => 'QQ 号必须是 5-12 位数字'];
        }
        if (!isset(MemberRoles::ROLES[$role])) {
            return ['ok' => false, 'msg' => '角色只能是 ' . implode(' / ', array_keys(MemberRoles::ROLES))];
        }

        self::store()->set(self::PREFIX . $qq, $role);
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $redis->set(self::PREFIX . $qq, $role);
            } catch (\Throwable) {
                // store 写成功就算成功，Redis 失败只影响插件侧的即时生效
            }
        }
        return ['ok' => true, 'msg' => '已设置为 ' . MemberRoles::label($role)];
    }

    public static function remove(string $qq): bool
    {
        $deleted = self::store()->del(self::PREFIX . $qq) > 0;
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $deleted = ((int)$redis->del(self::PREFIX . $qq) > 0) || $deleted;
            } catch (\Throwable) {
                // 忽略
            }
        }
        return $deleted;
    }

    /** 面板顶部的统计 */
    public static function stats(): array
    {
        $rows = self::all();
        $byRole = [];
        $byLevel = [];
        foreach ($rows as $row) {
            $byRole[$row['role']] = ($byRole[$row['role']] ?? 0) + 1;
            $byLevel[$row['level']] = ($byLevel[$row['level']] ?? 0) + 1;
        }
        return ['total' => count($rows), 'by_role' => $byRole, 'by_level' => $byLevel];
    }

    /** 角色-能力矩阵（面板展示）：roles 给下拉、matrix 给表格、legacy 给映射说明 */
    public static function model(): array
    {
        $roles = [];
        foreach (MemberRoles::ROLES as $role => $meta) {
            $roles[$role] = $meta['label'];
        }
        return [
            'roles'        => $roles,
            'capabilities' => MemberRoles::CAPABILITIES,
            'matrix'       => MemberRoles::catalog(),
            'legacy'       => MemberRoles::LEGACY,
        ];
    }
}