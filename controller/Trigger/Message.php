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
 * 消息触发类：处理消息路由匹配、权限校验、协程执行逻辑
 */
class Message {
	protected object $param;			   // 请求参数（提前校验，避免重复判断）
	protected ?RequestHandler $server;	  // 响应处理器（WS/常驻事件场景下可能为空）
	protected ?\Redis $redis = null;	   // Redis实例（避免重复获取）
	protected Api $api;					// API容器实例
	protected const OFFICIAL_ROBOT_PREFIX = '3889'; // 官方机器人ID前缀（可配置化）
	use \Controller\Traits\Worker;


	/**
	 * 构造函数：初始化依赖，校验基础参数
	 * @throws RuntimeException 依赖缺失/参数无效时抛出
	 */
	public function __construct() {
		// 1. 初始化核心依赖（提前校验，避免后续频繁判断）
		$this->initDependencies();

		// 2. 基础参数校验（失败直接抛出异常）
		$this->validateBaseParam();

		// 3. 初始化Redis（统一选择数据库）
		$this->initRedis();

		// 4. 执行核心逻辑
		$this->handleMessage();
	}


	/**
	 * 初始化核心依赖（Cache中的api/server/param）
	 * @throws RuntimeException 依赖缺失时抛出
	 */
	protected function initDependencies(): void {
		$this->api = Cache::get('api');
		$this->server = Cache::get('server');
		$this->param = Cache::get('param');

		// 依赖缺失校验
		if (!$this->api instanceof Api) {
			throw new RuntimeException("API容器实例缺失", 500);
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
	 * 初始化Redis实例（统一选择数据库，避免重复调用）
	 */
	protected function initRedis(): void {
		try {
			$this->redis = $this->api->redis;
			if ($this->redis) {
				$dbIndex = Env::get('Redis.select', 0);
				$this->redis->select($dbIndex);
			}
		} catch (\Exception $e) {
			Logger::error("Redis初始化失败：{$e->getMessage()}");
			$this->redis = null;
		}
	}


	/**
	 * 基础参数校验（消息类型、必要字段）
	 * @throws InvalidArgumentException 参数缺失/无效时抛出
	 */
	protected function validateBaseParam(): void {
		$messageType = strtolower(Env::get('message.type', ''));

		// 1. 校验消息类型（仅支持group/protected/all）
		if (!in_array($messageType, ['group', 'protected', 'all'], true)) {
			throw new InvalidArgumentException("不支持的消息类型：{$messageType}", 400);
		}

		// 2. 按消息类型校验必要字段
		$requiredFields = match ($messageType) {
			'group' => ['group_id'],		  // 群消息：必须有group_id
			'protected' => ['user_id'],		 // 私聊消息：必须有user_id，且无group_id
			'all' => ['group_id', 'user_id'], // 所有消息：两个字段都必须有
		};

		// 校验必要字段存在且非空
		foreach ($requiredFields as $field) {
			if (!isset($this->param->{$field}) || empty(trim($this->param->{$field}))) {
				throw new InvalidArgumentException("缺少必要参数：{$field}", 400);
			}
		}

		// 私聊消息额外校验：禁止存在group_id
		if ($messageType === 'protected' && isset($this->param->group_id) && !empty($this->param->group_id)) {
			throw new InvalidArgumentException("私聊消息不允许包含group_id", 400);
		}

		// 校验raw_message（路由匹配依赖）
		if (!isset($this->param->raw_message) || empty(trim($this->param->raw_message))) {
			throw new InvalidArgumentException("缺少消息内容：raw_message", 400);
		}
	}


	/**
	 * 核心消息处理逻辑：过滤机器人→获取权限→路由匹配→协程执行
	 */
	protected function handleMessage(): void {
		// 1. 过滤官方机器人（直接返回，不处理）
		if ($this->isOfficialRobot($this->param->user_id)) {
			Logger::debug("过滤官方机器人消息：用户ID {$this->param->user_id}");
			return;
		}
		if ($this->param->user_id === $this->param->self_id) {
			Logger::debug("过滤自身消息： {$this->param->user_id}");
			return;
		}
		// 2. 发送初始成功响应（原逻辑保持）
		// $this->server->sendSuccess(['status' => 'success']);

		// 3. 获取用户权限与群状态
		$userPermission = $this->getUserPermission();
		$groupStatus = $this->getGroupStatus();

		// 4. 遍历路由规则，匹配并执行
		$this->matchAndExecuteRouter($userPermission, $groupStatus);
	}


	/**
	 * 判断是否为官方机器人（前缀匹配）
	 * @param string $userId 用户ID
	 * @return bool 是否为官方机器人
	 */
	protected function isOfficialRobot(string $userId): bool {
		return str_starts_with(trim($userId), self::OFFICIAL_ROBOT_PREFIX);
	}


	/**
	 * 获取用户权限（从Redis读取，默认default）
	 * @return int 权限等级（数字，便于比较）
	 */
	protected function getUserPermission(): int {
		$userId = trim($this->param->user_id);
		$permissionKey = "memberPermission.{$userId}";
		$permissionStr = 'default';

		// 从Redis获取权限（Redis不可用时用默认值）
		if ($this->redis) {
			$permissionStr = $this->redis->exists($permissionKey) 
				? $this->redis->get($permissionKey) 
				: 'default';
		}

		// 转换权限为数字等级（便于比较，如super→5，default→1）
		$permissionLevel = $this->convertPermissionToLevel($permissionStr);
		Logger::debug("用户权限查询 | ID: {$userId} | 权限: {$permissionStr} | 等级: {$permissionLevel}");

		return $permissionLevel;
	}


	/**
	 * 获取群开启状态（群消息时有效，私聊返回true）
	 * @return bool 群是否开启（true=开启，false=关闭）
	 */
	protected function getGroupStatus(): bool {
		$messageType = strtolower(Env::get('message.type'));
		// 私聊消息无需群状态，直接返回true
		if ($messageType !== 'group') {
			return true;
		}

		try {
			$groupStatus = $this->groupStatus($this->param->group_id);
			Logger::debug("群状态查询 | ID: {$this->param->group_id} | 状态: " . ($groupStatus ? '开启' : '关闭'));
			return $groupStatus;
		} catch (\Exception $e) {
			Logger::error("群状态查询失败：{$e->getMessage()}");
			return false; // 异常时默认视为关闭
		}
	}


	/**
	 * 权限字符串转数字等级（便于比较）
	 * @param string $permission 权限字符串（如super、admin、default）
	 * @return int 权限等级（数字越大权限越高）
	 */
	protected function convertPermissionToLevel(string $permission): int {
		return match($permission) {
			'super', 8, '8' => 8,
			'supervisor', 7, '7' => 7,
			'owner', 6, '6' => 6,
			'director', 5, '5' => 5,
			'manager', 4, '4' => 4,
			'admin', 3, '3' => 3,
			'editor', 2, '2' => 2,
			default => is_numeric($permission) ? (int)$permission : 1
		};
	}


	/**
	 * 匹配路由规则并执行对应控制器方法
	 * @param int $userPermission 用户权限等级
	 * @param bool $groupStatus 群开启状态（true=开启）
	 */
	protected function matchAndExecuteRouter(int $userPermission, bool $groupStatus): void {
		$routerList = router::list()['Controller\Attr\Rule'] ?? [];
		if (empty($routerList)) {
			Logger::debug("无路由规则匹配：Controller\Attr\Rule 列表为空");
			return;
		}

		foreach ($routerList as $route) {
			// 路由规则必要字段校验
			if (!$this->validateRouteRule($route)) continue;
			
			// 消息内容匹配路由正则
			if (!$this->matchRouteRegex($route['match'], $this->param->raw_message)) continue;

			// 权限与群状态校验
			if (!$this->checkRoutePermission($route, $userPermission, $groupStatus)) continue;
			
			// 协程执行控制器方法
			$this->executeRouteInCoroutine($route);
		}
	}


	/**
	 * 校验路由规则字段是否完整
	 * @param array $route 路由规则
	 * @return bool 字段是否完整
	 */
	protected function validateRouteRule(array $route): bool {
		$requiredFields = ['match', 'permission', 'class', 'func'];
		foreach ($requiredFields as $field) {
			if (!isset($route[$field]) || empty(($route[$field]))) {
				Logger::warning("路由规则字段缺失：{$field}，跳过该规则");
				return false;
			}
		}
		return true;
	}

	/**
	 * 匹配路由正则（支持大小写不敏感）
	 * @param string $regex 路由正则
	 * @param string $message 消息内容
	 * @return bool 是否匹配
	 */
	protected function matchRouteRegex(string $regex, string $message): bool {
		$matchResult = preg_match($regex, trim($message));
		if ($matchResult === false) {
			Logger::error("路由正则匹配失败：正则 {$regex}，错误：" . preg_last_error_msg());
			return false;
		}
		return $matchResult > 0;
	}


	/**
	 * 校验路由权限与群状态
	 * @param array $route 路由规则
	 * @param int $userPermission 用户权限等级
	 * @param bool $groupStatus 群开启状态
	 * @return bool 是否允许执行
	 */
	protected function checkRoutePermission(array $route, int $userPermission, bool $groupStatus): bool {
		$routePermissionLevel = $this->convertPermissionToLevel($route['permission']);
		$routeClass = $route['class'];
		$routeFunc = $route['func'];
		
		// 1. 群关闭且用户权限不足3级：拒绝执行
		if (!$groupStatus && $userPermission < 3) {
			Logger::warning(
				"路由执行拒绝 | 群关闭且权限不足 | 路由：{$routeClass}->{$routeFunc} | " .
				"用户ID：{$this->param->user_id} | 权限等级：{$userPermission}"
			);
			return false;
		}

		// 2. 用户权限低于路由要求：拒绝执行并返回提示
		if ($userPermission < $routePermissionLevel) {
			Logger::warning(
				"路由执行拒绝 | 用户越权 | 路由：{$routeClass}->{$routeFunc} | " .
				"用户ID：{$this->param->user_id} | 用户权限：{$userPermission} | 要求权限：{$routePermissionLevel}"
			);
			// $this->server->sendError(403, '喵喵喵（权限不足）');
			return false;
		}

		return true;
	}


	/**
	 * 协程执行路由对应的控制器方法（捕获所有异常，避免协程崩溃）
	 * @param array $route 路由规则
	 */
	protected function executeRouteInCoroutine(array $route): void {
		$routeClass = $route['class'];
		$routeFunc = $route['func'];

		Coroutine::create(function () use ($route, $routeClass, $routeFunc) {
			try {
				// 1. 检查控制器类是否存在
				if (!class_exists($routeClass)) {
					throw new RuntimeException("控制器不存在：{$routeClass}");
				}

				// 2. 实例化控制器并执行方法
				$controller = new $routeClass($route);
				if (!method_exists($controller, $routeFunc)) {
					throw new RuntimeException("控制器方法不存在：{$routeClass}->{$routeFunc}");
				}
				$controller->{$routeFunc}();
				Logger::success("路由执行成功 | {$routeClass}->{$routeFunc}");

			} catch (\Exception $e) {
				$this->logRouteError($routeClass, $routeFunc, 'Exception', $e);
			} catch (\Error $e) {
				$this->logRouteError($routeClass, $routeFunc, 'Error', $e);
			} catch (\CompileError $e) {
				$this->logRouteError($routeClass, $routeFunc, 'CompileError', $e);
			}
		});
	}

	/**
	 * 记录路由执行错误日志（统一格式）
	 * @param string $class 控制器类名
	 * @param string $func 方法名
	 * @param string $errorType 错误类型（Exception/Error等）
	 * @param \Throwable $e 错误对象
	 */
	protected function logRouteError(string $class, string $func, string $errorType, \Throwable $e): void {
		$errorMsg = "路由执行失败 | 类型：{$errorType} | 路由：{$class}->{$func} | " .
					"消息：{$e->getMessage()} | 文件：{$e->getFile()} | 行号：{$e->getLine()}";
		Logger::error($errorMsg);
		Logger::error("错误堆栈：{$e->getTraceAsString()}");

		// 可选：向用户发送错误提示（原逻辑注释，保留开关）
		// if (isset($this->param->group_id)) {
		//	 $this->api->message->sendMsg(group: $this->param->group_id, message: "执行错误：{$e->getMessage()}");
		// }
	}
}