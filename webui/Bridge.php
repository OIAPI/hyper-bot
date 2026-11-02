<?php
namespace Webui;

use Core\Http\Kernel;
use Core\Http\Request as CoreRequest;

/**
 * 把 Swoole 请求桥接到内核的 HTTP 管线。
 *
 * 为什么需要桥接而不是直接用 Kernel：
 * - Swoole 的请求对象与内核的不可变 Request 是两种形状；
 * - 响应方面 Swoole 需要逐个 setHeader/status 写回，且 cookie 要手工拼；
 * - WebUI 的路由表与老站点的注解路由是两套体系，必须在这里分流。
 *
 * 这个类只在 Swoole 运行时被用到，纯 PHP 环境（含内置服务器）走 dev-server。
 */
final class Bridge {
    /** 该路径是否属于 WebUI（含静态资源） */
    public static function matches(string $path): bool {
        return $path === '/webui' || str_starts_with($path, '/webui/');
    }

    /**
     * 处理一个 Swoole 请求；返回 false 表示"不由 WebUI 处理"。
     */
    public static function handle(object $request, object $response): bool {
        $path = (string)($request->server['path_info'] ?? '/');
        if (!self::matches($path)) {
            return false;
        }

        // 静态资源交给 Swoole 的 enable_static_handler（document_root = public）；
        // 但目录本身不会自动补 index.html，这里显式跳转一次，保证 /webui/ 可访问。
        if ($path === '/webui' || $path === '/webui/') {
            $response->status(302);
            $response->header('Location', '/webui/index.html');
            $response->end();
            return true;
        }

        $core = CoreRequest::fromSwoole($request);

        try {
            $kernel = Routes::kernel();
            $result = $kernel->handle($core);
        } catch (\Throwable $e) {
            $response->status(500);
            $response->header('Content-Type', 'application/json; charset=utf-8');
            $response->end((string)json_encode([
                'code'    => -1,
                'message' => 'WebUI 处理异常：' . $e->getMessage(),
                'data'    => null,
            ], JSON_UNESCAPED_UNICODE));
            return true;
        }

        $response->status($result->status);
        foreach ($result->headers as $name => $value) {
            $response->header((string)$name, (string)$value);
        }
        // Response::$cookies 里存的是已经拼好的 Set-Cookie 字符串，
        // 直接用 header 写回即可；交给 Swoole 的 cookie() 会二次编码属性。
        if ($result->cookies !== []) {
            $response->header('Set-Cookie', implode("\r\n", $result->cookies));
        }
        $response->end($result->body);

        return true;
    }
}
