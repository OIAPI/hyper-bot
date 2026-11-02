<?php
namespace Controller\Attr;

use Attribute;

/**
 * 类注解：定义控制器的路由前缀
 * @example #[RoutePrefix('/api/user')]
 */
#[Attribute(Attribute::TARGET_CLASS)]
class RoutePrefix {
	public function __construct(
		public string $prefix // 路由前缀（如 '/api/user'）
	) {}
}
