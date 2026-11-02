<?php
namespace Controller\Enum;

// 权限位枚举：每个case对应1个独立二进制位
enum PermBit: int
{
	// 基础权限（可根据实际需求扩展）
	case VIEW = 1 << 0;	// 0001 (1)：查看
	case EDIT = 1 << 1;	// 0010 (2)：编辑
	case MANAGE = 1 << 2;  // 0100 (4)：管理
	case SUPER = 1 << 3;   // 1000 (8)：超级
	public function description(): string
	{
		return match ($this) {
			self::VIEW => '基础查看权限（默认拥有）',
			self::EDIT => '内容编辑权限',
			self::MANAGE => '角色与权限管理权限',
			self::SUPER => '系统级超级管控权限',
		};
	}
	// 辅助方法：将多个PermBit组合成整数（用于快速生成权限值）
	public static function combine(array $bits): int
	{
		$total = 0;
		foreach ($bits as $bit) {
			if ($bit instanceof self) {
				$total |= $bit->value;
			}
		}
		return $total;
	}
}

// RBAC适配层：关联位运算枚举与角色
class PermissionAdapter
{
    // 核心映射：RBAC角色 -> 位运算权限枚举组合
    private array $roleToPermBits = [
        '游客' => [PermBit::VIEW],
        '编辑者' => [PermBit::VIEW, PermBit::EDIT],
        '管理员' => [PermBit::VIEW, PermBit::EDIT, PermBit::MANAGE],
        '超级管理员' => [PermBit::VIEW, PermBit::EDIT, PermBit::MANAGE, PermBit::SUPER],
    ];

    // 用户-角色映射（未来可迁移到数据库）
    private array $userRoles = [
        1001 => ['编辑者'],
        1002 => ['超级管理员'],
    ];

    /**
     * 适配方法1：通过RBAC角色获取位运算权限值
     * 供现有系统中需要权限值的逻辑调用
     */
    public function getPermValueByRole(string $role): int
    {
        return $roleToPermBits[$role] 
            ? PermBit::combine($roleToPermBits[$role]) 
            : 0;
    }

    /**
     * 适配方法2：通过用户ID获取位运算权限值
     * 替代原有直接给用户分配权限值的逻辑
     */
    public function getUserPermValue(int $userId): int
    {
        if (!isset($this->userRoles[$userId])) return 0;

        // 合并用户所有角色对应的权限枚举
        $allPermBits = [];
        foreach ($this->userRoles[$userId] as $role) {
            $allPermBits = array_merge($allPermBits, $this->roleToPermBits[$role] ?? []);
        }
        return PermBit::combine(array_unique($allPermBits));
    }

    /**
     * 适配方法3：RBAC风格的权限校验
     * 可直接用于新业务逻辑，未来无需修改
     */
    public function checkPermission(int $userId, PermBit $permBit): bool
    {
        $userPermValue = $this->getUserPermValue($userId);
        return ($userPermValue & $permBit->value) !== 0;
    }

    // 预留RBAC核心方法，与之前的实现一致
    public function assignRole(int $userId, string $role): bool
    {
        if (!isset($this->roleToPermBits[$role]) || in_array($role, $this->userRoles[$userId] ?? [])) {
            return false;
        }
        $this->userRoles[$userId][] = $role;
        return true;
    }
}

// ------------------------------
// 现有系统改造示例（最小侵入）
// ------------------------------
// 1. 原有逻辑：直接使用权限值
/*
$oldUserPerm = 3; // VIEW|EDIT
if (($oldUserPerm & PermBit::EDIT->value) !== 0) {
    // 执行编辑操作
}

// 2. 改造后：通过适配层获取权限值，不改动原有校验逻辑
$adapter = new PermissionAdapter();
$newUserPerm = $adapter->getUserPermValue(1001); // 1001是编辑者，权限值为3
if (($newUserPerm & PermBit::EDIT->value) !== 0) {
    // 执行编辑操作（逻辑完全不变）
}

// 3. 新业务逻辑：直接使用RBAC校验
if ($adapter->checkPermission(1001, PermBit::MANAGE)) {
    // 执行管理操作
}
*/