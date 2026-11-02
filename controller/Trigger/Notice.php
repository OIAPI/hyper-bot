<?php
namespace Controller\Trigger;

use Swoole\Coroutine;
use Utils\Logger;
use Utils\Env;
use Utils\Cache;
use Controller\Api;
use Controller\Router;
use Controller\Server\RequestHandler;
use InvalidArgumentException;
use RuntimeException;

/**
 * 事件触发类：处理群通知事件的路由匹配与协程执行
 * @name Notice
 * @desc 控制类-事件（群通知触发逻辑）
 * @time 2025-09-14
 * @help 可通过注解的getAttributes方法获取注解类信息
 */
class Notice {
	protected ?RequestHandler $server;	  // 响应处理器（WS/常驻事件场景下可能为空）
	protected string $noticeType;		  // 通知类型（提前提取，避免重复调用）
	protected Api $api;					// API容器实例
	protected object $param;			   // 请求参数（提前校验，避免重复判断）
	protected const ROUTER_KEY = 'Controller\Attr\Notice'; // 路由列表Key（常量化）
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

		// 3. 执行核心事件处理逻辑
		$this->handleNotice();
	}


	/**
	 * 初始化核心依赖（Cache中的api/server/param）
	 * @throws RuntimeException 依赖缺失时抛出
	 */
	protected function initDependencies(): void {
		$this->api = Cache::$api;
		$this->server = Cache::$server;
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
	 * 基础参数校验（通知类型、群ID等必要字段）
	 * @throws InvalidArgumentException 参数缺失/无效时抛出
	 */
	protected function validateBaseParam(): void {
		// 1. 校验群ID（通知事件依赖群维度）
		if (!isset($this->param->group_id) || empty(trim($this->param->group_id))) {
			throw new InvalidArgumentException("缺少必要参数：group_id（群通知需指定群ID）", 400);
		}

		// 2. 校验通知类型（notice_type）
		if (!isset($this->param->notice_type) || empty(trim($this->param->notice_type))) {
			throw new InvalidArgumentException("缺少必要参数：notice_type（通知类型未指定）", 400);
		}

		// 3. 提取并格式化通知类型（避免空格/大小写问题）
		$this->noticeType = strtolower(trim($this->param->notice_type));
	}


	/**
	 * 核心事件处理逻辑：群状态判断→配置开关校验→路由匹配→协程执行
	 */
	protected function handleNotice(): void {
		// 1. 校验群开启状态（未开启则终止流程）
		if (!$this->checkGroupStatus()) {
			Logger::debug(
				"群通知处理终止 | 群未开启 | 群ID：{$this->param->group_id} | 通知类型：{$this->noticeType}"
			);
			return;
		}

		// 2. 校验配置开关（是否启用该类型通知）
		if (!$this->checkNoticeConfigSwitch()) {
			Logger::warning(
				"群通知处理终止 | 配置未启用 | 通知类型：{$this->noticeType} | " .
				"配置路径：notice.{$this->noticeType}（需设置为1启用）"
			);
			return;
		}

		// 3. 获取通知路由列表（无规则则终止）
		$routeList = $this->getNoticeRouteList();
		if (empty($routeList)) {
			Logger::debug(
				"群通知无匹配路由 | 路由列表为空 | 路由Key：" . self::ROUTER_KEY . " | 通知类型：{$this->noticeType}"
			);
			return;
		}

		// 4. 匹配路由并协程执行
		$this->matchAndExecuteRoute($routeList);
	}


	/**
	 * 校验群开启状态（复用父类groupStatus方法）
	 * @return bool 群是否开启（true=开启，false=关闭）
	 */
	protected function checkGroupStatus(): bool {
		try {
			$groupStatus = $this->groupStatus($this->param->group_id);
			return $groupStatus === true;
		} catch (\Exception $e) {
			Logger::error(
				"群状态查询失败 | 群ID：{$this->param->group_id} | 原因：{$e->getMessage()}"
			);
			return false; // 异常时默认视为关闭，避免误执行
		}
	}


	/**
	 * 校验配置开关（notice.{type}是否设置为1）
	 * @return bool 配置是否启用（true=启用，false=禁用）
	 */
	protected function checkNoticeConfigSwitch(): bool {
		$configKey = "notice.{$this->noticeType}";
		$configValue = Env::get($configKey, 0); // 默认禁用（0）

		// 配置值需严格为1（避免非数字值导致误判）
		return is_numeric($configValue) && (int)$configValue === 1;
	}


	/**
	 * 获取通知路由列表（从Router::list()提取）
	 * @return array 路由规则列表（空数组=无规则）
	 */
	protected function getNoticeRouteList(): array {
		try {
			$allRoutes = Router::list();
			return $allRoutes[self::ROUTER_KEY] ?? [];
		} catch (\Exception $e) {
			Logger::error(
				"路由列表获取失败 | 路由Key：" . self::ROUTER_KEY . " | 原因：{$e->getMessage()}"
			);
			return [];
		}
	}


	/**
	 * 匹配路由规则并协程执行对应方法
	 * @param array $routeList 通知路由列表
	 */
	protected function matchAndExecuteRoute(array $routeList): void {
		foreach ($routeList as $route) {
			// 1. 路由规则必要字段校验（避免字段缺失导致报错）
			if (!$this->validateRouteRule($route)) {
				continue;
			}

			// 2. 匹配通知类型（路由match字段与当前通知类型一致）
			if (strtolower(trim($route['match'])) !== $this->noticeType) {
				continue;
			}

			// 3. 协程执行路由方法（捕获所有异常，避免协程崩溃）
			$this->executeRouteInCoroutine($route);
		}
	}


	/**
	 * 校验路由规则字段是否完整
	 * @param array $route 路由规则
	 * @return bool 字段是否完整（true=完整，false=缺失）
	 */
	protected function validateRouteRule(array $route): bool {
		$requiredFields = ['class', 'func', 'match']; // 路由必要字段
		foreach ($requiredFields as $field) {
			if (!isset($route[$field]) || empty(trim($route[$field]))) {
				Logger::warning(
					"路由规则跳过 | 字段缺失 | 缺失字段：{$field} | 路由数据：" . json_encode($route, 256)
				);
				return false;
			}
		}
		return true;
	}

	/**
	 * 协程执行路由方法（捕获异常，确保流程稳定）
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
					"通知路由执行成功 | 通知类型：{$this->noticeType} | " .
					"路由：{$routeClass}->{$routeFunc}() | 群ID：{$this->param->group_id}"
				);

			} catch (\Throwable $e) { // 捕获所有异常/错误
				Logger::error(
					"通知路由执行失败 | 通知类型：{$this->noticeType} | " .
					"路由：{$routeClass}->{$routeFunc}() | 错误类型：" . get_class($e) . " | " .
					"原因：{$e->getMessage()} | 文件：{$e->getFile()} | 行号：{$e->getLine()}"
				);
				Logger::error("错误堆栈：{$e->getTraceAsString()}");
			}
		});
	}
}
