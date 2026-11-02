<?php
namespace Controller\Server;

use Swoole\Coroutine;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Utils\Logger;
use Utils\MessageLog;
use Utils\Cache;
use Utils\Env;
use Utils\Route; // 引入路由类
use InvalidArgumentException;
use RuntimeException;
use App\Controller\Api as robot;


class RequestHandler {
	private array $allowedTypes = [];
	private ?string $currentPostType = null;
	private Request $request;
	private Response $response;

	public function __construct(Request $request, Response $response) {
		$this->request = $request;
		$this->response = $response;

		// 记录请求基础信息（保留原逻辑）
		$method = $request->server['request_method'] ?? 'UNKNOWN';
		$path = $request->server['path_info'] ?? '/';
		// Logger::success("[请求接收] {$method} {$path}");

		if (Env::get('app.debug', false)) {
			$paramJson = json_encode(Cache::get('param'), JSON_UNESCAPED_UNICODE);
			Logger::debug("[请求参数] {$paramJson}");
		}
		$this->initRoute();
	}
	private function initRoute() {
		// 注意：registerByAnnotation 内部有 self::$init 一次性开关，
		// 多次调用时只有第一次生效，因此所有控制器必须一次性传入。
		$controllers = [robot::class];
		if (class_exists(\App\Controller\Protocol::class)) {
			$controllers[] = \App\Controller\Protocol::class;
		}
		Route::registerByAnnotation($controllers);
	}

	// 保留原registerType方法（兼容旧的post_type逻辑）
	public function registerType(string|array $types): void {
		$types = is_array($types) ? $types : [$types];
		foreach ($types as $type) {
			$type = ucwords(trim($type));
			if (!empty($type)) {
				$this->allowedTypes[$type] = true;
				// Logger::debug("[请求类型注册] 已允许：{$type}");
			}
		}
	}

	/**
	 * 新增：实例化后注册路由（兼容$this->route()调用）
	 * @param string $method 请求方法（get/post）
	 * @param string $path 路由路径
	 * @param callable $callback 回调函数
	 */
	public function route(string $method, string $path, callable $callback): void {
		$method = strtoupper($method);
		if (!in_array($method, ['GET', 'POST'])) {
			throw new InvalidArgumentException("不支持的请求方法：{$method}");
		}
		// 调用Route类静态方法注册
		if ($method === 'GET') {
			Route::get($path, $callback);
		} else {
			Route::post($path, $callback);
		}
	}

	/**
	 * 核心改造：优先匹配路由，未匹配则走原post_type逻辑
	 */
	public function handle(): void {
		try {
			$method = $this->request->server['request_method'] ?? 'UNKNOWN';
			$path = $this->request->server['path_info'] ?? '/';

			/*
			 * WebSocket 端点的兜底分支。
			 *
			 * 正常情况：HttpServer 使用 Swoole\WebSocket\Server 且
			 * open_websocket_protocol = true，升级请求由 Swoole 直接完成握手并派发
			 * WsHub::onOpen（鉴权也在那里做），**不会**进入本方法。
			 *
			 * 兜底情况：服务端退化为普通 HTTP Server 时，这里显式 upgrade，
			 * 保证 /onebot/v11/ws 与 /milky/event 仍可被手工接入。
			 */
			if ($this->isWebSocketEndpoint($path)) {
				$this->handleWebSocketUpgrade($path);
				return;
			}

			$param = Cache::get('param');
	
			// 匹配路由：获取 [回调函数, 路由参数]
			$routeResult = Route::match($method, $path);
			if ($routeResult) {
				[$routeCallback, $routeParams] = $routeResult;
				$this->handleRouteCallback($routeCallback, $routeParams); // 传入路由参数
				return;
			}

			// 2. 未匹配路由，执行原post_type逻辑（兼容旧功能）
			// Logger::debug("[未匹配路由] 走原post_type逻辑：{$method} {$path}");
			$this->validateBaseParams();
			$this->currentPostType = ucwords($param->post_type);
			if (!isset($this->allowedTypes[$this->currentPostType])) {
				throw new InvalidArgumentException("不支持的请求类型：{$this->currentPostType}", 400);
			}
			MessageLog::write($param, $this->currentPostType);
			// 子协程不保证继承父协程上下文，先快照再在子协程内恢复
			$snapshot = Cache::snapshot();
			Coroutine::create(function () use ($snapshot) {
				Cache::restore($snapshot);
				try {
					$this->completeUserId();
					$controllerClass = "\\Controller\\Trigger\\{$this->currentPostType}";
					$this->createController($controllerClass);
					// Logger::success("[请求处理成功] 类型：{$this->currentPostType}");
				} catch (\Exception $e) {
					Logger::error("[协程处理异常] 类型：{$this->currentPostType}，原因：{$e->getMessage()}，堆栈：{$e->getTraceAsString()}");
					if (!$this->isEnd()) {
						$this->sendError(500, "服务器内部错误：{$e->getMessage()}");
					}
				}
			});
			$this->sendSuccess(['status' => 'accepted', 'message' => '请求已接收，正在处理']);

		} catch (\Exception $e) {
			$code = $e->getCode() ?: 400;
			$this->sendError($code, $e->getMessage());
			Logger::error("[请求处理失败] 原因：{$e->getMessage()}，代码：{$code}");
		}
	}

