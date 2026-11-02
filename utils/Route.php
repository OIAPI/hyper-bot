<?php
namespace Utils;

use Controller\Attr\Route as RouteAnnotation;
use Controller\Attr\RoutePrefix;
use App\BaseController;
use ReflectionClass;
use ReflectionMethod;
use InvalidArgumentException;
use RuntimeException;

/**
 * 完善版路由类：支持注解注册、动态路由、ANY方法、精确/模糊匹配
 */
class Route {
	// 存储路由规则：[请求方法 => [路由路径 => 回调函数]]
	// 路径为标准化格式（无首尾斜杠），支持静态路径和动态路径（如user/:id）
	private static array $routes = [
		'GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => [], 'ANY' => []
	];
	private static $init = false;
	// 支持的HTTP方法白名单
	private const ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'DELETE', 'ANY'];


	/**
	 * 注册GET路由
	 * @param string $path 路由路径（如 '/api/data'、'/user/:id'）
	 * @param callable $callback 回调函数（闭包/[$class, $method]/函数名）
	 */
	public static function get(string $path, callable $callback): void {
		self::registerRoute('GET', $path, $callback);
	}

	/**
	 * 注册POST路由
	 * @param string $path 路由路径
	 * @param callable $callback 回调函数
	 */
	public static function post(string $path, callable $callback): void {
		self::registerRoute('POST', $path, $callback);
	}

	/**
	 * 注册PUT路由
	 * @param string $path 路由路径
	 * @param callable $callback 回调函数
	 */
	public static function put(string $path, callable $callback): void {
		self::registerRoute('PUT', $path, $callback);
	}

	/**
	 * 注册DELETE路由
	 * @param string $path 路由路径
	 * @param callable $callback 回调函数
	 */
	public static function delete(string $path, callable $callback): void {
		self::registerRoute('DELETE', $path, $callback);
	}

	/**
	 * 注册ANY路由（兼容所有HTTP方法）
	 * @param string $path 路由路径
	 * @param callable $callback 回调函数
	 */
	public static function any(string $path, callable $callback): void {
		self::registerRoute('ANY', $path, $callback);
	}

	/**
	 * 通用路由注册（私有核心方法）
	 * 处理路径标准化、方法校验、回调合法性检查
	 */
	private static function registerRoute(string $method, string $path, callable $callback): void {
		// 1. 校验请求方法
		$method = strtoupper($method);
		if (!in_array($method, self::ALLOWED_METHODS)) {
			throw new InvalidArgumentException(
				"不支持的请求方法：{$method}，仅允许：" . implode(',', self::ALLOWED_METHODS)
			);
		}

		// 2. 路径标准化（去除首尾斜杠，统一格式）
		$normalizedPath = self::normalizePath($path);

		// 3. 校验回调合法性
		if (!is_callable($callback)) {
			throw new InvalidArgumentException("路由回调不可执行：{$normalizedPath}");
		}

		// 4. 存储路由（覆盖同名路由，后注册的优先级高）
		self::$routes[$method][$normalizedPath] = $callback;
		Logger::debug("[路由注册成功] {$method} → /{$normalizedPath}");
	}

	/**
	 * 路由匹配（支持精确匹配+动态路由匹配，返回回调+路由参数）
	 * @param string $method 请求方法（GET/POST等）
	 * @param string $path 请求路径（如 '/api/user/123'）
	 * @return array|null [回调函数, 路由参数数组]，未匹配返回null
	 */
	public static function match(string $method, string $path): ?array {
		$method = strtoupper($method);
		$normalizedPath = self::normalizePath($path);
		// 1. 优先匹配当前方法的路由（GET/POST等）
		if (isset(self::$routes[$method])) {
			// 1.1 先尝试精确匹配（性能优先）
			if (isset(self::$routes[$method][$normalizedPath])) {
				Logger::debug("[路由精确匹配] {$method} /{$normalizedPath}");
				return [self::$routes[$method][$normalizedPath], []];
			}

			// 1.2 精确匹配失败，尝试动态路由匹配（如 /user/:id）
			$dynamicMatch = self::matchDynamicRoute(self::$routes[$method], $normalizedPath);
			if ($dynamicMatch) {
				return $dynamicMatch;
			}
		}

		// 2. 当前方法无匹配，尝试ANY路由
		if (!empty(self::$routes['ANY'])) {
			if (isset(self::$routes['ANY'][$normalizedPath])) {
				Logger::debug("[ANY路由精确匹配] /{$normalizedPath}");
				return [self::$routes['ANY'][$normalizedPath], []];
			}

			$anyDynamicMatch = self::matchDynamicRoute(self::$routes['ANY'], $normalizedPath);
			if ($anyDynamicMatch) {
				Logger::debug("[ANY路由动态匹配] /{$normalizedPath}");
				return $anyDynamicMatch;
			}
		}

		// 3. 无匹配路由
		Logger::debug("[路由匹配失败] {$method} /{$normalizedPath}");
		return null;
	}

	/**
	 * 动态路由匹配（私有辅助方法）
	 * 匹配格式如 /user/:id → 提取参数 id=xxx
	 */
	private static function matchDynamicRoute(array $routeList, string $requestPath): ?array {
		foreach ($routeList as $routePath => $callback) {
			// 跳过静态路由（不含 : 参数标记）
			if (strpos($routePath, ':') === false) {
				continue;
			}

			// 构建动态路由正则（:param → 匹配非/字符）
			$routeRegex = preg_replace_callback(
				'/:([a-zA-Z0-9_]+)/',
				fn($matches) => '(?P<' . $matches[1] . '>[^/]+)', // 命名分组，便于提取参数
				$routePath
			);

			// 执行正则匹配
			if (preg_match("~^{$routeRegex}$~", $requestPath, $matches)) {
				// 提取命名分组参数（过滤完整匹配结果）
				$routeParams = [];
				foreach ($matches as $key => $value) {
					if (is_string($key) && $key !== $requestPath) {
						$routeParams[$key] = $value;
					}
				}

				Logger::debug("[动态路由匹配成功] 路径：{$routePath}，参数：" . json_encode($routeParams));
				return [$callback, $routeParams];
			}
		}

		return null;
	}

	/**
	 * 通过注解自动注册控制器路由
	 * @param string|array $controllerClasses 控制器类名（单个类名或类名数组）
	 */
	public static function registerByAnnotation(string|array $controllerClasses): void {
		if(self::$init === true) return;
		$controllerClasses = is_array($controllerClasses) ? $controllerClasses : [$controllerClasses];
	
		foreach ($controllerClasses as $controllerClass) {
			// 1. 校验控制器类存在性
			if (!class_exists($controllerClass)) {
				throw new InvalidArgumentException("控制器不存在：{$controllerClass}");
			}
			$refClass = new ReflectionClass($controllerClass);
	
			// 2. 校验控制器是否继承 App\BaseController
			if (!$refClass->isSubclassOf(BaseController::class)) {
				throw new RuntimeException(
					"控制器 {$controllerClass} 未继承 App\BaseController，不允许注解路由注册"
				);
			}
	
			// 3. 解析类注解：RoutePrefix（必填，直接获取，无需判断空）
			$classAttrs = $refClass->getAttributes(RoutePrefix::class);
			if (empty($classAttrs)) {
				throw new RuntimeException("控制器 {$controllerClass} 缺少必填注解：#[RoutePrefix]");
			}
			$prefixAttr = $classAttrs[0]->newInstance();
			$prefix = trim($prefixAttr->prefix, '/'); // 标准化前缀（去除首尾斜杠）
			Logger::debug("[注解解析] 控制器 {$controllerClass} 前缀：/{$prefix}");
	
			// 4. 遍历控制器公共方法，解析方法注解
			foreach ($refClass->getMethods(ReflectionMethod::IS_PUBLIC) as $refMethod) {
				// 跳过继承自 BaseController 的方法（修复日志错误，改为debug级别）
				if ($refMethod->getDeclaringClass()->getName() === BaseController::class) {
					Logger::debug("[路由跳过] 方法 {$refMethod->getName()} 继承自 BaseController，跳过注册");
					continue;
				}
	
				// 解析方法注解：Route（存在才处理，无注解直接跳过）
				$methodAttrs = $refMethod->getAttributes(RouteAnnotation::class);
				if (empty($methodAttrs)) {
					Logger::debug("[路由跳过] 方法 {$refMethod->getName()} 未添加 #[Route] 注解，跳过注册");
					continue;
				}
	
				// 实例化注解对象，获取路由配置
				$routeAttr = $methodAttrs[0]->newInstance();
				$httpMethod = $routeAttr->method;
				$methodPath = trim($routeAttr->path ?? '', '/'); // 原始路径（可能为空）
				// Logger::debug("[注解解析] 方法 {$refMethod->getName()} 原始路径：" . ($methodPath ?: '空'));
	
				// 核心修复：空path仅用方法名（不再拼接类名，避免多重路径冗余）
				if (empty($methodPath)) {
					$methodPath = $refMethod->getName(); // 空路径 → 直接使用方法名
					Logger::debug("[路径生成] 方法 {$refMethod->getName()} 空路径，自动生成路径：{$methodPath}");
				}
	
				// 拼接完整路由路径（仅前缀+方法名/手动路径，无类名，避免多重路径）
				$fullPath = $prefix ? "{$prefix}/{$methodPath}" : $methodPath;
				$fullPath = $fullPath ?: ''; // 极端场景：前缀和路径都为空 → 根路由
				Logger::debug("[路由拼接] 完整路径：/{$fullPath}");
	
				// 5. 注册路由：回调为 [控制器实例, 方法名]
				try {
					$controllerInstance = $refClass->newInstance(); // 无参构造实例化
					$callback = [$controllerInstance, $refMethod->getName()];
					self::registerRoute($httpMethod, "/{$fullPath}", $callback);
				} catch (\Exception $e) {
					throw new RuntimeException(
						"控制器 {$controllerClass} 方法 {$refMethod->getName()} 注册失败：{$e->getMessage()}"
					);
				}
			}
	
			Logger::success("[路由注册完成] 控制器 {$controllerClass}（前缀：/{$prefix}）所有路由已加载");
			self::$init = true;
		}
	}
	
	/**
	 * 路径标准化（去除首尾斜杠，统一格式）
	 * @param string $path 原始路径
	 * @return string 标准化后的路径
	 */
	private static function normalizePath(string $path): string {
		// 处理根路径（空字符串或仅斜杠 → 标准化为空）
		$normalized = trim($path, '/');
		return $normalized === '' ? '' : $normalized;
	}

	/**
	 * 清空所有路由（测试/重置场景用）
	 */
	public static function clear(): void {
		self::$routes = array_fill_keys(self::ALLOWED_METHODS, []);
		Logger::debug("[路由重置] 所有已注册路由已清空");
	}

	/**
	 * 获取已注册的路由列表（调试用）
	 * @return array 路由规则数组
	 */
	public static function getRoutes(): array {
		return self::$routes;
	}

	/**
	 * 检查路由是否已存在（避免重复注册）
	 * @param string $method 请求方法
	 * @param string $path 路由路径
	 * @return bool 是否存在
	 */
	public static function hasRoute(string $method, string $path): bool {
		$method = strtoupper($method);
		$normalizedPath = self::normalizePath($path);
		return isset(self::$routes[$method][$normalizedPath]);
	}
}
