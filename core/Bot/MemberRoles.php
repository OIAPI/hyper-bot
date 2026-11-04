<?php
namespace Core\Bot;

/**
 * QQ 成员角色（机器人侧 RBAC）。
 *
 * 为什么不再用 v1 的 8 档等级：manager(4) < director(5) < owner(6) 这种排序，
 * 没人说得清「经理」和「主管」差在哪；插件真正关心的其实是「能不能做某件事」。
 *
 * 这里把权限拆成「角色 -> 能力集合」，等级只作为对插件的兼容视图：
 *   - 新代码用 can() 判断具体能力；
 *   - 老插件里的 #[Rule(permission: PermRule::OWNER)] / comparePermission() 仍然可用，
 *     因为角色能推导出一个等级（见 level()）。
 *
 * 存储没有变：memberPermission.<QQ> 存角色名。v1 的 supervisor/director/manager
 * 在读取时按 LEGACY 映射到新角色，不会被当成未知值丢掉。
 */
final class MemberRoles
{
    /** 能力清单：插件与面板共用同一套名字 */
    public const CAPABILITIES = [
        'command.basic'   => '使用公开命令',
        'command.manage'  => '使用群管理命令（禁言 / 踢人 / 撤回）',
        'group.config'    => '修改群配置与群内插件开关',
        'member.manage'   => '调整成员的机器人权限',
        'plugin.manage'   => '安装 / 重载插件',
        'system.manage'   => '系统级操作（清缓存 / 重启协议）',
    ];

    /**
     * 角色定义：label 给人看，level 给老接口用，capabilities 给新代码用。
     * 数值越大权限越高，与 Dispatcher 的等级比较保持一致。
     */
    public const ROLES = [
        'super' => [
            'label' => '主人',
            'level' => 15,
            'capabilities' => ['*'],
        ],
        'owner' => [
            'label' => '所有者',
            'level' => 6,
            'capabilities' => ['command.basic', 'command.manage', 'group.config', 'member.manage', 'plugin.manage'],
        ],
        'admin' => [
            'label' => '管理员',
            'level' => 3,
            'capabilities' => ['command.basic', 'command.manage', 'group.config'],
        ],
        'editor' => [
            'label' => '编辑',
            'level' => 2,
            'capabilities' => ['command.basic', 'command.manage'],
        ],
        'member' => [
            'label' => '普通成员',
            'level' => 1,
            'capabilities' => ['command.basic'],
        ],
    ];

    /** v1 的等级名 -> 新角色（仅读取时映射，不主动改写存储） */
    public const LEGACY = [
        'supervisor' => 'owner',
        'director'   => 'admin',
        'manager'    => 'admin',
        'default'    => 'member',
        'member'     => 'member',
    ];

    /** 归一化角色名；未知值一律按普通成员处理（最小权限） */
    public static function normalize(string $role): string
    {
        $role = strtolower(trim($role));
        if (isset(self::ROLES[$role])) {
            return $role;
        }
        if (isset(self::LEGACY[$role])) {
            return self::LEGACY[$role];
        }
        // 有些 v1 部署存的是等级数字（2/3/6/15），按等级反查角色
        if ($role !== '' && ctype_digit($role)) {
            $level = (int)$role;
            foreach (self::ROLES as $name => $meta) {
                if ((int)$meta['level'] === $level) {
                    return $name;
                }
            }
            // supervisor 7 / director 5 / manager 4 这类没有对应角色的旧等级
            return match (true) {
                $level >= 6 => 'owner',
                $level >= 3 => 'admin',
                $level >= 2 => 'editor',
                default     => 'member',
            };
        }
        return 'member';
    }

    /** 是否是本模型里定义过的角色（写入前校验用，不接受未知值） */
    public static function exists(string $role): bool
    {
        return isset(self::ROLES[strtolower(trim($role))]);
    }

    /** 是否可以当作输入接受（新角色名 / v1 旧名 / 纯数字等级） */
    public static function known(string $role): bool
    {
        $role = strtolower(trim($role));
        return $role !== '' && (isset(self::ROLES[$role]) || isset(self::LEGACY[$role]) || ctype_digit($role));
    }

    /** 能力矩阵：角色 -> {label, level, capabilities}（面板展示用） */
    public static function matrix(): array
    {
        return self::catalog();
    }

    public static function level(string $role): int
    {
        return self::ROLES[self::normalize($role)]['level'] ?? 1;
    }

    public static function label(string $role): string
    {
        return self::ROLES[self::normalize($role)]['label'] ?? '普通成员';
    }

    /** @return string[] 展开后的能力名（super 是全部） */
    public static function capabilities(string $role): array
    {
        $caps = self::ROLES[self::normalize($role)]['capabilities'] ?? [];
        return $caps === ['*'] ? array_keys(self::CAPABILITIES) : $caps;
    }

    public static function can(string $role, string $capability): bool
    {
        $capability = strtolower(trim($capability));
        return in_array($capability, self::capabilities($role), true);
    }

    /** 面板用的目录：角色 -> {label, level, capabilities} */
    public static function catalog(): array
    {
        $out = [];
        foreach (self::ROLES as $role => $meta) {
            $out[$role] = [
                'label'        => $meta['label'],
                'level'        => $meta['level'],
                'capabilities' => self::capabilities($role),
            ];
        }
        return $out;
    }
}