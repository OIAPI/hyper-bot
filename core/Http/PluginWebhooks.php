<?php
namespace Core\Http;

use Core\Bot\Registry;
use Core\Log\Logger;

/**
 * 把插件里 #[Webhook] 注解的方法注册成公开 HTTP 路由。
 *
 *   #[\Controller\Attr\Webhook(method: 'get', router: '/路径')]
 *   public function test() { return 122; }
 *   → GET /路径/test
 *
 * 限制：
 *   - method 只允许 get/post/put/patch/delete/all；
 *   - router 可留空（根路径），不能含空白 / ? / #，不能占用 /webui、/api、/webhook/github；
 *   - 方法必须是 public 实例方法，参数只能是 0 个或 1 个 Core\Http\Request；
 *   - 返回值只接受 Response / 标量 / 数组 / null，其它类型返回 500。
 */
final class PluginWebhooks
{
    private const RESERVED = ['/webui', '/api', '/webhook/github'];
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete', 'all'];

    public static function register(Router $router, Registry $registry, Logger $logger): void
    {
        self::registerHooks($router, $registry->webhooks(), $logger);
    }

    /** @param list<array{class:string,func:string,method:string,router:string}> $hooks */
    public static function registerHooks(Router $router, array $hooks, Logger $logger): void
    {
        $seen = [];
        foreach ($hooks as $hook) {
            $class = (string)($hook['class'] ?? '');
            $func = (string)($hook['func'] ?? '');
            if ($class === '' || $func === '') {
                continue;
            }
            $method = strtolower(trim((string)($hook['method'] ?? 'all')));
            if (!in_array($method, self::METHODS, true)) {
                $logger->warning('[webhook] 忽略注解：不支持的方法 ' . $method . ' | ' . $class . '::' . $func);
                continue;
            }
            $path = self::path((string)($hook['router'] ?? ''), $func);
            if ($path === null) {
                $logger->warning('[webhook] 忽略注解：路径不合法 | ' . $class . '::' . $func);
                continue;
            }
            if (self::reserved($path)) {
                $logger->warning('[webhook] 忽略注解：路径被系统占用 ' . $path . ' | ' . $class . '::' . $func);
                continue;
            }

            // 路径里有中文等非 ASCII 字符时，同时注册百分号编码版本：
            // 不同 Swoole / 反向代理给出的 path_info 可能一份是原文、一份是编码。
            $variants = [$path];
            if (preg_match('/[^\x00-\x7F]/u', $path)) {
                $variants[] = (string)preg_replace_callback(
                    '/[^\x00-\x7F]+/u',
                    static fn(array $m): string => rawurlencode($m[0]),
                    $path,
                );
            }

            foreach ($variants as $i => $routePath) {
                $key = $method . ' ' . $routePath;
                if (isset($seen[$key])) {
                    $logger->warning('[webhook] 忽略重复路由 ' . $key);
                    continue;
                }
                $seen[$key] = true;
                $handler = static function (Request $request, array $params = []) use ($class, $func): Response {
                    return self::invoke($class, $func, $request, $params);
                };
                $name = 'plugin.webhook.' . strtolower(str_replace('\\', '.', $class)) . '.' . $func . ($i > 0 ? '.' . $i : '');
                try {
                    if ($method === 'all') {
                        $router->any($routePath, $handler, $name);
                    } else {
                        $router->add($method, $routePath, $handler, $name);
                    }
                } catch (\Throwable $e) {
                    $logger->warning('[webhook] 路由注册失败 ' . $method . ' ' . $routePath . ' | ' . $e->getMessage());
                }
            }
        }
    }

    /** router + 方法名 → 最终路径；非法返回 null */
    public static function path(string $router, string $func): ?string
    {
        $router = trim($router);
        if ($router === '' || $router === '/') {
            $router = '';
        } else {
            if (!str_starts_with($router, '/')) {
                $router = '/' . $router;
            }
            $router = rtrim($router, '/');
        }
        $path = $router . '/' . $func;
        if (strlen($path) > 128) {
            return null;
        }
        if (preg_match('/[\s?#]/', $path)) {
            return null;
        }
        if (str_contains($path, '..')) {
            return null;
        }
        if (!preg_match('/^\/[A-Za-z0-9_\-\.\/\{\}\x{4e00}-\x{9fa5}]*$/u', $path)) {
            return null;
        }
        return $path;
    }

