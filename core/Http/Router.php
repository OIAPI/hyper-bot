<?php
namespace Core\Http;

/**
 * 统一路由表。
 *
 * 历史包袱：项目里并存 `Utils\Route`（注解扫描、一次性注册）与
 * `Controller\Router`（插件扫描、菜单），两者职责重叠且都无法表达
 * 「中间件 / 分组 / 命名 / 参数约束」。
 *
 * 本类只做纯粹的「模式 → 处理器」映射，不做注解扫描、不碰插件，
 * 因此可以被单测覆盖，也可以被两个旧类共同委托。
 *
 * 模式语法：`/webui/api/groups/{group_id}`（`{name}` 与历史的 `:name` 等价，
 * 后者用于兼容旧控制器注解）。
 */
final class Router {
    /** @var array<string, list<array{pattern:string, regex:string, params:list<string>, handler:mixed, middlewares:list<string>, name:?string}>> */
    private array $routes = [];
    private array $names = [];
    /** @var list<string> */
    private array $groupStack = [];
    /** @var list<string> */
    private array $middlewareStack = [];
    private array $middlewareMap = [];

    public const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS', 'ANY'];

    public function middleware(string $alias, callable $handler): self {
        $this->middlewareMap[$alias] = $handler;
        return $this;
    }

    public function middlewareHandler(string $alias): ?callable {
        return $this->middlewareMap[$alias] ?? null;
    }

    /** 注册分组（前缀 + 该组所有路由共享的中间件） */
    public function group(string $prefix, callable $callback, array $middlewares = []): self {
        $this->groupStack[] = trim($prefix, '/');
        $count = count($this->middlewareStack);
        foreach ($middlewares as $mw) {
            $this->middlewareStack[] = $mw;
        }
        $callback($this);
        while (count($this->middlewareStack) > $count) {
            array_pop($this->middlewareStack);
        }
        array_pop($this->groupStack);
        return $this;
    }

    public function add(string|array $methods, string $pattern, mixed $handler, ?string $name = null, array $middlewares = []): self {
        foreach ((array)$methods as $method) {
            $method = strtoupper($method);
            if (!in_array($method, self::METHODS, true)) {
                throw new \InvalidArgumentException("不支持的方法：{$method}");
            }
            $full = $this->fullPattern($pattern);
            [$regex, $params] = self::compile($full);
            $record = [
                'pattern'     => $full,
                'regex'       => $regex,
                'params'      => $params,
                'handler'     => $handler,
                'middlewares' => array_merge($this->middlewareStack, $middlewares),
                'name'        => $name,
            ];
            $this->routes[$method][] = $record;
            if ($name !== null) {
                $this->names[$name] = $full;
            }
        }
        return $this;
    }

    public function get(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('GET', $pattern, $handler, $name, $mw);
    }

    public function post(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('POST', $pattern, $handler, $name, $mw);
    }

    public function put(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('PUT', $pattern, $handler, $name, $mw);
    }

    public function patch(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('PATCH', $pattern, $handler, $name, $mw);
    }

    public function delete(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('DELETE', $pattern, $handler, $name, $mw);
    }

    public function any(string $pattern, mixed $handler, ?string $name = null, array $mw = []): self {
        return $this->add('ANY', $pattern, $handler, $name, $mw);
    }

    /**
     * @return array{handler:mixed, params:array<string,string>, middlewares:list<string>}|null
     */
    public function match(string $method, string $path): ?array {
        $method = strtoupper($method);
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');

        foreach ([$method, 'ANY'] as $m) {
            foreach ($this->routes[$m] ?? [] as $route) {
                if (preg_match($route['regex'], $path, $matches)) {
                    $params = [];
                    foreach ($route['params'] as $param) {
                        // 路径参数可能被百分号编码（如 App%5CPlugins），必须解码
                        $params[$param] = rawurldecode((string)($matches[$param] ?? ''));
                    }
                    return [
                        'handler'     => $route['handler'],
                        'params'      => $params,
                        'middlewares' => $route['middlewares'],
                    ];
                }
            }
        }
        return null;
    }

    /** 允许的方法（用于 405 判定） */
    public function allowedMethods(string $path): array {
        $path = '/' . trim(parse_url($path, PHP_URL_PATH) ?: '', '/');
        $allowed = [];
        foreach ($this->routes as $method => $routes) {
            foreach ($routes as $route) {
                if ($method !== 'ANY' && preg_match($route['regex'], $path)) {
                    $allowed[] = $method;
                }
            }
        }
        return array_values(array_unique($allowed));
    }

    public function name(string $name): ?string {
        return $this->names[$name] ?? null;
    }

    public function all(): array {
        return $this->routes;
    }

    public function reset(): void {
        $this->routes = [];
        $this->names = [];
    }

    private function fullPattern(string $pattern): string {
        $prefix = implode('/', array_filter($this->groupStack, fn($v) => $v !== ''));
        $pattern = trim($pattern, '/');
        $segments = array_filter([$prefix, $pattern], fn($v) => $v !== '');
        return '/' . implode('/', $segments);
    }

    /** @return array{0:string,1:list<string>} */
    private static function compile(string $pattern): array {
        $params = [];
        $regex = preg_replace_callback(
            '/[{:]([a-zA-Z_][a-zA-Z0-9_]*)}?/',
            function (array $m) use (&$params) {
                $params[] = $m[1];
                return '(?P<' . $m[1] . '>[^/]+)';
            },
            $pattern
        );
        // 未闭合的 {param} 没被上面的模式吃掉，交给下面的兜底
        $regex = str_replace('{', '(', (string)$regex);
        $regex = str_replace('}', ')', (string)$regex);
        return ['~^' . $regex . '$~u', $params];
    }
}