	/**
	 * 处理路由回调（新增路由参数传递）
	 */
	private function handleRouteCallback(callable $callback, array $routeParams): void {
		$snapshot = Cache::snapshot();
		Coroutine::create(function () use ($callback, $routeParams, $snapshot) {
			Cache::restore($snapshot);
			try {
				// 回调函数参数：请求对象、响应对象、全局参数、路由参数（顺序固定，便于使用）
				$result = call_user_func(
					$callback,
					$this->request,
					$this->response,
					Cache::get('param'),
					$routeParams // 新增：传递路由参数（如 ['id' => 123]）
				);
	
				// 响应处理（原逻辑不变）
				if (is_array($result) || is_object($result)) {
					$this->sendResponse($result, 200);
				} elseif ($result === null) {
					$this->sendResponse(['code' => 1, 'message' => '路由处理成功'], 200);
				} else {
					$this->sendResponse(['code' => 1, 'message' => $result], 200);
					// throw new RuntimeException("路由回调返回值必须是数组或null");
				}
			} catch (\Exception $e) {
				Logger::error("[路由处理异常] 原因：{$e->getMessage()}，堆栈：{$e->getTraceAsString()}");
				if (!$this->isEnd()) {
					$this->sendError(500, "路由处理错误：{$e->getMessage()}");
				}
			}
		});
	}

	/**
	 * 是否为 WebSocket 端点
	 */
	private function isWebSocketEndpoint(string $path): bool {
		$path = '/' . ltrim($path, '/');
		$onebotPath = rtrim(\Protocol\Core\Config::string('onebot.reverse.path', '/onebot/v11/ws'), '/');
		$milkyPath  = rtrim(\Protocol\Core\Config::string('milky.ws.server_path', '/milky/event'), '/');

		$isOneBot = \Protocol\Core\Config::bool('onebot.reverse.enable', false) && $path === $onebotPath;
		$isMilky  = \Protocol\Core\Config::bool('milky.enable', false) && $path === $milkyPath;
		return $isOneBot || $isMilky;
	}

	/**
	 * 处理 WebSocket 升级
	 * 说明：Swoole 会先派发 request 事件，需要显式 upgrade 才会进入 open/message 回调。
	 */
	private function handleWebSocketUpgrade(string $path): void {
		$path = '/' . ltrim($path, '/');
		$token = (string)($this->request->header['authorization'] ?? '');
		$query = (string)($this->request->server['query_string'] ?? '');

		// OneBot 反向 WS
		$onebotPath = rtrim(\Protocol\Core\Config::string('onebot.reverse.path', '/onebot/v11/ws'), '/');
		if ($path === $onebotPath && \Protocol\Core\Config::bool('onebot.reverse.enable', false)) {
			$expect = (string)\Protocol\Core\Config::string('onebot.reverse.token', '');
			if ($expect !== '' && !$this->checkToken($token, $query, $expect)) {
				Logger::warning('[OneBot-WS] 反向 WS 鉴权失败 | ' . $path);
				$this->response->status(401);
				$this->response->end('unauthorized');
				return;
			}
			$this->upgradeNow();
			return;
		}

		// Milky WS（本框架模拟协议端的 /event 端点）
		$milkyPath = rtrim(\Protocol\Core\Config::string('milky.ws.server_path', '/milky/event'), '/');
		if ($path === $milkyPath) {
			$expect = (string)\Protocol\Core\Config::string('milky.token', '');
			if ($expect !== '' && !$this->checkToken($token, $query, $expect)) {
				Logger::warning('[Milky-WS] 鉴权失败 | ' . $path);
				$this->response->status(401);
				$this->response->end('unauthorized');
				return;
			}
			$this->upgradeNow();
			return;
		}

		$this->response->status(404);
		$this->response->end('not a websocket endpoint');
	}

