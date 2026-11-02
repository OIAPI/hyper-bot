<?php
namespace Controller\Attr;
use Attribute;
use Controller\Enum\PermRule;

#[Attribute]
class Rule {
	/**
	 * @desc 注解类
	 * @param string $match 正则匹配
	 * @param ?string $name 菜单显示名
	 * @param string $pattern 类型，群group 好友private
	 * @param string $permission 权限 默认default
	 */
	public function __construct(public string $match = '/.*/', public ?string $name = null, public string $pattern = 'group', public string | int | PermRule $permission = 'default') {
		
	}
}