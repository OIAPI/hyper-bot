<?php
namespace Controller\Trigger;

use Swoole\Coroutine;
use Utils\Logger;
use Utils\Env;
use Utils\Cache;
use Controller\Api;
use Controller\Server\RequestHandler;
use Controller\Router;
use InvalidArgumentException;
use RuntimeException;

/**
 * 入群申请触发类：处理入群申请事件的路由匹配与协程执行
 * @name RequestTrigger
 * @desc 控制类-入群申请（入群申请事件触发逻辑）
 * @time 2025-09-14
 * @help 可通过注解的getAttributes方法获取注解类信息
 */
class Request {
	protected ?RequestHandler $server;	  // 响应处理器（WS/常驻事件场景下可能为空）
	protected string $groupId;			 // 群ID（提前提取，避免重复调用）
	protected Api $api;					// API容器实例
	protected object $param;			   // 请求参数（提前校验，避免重复判断）
	protected const ROUTER_KEY = 'Controller\Attr\Request'; // 入群申请路由列表Key（常量化）
	use \Controller\Traits\Worker;


	/**
	 * 构造函数：初始化依赖→校验参数→执行核心逻辑
	 * @throws RuntimeException 依赖缺失时抛出
	 * @throws InvalidArgumentException 参数无效时抛出
	 */
	public function __construct() {
		// parent::__construct();

		// 1. 初始化核心依赖（提前校验，避免后续频繁判断）
		$this->initDependencies();

		// 2. 基础参数校验（失败直接终止流程）
		$this->validateBaseParam();

		// 3. 执行核心入群申请处理逻辑
		$this->handleJoinRequest();
	}


	/**
	 * 初始化核心依赖（Cache中的api/server/param）
	 * @throws RuntimeException 依赖缺失/无效时抛出
	 */
	protected function initDependencies(): void {
		$this->api = Cache::get('api');
		$this->server = Cache::get('server');
		$this->param = Cache::get('param');

		// 依赖有效性校验
		if (!$this->api instanceof Api) {
			throw new RuntimeException("API容器实例缺失（需初始化Cache::\$api）", 500);
		}
		// WebSocket / 常驻事件没有 HTTP 响应对象，这里仅告警不中断
		if (!$this->server instanceof RequestHandler) {
			\Utils\Logger::debug("[触发器] 无 HTTP 响应处理器（WS/常驻事件场景），继续以无响应模式处理");
		}
		if (empty($this->param) || !is_object($this->param)) {
			throw new RuntimeException("请求参数无效（非对象或为空）", 400);
		}
	}


	/**
	 * 基础参数校验（入群申请必须的群ID等字段）
	 * @throws InvalidArgumentException 参数缺失/无效时抛出
	 */
	protected function validateBaseParam(): void {
		// 1. 校验群ID（入群申请依赖群维度，必须非空）
		if (!isset($this->param->group_id) || empty(trim($this->param->group_id))) {
			throw new InvalidArgumentException("缺少必要参数：group_id（入群申请需指定群ID）", 400);
		}

		// 2. 提取并格式化群ID（避免空格问题）
		$this->groupId = trim($this->param->group_id);
	}


	/**
	 * 核心入群申请处理逻辑：群状态判断→路由列表获取→匹配执行
	 */
	protected function handleJoinRequest(): void {
		// 1. 校验群开启状态（未开启则终止流程）
		if (!$this->checkGroupStatus()) {
			Logger::debug(
				"入群申请处理终止 | 群未开启 | 群ID：{$this->groupId}"
			);
			return;
		}

		// 2. 获取入群申请路由列表（无规则则终止）
		$routeList = $this->getJoinRequestRouteList();
		if (empty($routeList)) {
			Logger::debug(
				"入群申请无匹配路由 | 路由列表为空 | 路由Key：" . self::ROUTER_KEY . " | 群ID：{$this->groupId}"
			);
			return;
		}

		// 3. 遍历路由并协程执行
		$this->executeRouteList($routeList);
	}


