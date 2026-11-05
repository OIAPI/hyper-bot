<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\Registry;
use Core\Http\PluginWebhooks;
use Core\Http\Request;
use Core\Http\Router;
use Core\Log\Logger;

$logger = new Logger('test');
// 测试夹具不在 composer autoload 里，先手动加载，Registry 才能反射到类
require_once __DIR__ . '/fixtures/WebhookFixture.php';
$registry = new Registry($logger, ['Test\\Fixtures\\' => __DIR__ . '/fixtures']);
$hooks = $registry->webhooks();

$pass = 0;
$fail = 0;
function check(string $name, string $want, string $got): void {
    global $pass, $fail;
    if ($want === $got) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       期待: " . json_encode($want, 256) . "\n       实际: " . json_encode($got, 256) . "\n"; }
}

check('扫描到 11 条注解（非法方法/保留路径仍在列表，注册时过滤）', '11', (string)count($hooks));

$router = new Router();
PluginWebhooks::registerHooks($router, $hooks, $logger);

function call(Router $router, string $method, string $path, array $body = []): array {
    $parts = parse_url($path);
    $routePath = (string)($parts['path'] ?? $path);
    parse_str((string)($parts['query'] ?? ''), $query);
    $match = $router->match($method, $routePath);
    if ($match === null) {
        return ['status' => 404, 'body' => ''];
    }
    $request = new Request(
        method: $method,
        path: $routePath,
        query: $query,
        body: $body,
        headers: [],
        rawBody: (string)json_encode($body),
        cookies: [],
        files: [],
        remoteIp: '127.0.0.1',
        startedAt: microtime(true),
    );
    $response = ($match['handler'])($request, $match['params']);
    return ['status' => $response->status, 'body' => $response->body];
}

$r = call($router, 'GET', '/demo/ping');
check('GET /demo/ping 返回 122', '200|122', $r['status'] . '|' . $r['body']);

$r = call($router, 'POST', '/demo/any');
check('all 任意方法', '200|{"ok":true}', $r['status'] . '|' . $r['body']);

$r = call($router, 'POST', '/demo/echo', ['x' => 1]);
check('Request 注入', '200|{"body":{"x":1}}', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/root');
check('router 留空 → 根路径 /root', '200|"root"', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/路径/chinese');
check('中文路径', '200|"你好"', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/' . rawurlencode('路径') . '/chinese');
check('中文路径（百分号编码）', '200|"你好"', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/demo/badReturn');
check('非法返回值 → 500', '500', (string)$r['status']);
check('非法返回值有说明', '1', str_contains($r['body'], '返回值类型不受支持') ? '1' : '0');

$r = call($router, 'GET', '/demo/multi?' . http_build_query(['name' => '张三', 'n' => '9']));
check('多参数：Request + query 标量 + 默认值', '200|{"name":"张三","n":9,"extra":null,"method":"GET"}', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/demo/42/withPath');
check('路由参数 {id} + params 数组', '200|{"id":42,"params":{"id":"42"}}', $r['status'] . '|' . $r['body']);

$r = call($router, 'GET', '/demo/missing');
check('缺少必填参数 → 500', '500', (string)$r['status']);
check('缺少参数有说明', '1', str_contains($r['body'], '参数无法解析') ? '1' : '0');

check('保留路径不注册', '404', (string)call($router, 'GET', '/webui/x')['status']);
check('非法方法不注册', '404', (string)call($router, 'TRACE', '/demo/badMethod')['status']);

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
