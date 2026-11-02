<?php
/**
 * HTTP 入口（v2）：PHP 架构与 WebUI-only 架构的路由脚本。
 *
 * 由 `php bin/hyper serve` 通过 PHP 内置服务器加载：
 *   php -S 127.0.0.1:8080 -t public public/index.php
 *
 * 职责：
 *   1. 把 PHP 内置服务器的全局变量转成内核的 Request；
 *   2. 静态资源（/webui/ 下的 html/js/css）直接回给内置服务器，不进入内核；
 *   3. 其余请求全部交给 Core\Http\Kernel（WebUI API + 协议 Webhook + 健康检查）。
 *
 * 和 v1 的 public/dev-server.php 的区别：
 *   - v1 那个只用于「本地预览 WebUI」，协议端点得走 Swoole 的老路径；
 *   - v2 这个是**真正的应用入口**，协议 Webhook 与 WebUI 同源同管线。
 */
declare(strict_types=1);

require dirname(__DIR__) . '/Include.php';

use Core\Http\Request;
use Core\Http\Response;

\Core\Bootstrap::run(['quiet' => true]);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// 1. 静态资源直接放行（交给 PHP 内置服务器返回原始文件）
if (isStaticAsset($path)) {
    return false;
}

// 2. /webui 根路径跳转到面板首页
if ($path === '/webui' || $path === '/webui/') {
    header('Location: /webui/index.html', true, 302);
    return true;
}

// 3. 构造内核请求
$request = Request::fromSwoole((object)[
    'server' => [
        'request_method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path_info'          => $path,
        'remote_addr'        => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'request_time_float' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
    ],
    'header'  => array_change_key_case(
        function_exists('getallheaders') ? (getallheaders() ?: []) : [],
        CASE_LOWER
    ),
    'get'     => $_GET,
    'post'    => $_POST,
    'cookie'  => $_COOKIE,
    'files'   => $_FILES,
    'rawBody' => (string)file_get_contents('php://input'),
]);

// 4. 走内核
$kernel = \Webui\Routes::kernel();
$response = $kernel->handle($request);

emit($response);
return true;

// ============================================================

/** 是否是 /webui/ 下可直接静态返回的文件 */
function isStaticAsset(string $path): bool
{
    if (!str_starts_with($path, '/webui/')) {
        return false;
    }
    $file = dirname(__DIR__) . '/public' . $path;
    return is_file($file);
}

function emit(Response $response): void
{
    http_response_code($response->status);
    foreach ($response->headers as $name => $value) {
        header($name . ': ' . $value, true);
    }
    foreach ($response->cookies as $cookie) {
        header('Set-Cookie: ' . $cookie, false);
    }
    echo $response->body;
}