    /**
     * 参数注入：用反射按类型/名字解析，支持多个参数、默认值和可空。
     *
     *   Request $request        → 当前请求
     *   array $params           → 路由参数（名字叫 headers/query/body 时取对应部分）
     *   string $id / int $n     → 路由参数 → query → body 里同名值
     *   ?string $x = 'default'  → 找不到就用默认值 / null
     *
     * @return array{0:bool, 1:mixed}
     */
    private static function resolveParam(\ReflectionParameter $param, Request $request, array $routeParams): array
    {
        $type = $param->getType();
        $typeName = '';
        if ($type instanceof \ReflectionNamedType) {
            $typeName = $type->getName();
        } elseif ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $item) {
                if ($item instanceof \ReflectionNamedType && $item->getName() === Request::class) {
                    return [true, $request];
                }
            }
        }
        if ($typeName === Request::class) {
            return [true, $request];
        }
        $name = $param->getName();
        if ($typeName === 'array') {
            $bag = match (strtolower($name)) {
                'header', 'headers' => $request->headers,
                'query', 'get' => $request->query,
                'body', 'data', 'post', 'payload' => $request->body,
                'params', 'path', 'route' => $routeParams,
                default => array_merge($routeParams, $request->query, $request->body),
            };
            return [true, $bag];
        }
        // 标量 / 未声明类型：路由参数 → query → body
        foreach ([$routeParams, $request->query, $request->body] as $bag) {
            if (array_key_exists($name, $bag)) {
                return [true, self::cast($bag[$name], $typeName)];
            }
        }
        if ($param->isDefaultValueAvailable()) {
            return [true, $param->getDefaultValue()];
        }
        if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
            return [true, null];
        }
        return [false, null];
    }

    private static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'string' => is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE),
            'int' => (int)$value,
            'float' => (float)$value,
            'bool' => is_bool($value) ? $value : in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true),
            default => $value,
        };
    }

    private static function reserved(string $path): bool
    {
        foreach (self::RESERVED as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }
        return false;
    }

    private static function invoke(string $class, string $func, Request $request, array $routeParams = []): Response
    {
        try {
            if (!class_exists($class) || !method_exists($class, $func)) {
                return Response::fail('Webhook 方法不存在', -1, 404);
            }
            $ref = new \ReflectionMethod($class, $func);
            if (!$ref->isPublic() || $ref->isStatic()) {
                return Response::fail('Webhook 方法必须是 public 实例方法', -1, 500);
            }
            $plugin = new $class(['webhook' => true, 'request' => $request, 'params' => $routeParams]);
            $args = [];
            foreach ($ref->getParameters() as $param) {
                $resolved = self::resolveParam($param, $request, $routeParams);
                if (!$resolved[0]) {
                    return Response::fail(
                        'Webhook 参数无法解析：$' . $param->getName()
                        . '（支持 Request、array，以及按参数名从路由参数 / query / body 取标量）',
                        -1,
                        500,
                    );
                }
                $args[] = $resolved[1];
            }
            $result = $plugin->{$func}(...$args);
            if ($result instanceof Response) {
                return $result;
            }
            if ($result === null || is_scalar($result) || is_array($result)) {
                return Response::json($result);
            }
            return Response::fail(
                'Webhook 返回值类型不受支持：' . get_debug_type($result) . '（只允许 Response / 标量 / 数组 / null）',
                -1,
                500,
            );
        } catch (\Throwable $e) {
            \Utils\Logger::error('[webhook] 注解方法执行失败 | ' . $class . '::' . $func . ' | ' . $e->getMessage());
            return Response::fail('Webhook 执行失败：' . $e->getMessage(), -1, 500);
        }
    }
}
