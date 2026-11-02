<?php
/**
 * WebUI 开发服务器（无需 Swoole）。
 *
 * 生产环境仍然走 Swoole（见 controller/Server/HttpServer.php）；
 * 这个入口只用于本地开发、CI 与「没有装 Swoole 的机器」上预览 WebUI。
 *
 *   php -S 127.0.0.1:8090 -t public public/dev-server.php
 */
require __DIR__ . '/../Include.php';

\Core\App::boot();
\Webui\Auth::bootstrap();

$request = \Core\Http\Request::fromSwoole((object)[
    'server' => [
        'request_method'     => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'path_info'          => parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/',
        'remote_addr'        => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
        'request_time_float' => $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true),
    ],
    'header' => array_change_key_case(function_exists('getallheaders') ? (getallheaders() ?: []) : [], CASE_LOWER),
    'get'    => $_GET,
    'post'   => $_POST,
    'cookie' => $_COOKIE,
    'files'  => $_FILES,
    'rawBody' => (string)file_get_contents('php://input'),
]);

// 静态资源优先：/webui/ 下的文件直接由内置服务器返回
$rel = ltrim($request->path, '/');
$staticFile = __DIR__ . '/' . $rel;
if ($request->method === 'GET' && is_file($staticFile) && str_starts_with($request->path, '/webui/')) {
    $ext = strtolower(pathinfo($staticFile, PATHINFO_EXTENSION));
    $types = ['css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'html' => 'text/html; charset=utf-8', 'svg' => 'image/svg+xml', 'png' => 'image/png', 'ico' => 'image/x-icon', 'json' => 'application/json'];
    header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
    header('Cache-Control: no-cache');
    readfile($staticFile);
    return;
}
if ($request->path === '/webui' || $request->path === '/webui/') {
    header('Location: /webui/index.html');
    return;
}

$kernel = \Webui\Routes::kernel();
$response = $kernel->handle($request);

http_response_code($response->status);
foreach ($response->headers as $name => $value) {
    header($name . ': ' . $value);
}
foreach ($response->cookies as $cookie) {
    header('Set-Cookie: ' . $cookie, false);
}
// 静态资源回落到内置服务器（/webui/ 下的 html/js/css）
if ($response->status === 404 && is_file(__DIR__ . ($request->path))) {
    return false;
}
echo $response->body;