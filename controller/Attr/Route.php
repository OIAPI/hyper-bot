<?php
namespace Controller\Attr;

use Attribute;

/**
 * 方法注解：定义方法的路由规则
 * @example #[Route('GET', '/info')]
 * @example #[Route('ANY', '/detail/:id')]
 */
#[Attribute(Attribute::TARGET_METHOD)]
class Route {
	// 支持的请求方法
	private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'ANY'];

	public function __construct(
		public string $method = 'GET', // 请求方法（GET/POST/ANY等）
		public ?string $path = null	// 路由路径（如 '/info'、'/detail/:id'）
	) {
		$this->method = strtoupper($this->method);
		// 校验方法合法性
		if (!in_array($this->method, self::ALLOWED_METHODS)) {
			throw new \InvalidArgumentException("不支持的请求方法：{$this->method}");
		}
	}
}
