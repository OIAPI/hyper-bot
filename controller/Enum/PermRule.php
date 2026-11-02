<?php
namespace Controller\Enum;

// 权限规则枚举：分层管理“主动角色”和“默认兜底”，消除逻辑歧义
enum PermRule: string
{
	// 第一类：主动配置的角色规则（对应原逻辑的独立角色）
	case SUPER = 'super';	   // 超级角色
	case SUPERVISOR = 'supervisor'; // 督导角色
	case OWNER = 'owner';	   // 所有者角色
	case DIRECTOR = 'director'; // 主管角色
	case MANAGER = 'manager';   // 经理角色
	case ADMIN = 'admin';	   // 管理员角色
	case EDITOR = 'editor';	 // 编辑角色（仅EDIT权限，值=2）

	// 第二类：默认兜底规则（仅VIEW权限，值=1，非独立角色）
	case DEFAULT = 'default';

	/**
	 * 核心：获取规则对应的权限值（完全基于PermBit，无硬编码）
	 */
	private function getPermValue(): int
	{
		return match ($this) {
			// 主动角色：按原逻辑组合权限位
			self::SUPER => PermBit::SUPER->value,
			self::SUPERVISOR => PermBit::combine([PermBit::VIEW, PermBit::EDIT, PermBit::MANAGE]),
			self::OWNER => PermBit::combine([PermBit::EDIT, PermBit::MANAGE]),
			self::DIRECTOR => PermBit::combine([PermBit::VIEW, PermBit::MANAGE]),
			self::MANAGER => PermBit::MANAGE->value,
			self::ADMIN => PermBit::combine([PermBit::VIEW, PermBit::EDIT]),
			self::EDITOR => PermBit::EDIT->value, // 主动角色：仅EDIT（值=2）
			
			// 默认规则：仅VIEW（值=1，与EDITOR不重复）
			self::DEFAULT => PermBit::VIEW->value,
		};
	}

	/**
	 * 对外：根据权限值/规则名获取PermRule（对齐原逻辑）
	 */
	public static function getByPerm(int|string $perm): self
	{
		// 字符串输入：优先匹配主动角色，无匹配则兜底
		if (is_string($perm)) {
			return self::tryFrom($perm) ?? self::DEFAULT;
		}

		// 整数输入：按“权限范围从大到小”匹配主动角色，无匹配则兜底
		$activeRoles = [
			self::SUPER, self::SUPERVISOR, self::OWNER,
			self::DIRECTOR, self::MANAGER, self::ADMIN, self::EDITOR
		];
		foreach ($activeRoles as $role) {
			if ($perm === $role->getPermValue()) {
				return $role;
			}
		}

		return self::DEFAULT;
	}

	/**
	 * 新增：判断是否为默认兜底规则（消除逻辑歧义）
	 * 业务中可快速区分“主动配置角色”和“默认权限”
	 */
	public function isDefault(): bool
	{
		return $this === self::DEFAULT;
	}

	/**
	 * 保留：对外获取权限值
	 */
	public function getValue(): int
	{
		return $this->getPermValue();
	}
}