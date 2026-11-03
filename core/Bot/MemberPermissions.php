<?php
namespace Core\Bot;

use Core\App;
use Core\Store\Store;

/**
 * QQ 成员权限（机器人使用权限，与后台登录账号无关）。
 *
 * 模型是「角色 + 能力」（见 MemberRoles）：存储里的 memberPermission.<QQ> 存角色名，
 * 角色再推导出对老接口友好的等级。v1 的 supervisor/director/manager 在读取时
 * 自动映射成新角色，不会被丢掉。
 */
final class MemberPermissions
{
    /** 面板可选角色（顺序 = 从高到低） */
    public const LEVELS = [
        'super'  => '主人',
        'owner'  => '所有者',
        'admin'  => '管理员',
        'editor' => '编辑',
        'member' => '普通成员',
    ];

    private static function store(): Store
    {
        return App::boot()->store;
    }

    /** @return array<int, array{qq:string, role:string, level:int, label:string, capabilities:string[]}> */
    public static function all(): array
    {
        $rows = [];
        foreach (self::store()->keys('memberPermission.') as $key) {
            $qq = substr($key, strlen('memberPermission.'));
            if ($qq === '' || !ctype_digit($qq)) {
                continue;
            }
            $role = MemberRoles::normalize((string)self::store()->get($key));
            $rows[] = [
                'qq'           => $qq,
                'role'         => $role,
                // 兼容旧前端：level 仍然给，但它是从角色推导出来的
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
        $value = self::store()->get('memberPermission.' . $qq);
        return $value === null ? null : MemberRoles::normalize((string)$value);
    }

    /** @return array{ok:bool,msg:string} */
    public static function set(string $qq, string $role): array
    {
        $qq = trim($qq);
        if (!ctype_digit($qq) || strlen($qq) < 5 || strlen($qq) > 12) {
            return ['ok' => false, 'msg' => 'QQ 号必须是 5-12 位数字'];
        }
        // 接受旧等级名（supervisor/director/manager/default），统一存成角色
        $normalized = MemberRoles::normalize($role);
        if (strtolower(trim($role)) !== $normalized && !isset(MemberRoles::LEGACY[strtolower(trim($role))])) {
            return ['ok' => false, 'msg' => '角色只能是 ' . implode(' / ', array_keys(self::LEVELS))];
        }
        self::store()->set('memberPermission.' . $qq, $normalized);
        return ['ok' => true, 'msg' => '已设置为 ' . MemberRoles::label($normalized)];
    }

    public static function remove(string $qq): bool
    {
        return self::store()->del('memberPermission.' . $qq) > 0;
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

    /** 面板需要的完整模型：角色 -> 能力 */
    public static function model(): array
    {
        return [
            'roles'        => self::LEVELS,
            'capabilities' => MemberRoles::CAPABILITIES,
            'matrix'       => MemberRoles::catalog(),
            'legacy'       => MemberRoles::LEGACY,
        ];
    }
}