	/**
	 * 完成 WebSocket 握手
	 *
	 * 说明：Swoole 开启 open_websocket_protocol 后有两种握手方式
	 *  - 4.x/5.x 均可：request 回调里不写响应，Swoole 在回调结束后自动完成握手；
	 *  - 5.x 起额外提供 Response::upgrade()，可显式握手。
	 * 这里优先用显式升级，失败/不支持时回退到「不写响应」的自动握手。
	 */
	private function upgradeNow(): void {
		if (method_exists($this->response, 'upgrade')) {
			try {
				$this->response->upgrade();
				return;
			} catch (\Throwable $e) {
				Logger::warning('[WS] 显式 upgrade 失败，回退自动握手：' . $e->getMessage());
			}
		}
		// 不发送任何响应，交由 Swoole 自动完成握手
		Logger::debug('[WS] 交由 Swoole 自动完成 WebSocket 握手');
	}

	/**
	 * 校验 access_token：支持 Authorization: Bearer xxx 与 ?access_token=xxx
	 */
	private function checkToken(string $authorization, string $queryString, string $expect): bool {
		$bearer = '';
		if (str_starts_with(strtolower($authorization), 'bearer ')) {
			$bearer = trim(substr($authorization, 7));
		}
		if ($bearer === '' && $queryString !== '') {
			parse_str($queryString, $query);
			$bearer = (string)($query['access_token'] ?? '');
		}
		return $bearer !== '' && hash_equals($expect, $bearer);
	}

	// 以下方法均保留原逻辑，无修改
	private function validateBaseParams(): void {
		if (empty(Cache::get('param'))) {
			throw new RuntimeException("请求参数为空", 400);
		}
		if (!isset(Cache::get('param')->post_type) || empty(trim(Cache::get('param')->post_type))) {
			throw new RuntimeException("缺少必要参数：post_type", 400);
		}
	}

	private function completeUserId(): void {
		return;
		$param = Cache::get('param');
		if (!isset($param->user_id)) {
			if (isset($param->operator_id) && !empty($param->operator_id)) {
				$param->user_id = $param->operator_id;
				// Logger::debug("[用户ID补全] 从operator_id填充：{$param->user_id}");
			} else {
				throw new RuntimeException("缺少用户标识：user_id/operator_id", 400);
			}
		}
	}

	private function createController(string $controllerClass): object {
		if (!class_exists($controllerClass)) {
			throw new RuntimeException("控制器不存在：{$controllerClass}", 404);
		}

		try {
			return new $controllerClass();
		} catch (\Exception $e) {
			throw new RuntimeException("控制器实例化失败：{$controllerClass}，原因：{$e->getMessage()}", 500);
		}
	}

	public function sendSuccess(array $data, int $code = 200): void {
		$response = [
			'code' => 1,
			'message' => 'success666',
			'data' => $data,
			'timestamp' => time()
		];
		$this->sendResponse($response, $code);
	}

	public function sendError(int $httpCode, string $message, int $bizCode = -1): void {
		$response = [
			'code' => $bizCode,
			'message' => $message,
			'data' => null,
			'timestamp' => time()
		];
		$this->sendResponse($response, $httpCode);
	}

	private function sendResponse(array|object $response, int $httpCode): void {
		if ($this->isEnd()) {
			// Logger::warning("[响应重复发送] HTTP码：{$httpCode}，数据：" . json_encode($response));
			return;
		}

		$this->response->status($httpCode);
		$this->response->header('Content-Type', 'application/json; charset=utf-8');
		$this->response->header('Access-Control-Allow-Origin', '*');

		$responseJson = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
		$this->response->end($responseJson);
		Logger::debug("[响应发送] HTTP码：{$httpCode}");
	}
	public function __get(string $key): mixed {
		throw new RuntimeException("禁止通过魔术方法获取参数【{$key}】，请使用Cache::get('param->{$key}')", 403);
	}
	
	private function isEnd() {
		return !$this->response->isWritable();
	}

	public function on(string $type): void {
		$this->registerType($type);
	}
}