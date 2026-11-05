<?php
namespace Core\Http;

/**
 * HTTP 内核：把「请求 → 路由 → 中间件 → 控制器 → 响应」这条链固定下来。
 *
 * 与 `Controller\Server\RequestHandler` 的区别：
 *  - 本类**不依赖 Swoole**（只吃 `Core\Http\Request`），因此可在 CLI 下单测；
 *  - 本类**不回头找历史 Cache::get('param')**，参数一律显式传入；
 *  - 中间件是显式的、可组合的，而不是散落在各控制器里的 if 判断。
 *
 * 历史逻辑（post_type 触发、MessageLog、子协程）保留在旧的 RequestHandler 中，
 * 由 Kernel 以「兜底处理器」的形式挂载，二者互不干扰。
 */
final class Kernel {
    public function __construct(
        private Router $router,
        private ?\Core\Log\Logger $logger = null,
        private ?\Closure $fallback = null,
        private ?\Closure $errorHandler = null,
    ) {}

    /** @var array<int, callable> 插件注册的响应过滤器 */
    private array $responseFilters = [];

    /** 插件可以追加「拿到 Response 之后再改一把」的过滤器（CORS、统一头等） */
    public function addResponseFilter(callable $filter): void {
        $this->responseFilters[] = $filter;
    }

    public function router(): Router {
        return $this->router;
    }

    public function setFallback(?\Closure $fallback): void {
        $this->fallback = $fallback;
    }

    /** 依次跑插件过滤器；任何一步抛错都只记日志，不影响原响应 */
    private function applyFilters(Request $request, Response $response): Response {
        foreach ($this->responseFilters as $filter) {
            try {
                $next = $filter($request, $response);
                if ($next instanceof Response) {
                    $response = $next;
                }
            } catch (\Throwable $e) {
                $this->logger?->warning('response filter failed: ' . $e->getMessage());
            }
        }
        return $response;
    }

    /** @var array<int, callable> 插件注册的请求中间件（进来就执行） */
    private array $middlewares = [];

    /**
     * 插件可以挂 ThinkPHP 风格的请求中间件：fn(Request $req, callable $next): Response。
     *
     * 和 addResponseFilter 的区别：这个在路由匹配之前执行，拿到的是 Request，
     * 可以鉴权、限流、做维护模式、改写请求，也可以短路直接返回 Response。
     */
    public function addMiddleware(callable $middleware): void {
        $this->middlewares[] = $middleware;
    }

    public function handle(Request $request): Response {
        $started = microtime(true);
        // WebUI 全局账号作用域：前端选中哪个机器人，后端所有带账号语义的数据
        // （群、权限、订阅覆盖、Webhook、RSS、事件/消息、定时任务）都按它读写。
        $requestedAccount = trim($request->header('x-account-id', ''));
        if ($requestedAccount !== '') {
            $account = \Core\Bot\Accounts::get($requestedAccount);
            if ($account !== null) {
                \Core\Bot\AccountContext::use($account);
            }
        }
        try {
            // 插件中间件包在最外层：它们能看到 404，也能决定根本不进路由
            $runner = fn(Request $req): Response => $this->resolve($req);
            foreach (array_reverse($this->middlewares) as $middleware) {
                $next = $runner;
                $runner = fn(Request $req): Response => $middleware($req, $next);
            }
            return $this->applyFilters($request, $runner($request));
        } catch (\Throwable $e) {
            $this->logger?->error('http kernel exception', [
                'path'  => $request->path,
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
            ]);
            if ($this->errorHandler !== null) {
                return ($this->errorHandler)($e, $request);
            }
            return Response::fail($e->getMessage(), -1, 500);
        } finally {
            \Core\Bot\AccountContext::reset();
            $this->logger?->debug('http request', [
                'method' => $request->method,
                'path'   => $request->path,
                'ms'     => round((microtime(true) - $started) * 1000, 2),
            ]);
        }
    }

    /** 路由匹配 → 内置中间件链 → 控制器 */
    private function resolve(Request $request): Response {
        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            if ($this->fallback !== null) {
                $result = ($this->fallback)($request);
                return $result instanceof Response ? $result : Response::json($result);
            }
            $allowed = $this->router->allowedMethods($request->path);
            if ($allowed !== []) {
                return Response::fail('method not allowed', -405, 405)->withHeader('Allow', implode(', ', $allowed));
            }
            return Response::fail("route not found: {$request->path}", -404, 404);
        }

        $chain = array_map(function (string $alias) {
            $handler = $this->router->middlewareHandler($alias);
            if ($handler === null) {
                throw new \RuntimeException("middleware not registered: {$alias}");
            }
            return $handler;
        }, $match['middlewares']);

        $core = fn(Request $req): Response => $this->dispatch($match['handler'], $req, $match['params']);

        return array_reduce(
            array_reverse($chain),
            fn(callable $next, callable $middleware) => fn(Request $req): Response => $middleware($req, $next),
            $core
        )($request);
    }

    private function dispatch(mixed $handler, Request $request, array $params): Response {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && class_exists($handler[0])) {
            $handler = [new $handler[0](), $handler[1]];
        }
        if (!is_callable($handler)) {
            throw new \RuntimeException('route handler is not callable');
        }
        $result = $handler($request, $params);
        if ($result instanceof Response) {
            return $result;
        }
        if (is_array($result) || is_object($result)) {
            return Response::json($result);
        }
        return is_string($result) ? Response::text($result) : Response::ok($result);
    }
}