	/**
	 * 校验群开启状态（复用父类groupStatus方法，增加异常捕获）
	 * @return bool 群是否开启（true=开启，false=关闭）
	 */
	protected function checkGroupStatus(): bool {
		try {
			$groupStatus = $this->groupStatus($this->groupId);
			return $groupStatus === true;
		} catch (\Exception $e) {
			Logger::error(
				"群状态查询失败 | 群ID：{$this->groupId} | 原因：{$e->getMessage()}"
			);
			return false; // 异常时默认视为关闭，避免误执行入群逻辑
		}
	}


	/**
	 * 获取入群申请路由列表（从router::list()提取，增加异常捕获）
	 * @return array 路由规则列表（空数组=无规则）
	 */
	protected function getJoinRequestRouteList(): array {
		try {
			$allRoutes = Router::list();
			return $allRoutes[self::ROUTER_KEY] ?? [];
		} catch (\Exception $e) {
			Logger::error(
				"入群申请路由列表获取失败 | 路由Key：" . self::ROUTER_KEY . " | 原因：{$e->getMessage()}"
			);
			return [];
		}
	}


	/**
	 * 遍历路由列表，协程执行对应控制器方法（捕获异常，确保流程稳定）
	 * @param array $routeList 入群申请路由列表
	 */
	protected function executeRouteList(array $routeList): void {
		foreach ($routeList as $route) {
			// 1. 路由规则必要字段校验（避免字段缺失导致报错）
			if (!$this->validateRouteRule($route)) {
				continue;
			}

			// 2. 协程执行路由方法
			$this->executeRouteInCoroutine($route);
		}
	}


	/**
	 * 校验路由规则字段是否完整（class/func为必须）
	 * @param array $route 路由规则
	 * @return bool 字段是否完整（true=完整，false=缺失）
	 */
	protected function validateRouteRule(array $route): bool {
		$requiredFields = ['class', 'func']; // 入群申请路由必要字段
		foreach ($requiredFields as $field) {
			if (!isset($route[$field]) || empty(trim($route[$field]))) {
				Logger::warning(
					"入群申请路由跳过 | 字段缺失 | 缺失字段：{$field} | 路由数据：" . json_encode($route, 256)
				);
				return false;
			}
		}
		return true;
	}


	/**
	 * 协程执行路由方法（捕获所有异常/错误，避免协程崩溃）
	 * @param array $route 路由规则
	 */
	protected function executeRouteInCoroutine(array $route): void {
		$routeClass = trim($route['class']);
		$routeFunc = trim($route['func']);

		Coroutine::create(function () use ($route, $routeClass, $routeFunc) {
			try {
				// 1. 检查控制器类是否存在
				if (!class_exists($routeClass)) {
					throw new RuntimeException("控制器不存在：{$routeClass}");
				}

				// 2. 实例化控制器（传入路由参数）
				$controller = new $routeClass($route);

				// 3. 检查控制器方法是否存在
				if (!method_exists($controller, $routeFunc)) {
					throw new RuntimeException("控制器方法不存在：{$routeClass}->{$routeFunc}()");
				}

				// 4. 执行方法并记录成功日志
				$controller->{$routeFunc}();
				Logger::success(
					"入群申请路由执行成功 | 群ID：{$this->groupId} | 路由：{$routeClass}->{$routeFunc}()"
				);

			} catch (\Throwable $e) { // 捕获所有异常/错误（含Error/CompileError等）
				Logger::error(
					"入群申请路由执行失败 | 群ID：{$this->groupId} | 路由：{$routeClass}->{$routeFunc}() | " .
					"错误类型：" . get_class($e) . " | 原因：{$e->getMessage()} | " .
					"文件：{$e->getFile()} | 行号：{$e->getLine()}"
				);
				Logger::error("错误堆栈：{$e->getTraceAsString()}");
			}
		});
	}
}