<?php
/**
 * WebUI Swoole 桥接自检。
 *
 * 本机没有 swoole 扩展，因此用最小桩对象模拟 Swoole\Http\Request/Response
 * 的形状，验证 Webui\Bridge 的分流、状态码、Header 与 Cookie 写回是否正确。
 * 这能覆盖"接进生产入口"这条链路，而不只是 dev-server。
 */

declare(strict_types=1);

require __DIR__ . '/../Include.php';

$pass = 0;
$fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name} {$extra}\n"; }
}

final class StubRequest {
    public array $server;
    public array $header;
    public array $get = [];
    public array $post = [];
    public array $cookie = [];
    public array $files = [];
    private string $raw;
    public function __construct(string $method, string $path, array $headers = [], string $raw = '') {
        $this->server = [
            'request_method' => $method,
            'path_info'      => $path,
            'remote_addr'    => '127.0.0.1',
            'request_time_float' => microtime(true),
        ];
        $this->header = $headers;
        $this->raw = $raw;
    }
    public function rawContent(): string { return $this->raw; }
}

final class StubResponse {
    public int $status = 200;
    public array $headers = [];
    public array $cookies = [];
    public string $body = '';
    public function status(int $s): void { $this->status = $s; }
    public function header(string $k, string $v): void { $this->headers[$k] = $v; }
    public function cookie(...$args): void { $this->cookies[] = $args; }
    public function end(string $b = ''): void { $this->body .= $b; }
}

echo "== WebUI Swoole 桥接 ==\n";

// 1. 非 WebUI 路径必须放行给旧逻辑
$res = new StubResponse();
ok('非 WebUI 路径不被接管', \Webui\Bridge::handle(new StubRequest('GET', '/api/foo'), $res) === false);

// 2. /webui/ 目录跳转
$res = new StubResponse();
$handled = \Webui\Bridge::handle(new StubRequest('GET', '/webui/'), $res);
ok('/webui/ 重定向到 index', $handled && $res->status === 302 && ($res->headers['Location'] ?? '') === '/webui/index.html');

// 3. 公开端点可直接访问
$res = new StubResponse();
$handled = \Webui\Bridge::handle(new StubRequest('GET', '/webui/api/auth/status'), $res);
$json = json_decode($res->body, true);
ok('公开端点可访问', $handled && $res->status === 200 && ($json['code'] ?? 0) === 1);

// 4. 受保护端点未登录必须 401
$res = new StubResponse();
$handled = \Webui\Bridge::handle(new StubRequest('GET', '/webui/api/overview'), $res);
$json = json_decode($res->body, true);
ok('未登录访问受保护端点为 401', $handled && $res->status === 401 && ($json['code'] ?? 0) === -401);

// 5. JSON 响应必须带 Content-Type，且不含 HTML
ok('JSON 响应带 Content-Type', str_contains((string)($res->headers['Content-Type'] ?? ''), 'application/json'));

// 6. 登录成功要下发 HttpOnly 会话 Cookie
$body = json_encode(['username' => 'admin', 'password' => 'bridge-pass']);
$res = new StubResponse();
@\Webui\Auth::setUsersForTest(['admin' => ['password' => password_hash('bridge-pass', PASSWORD_BCRYPT), 'role' => 'admin', 'created' => time()]]);
\Webui\Bridge::handle(new StubRequest('POST', '/webui/api/auth/login', ['content-type' => 'application/json'], (string)$body), $res);
$json = json_decode($res->body, true);
$setCookie = (string)($res->headers['Set-Cookie'] ?? '');
ok('登录成功', $res->status === 200 && ($json['code'] ?? 0) === 1, $res->body);
ok('登录下发会话 Cookie', str_contains($setCookie, \Core\Http\Middleware\AuthMiddleware::COOKIE . '='));
ok('Cookie 为 HttpOnly', stripos($setCookie, 'HttpOnly') !== false);

// 7. 带 Cookie 的后续请求应被识别为已登录
$token = (string)($json['data']['token'] ?? '');
$res = new StubResponse();
\Webui\Bridge::handle(new StubRequest('GET', '/webui/api/auth/status', ['cookie' => \Core\Http\Middleware\AuthMiddleware::COOKIE . '=' . $token]), $res);
$json2 = json_decode($res->body, true);
ok('会话可被后续请求识别', ($json2['data']['authenticated'] ?? false) === true);

echo "\n" . ($fail === 0 ? "全部通过（{$pass} 项）" : "失败 {$fail} 项") . "\n";
exit($fail === 0 ? 0 : 1);
