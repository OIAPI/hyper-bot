<?php
declare(strict_types=1);
/**
 * WebUI API 端到端自检（不需要 Swoole / Redis / 浏览器）。
 *   php tests/webui_api_test.php
 */
require __DIR__ . '/../Include.php';

// 测试用独立运行时目录，避免污染真实数据
$tmp = sys_get_temp_dir() . '/wb-api-' . bin2hex(random_bytes(4));
@mkdir($tmp . '/webui', 0775, true);
@mkdir($tmp . '/log', 0775, true);

$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($detail !== '' && !$cond ? "  ({$detail})" : '') . PHP_EOL;
    if (!$cond) $fail++;
};

// 1) 装配内核（内存存储，避免依赖 Redis）
$app = \Core\App::boot();
$store = new \Core\Store\MemoryStore();
$app->container->bind(\Core\Store\Store::class, $store)->bind('store', $store);

$auth = new \Core\Http\Middleware\AuthMiddleware($store);
$router = $app->container->get(\Core\Http\Router::class);
$router->middleware('webui.auth', fn($req, $next) => $auth->process($req, $next));
\Webui\Routes::register($router, $auth);
$kernel = new \Core\Http\Kernel($router, $app->logger, null, fn($e, $r) => \Core\Http\Response::fail($e->getMessage(), -1, 500));

$call = function (string $method, string $path, array $body = [], string $token = '') use ($kernel, $store) {
    $headers = $token !== '' ? ['authorization' => 'Bearer ' . $token] : [];
    $req = new \Core\Http\Request($method, $path, [], $body, $headers, json_encode($body) ?: '', $token !== '' ? [] : [], [], '127.0.0.1', microtime(true));
    $res = $kernel->handle($req);
    return ['status' => $res->status, 'json' => json_decode($res->body, true), 'raw' => $res->body];
};

echo "== 鉴权 ==\n";
// 造一个测试账号（不碰真实 users.json）
\Webui\Auth::setUsersForTest(['admin' => ['password' => password_hash('secret123', PASSWORD_BCRYPT), 'role' => 'admin', 'created' => time()]]);
$login = $call('POST', '/webui/api/auth/login', ['username' => 'admin', 'password' => 'secret123']);
$ok('登录成功', $login['status'] === 200 && ($login['json']['code'] ?? 0) === 1, $login['raw']);
$token = (string)($login['json']['data']['token'] ?? '');
$ok('返回 token', strlen($token) === 48);

$bad = $call('POST', '/webui/api/auth/login', ['username' => 'admin', 'password' => 'wrong']);
$ok('密码错误 401', $bad['status'] === 401);

$noAuth = $call('GET', '/webui/api/overview');
$ok('未登录访问受保护接口 → 401', $noAuth['status'] === 401);

$me = $call('GET', '/webui/api/auth/me', [], $token);
$ok('me 返回用户名', ($me['json']['data']['user']['username'] ?? '') === 'admin');

echo "== 概览 ==\n";
$ov = $call('GET', '/webui/api/overview', [], $token);
$ok('overview 200', $ov['status'] === 200);
$data = $ov['json']['data'] ?? [];
$ok('含 app/runtime/counts/protocol', isset($data['app'], $data['runtime'], $data['counts'], $data['protocol']));
$ok('app.store 有值', ($data['app']['store'] ?? '') !== '');
$ok('counts.groups 为数字', is_int($data['counts']['groups'] ?? null));

$counts = $call('GET', '/webui/api/overview/counts', [], $token);
$ok('counts 端点', ($counts['json']['data']['plugins'] ?? -1) >= 0);

$proto = $call('GET', '/webui/api/overview/protocols', [], $token);
$ok('三个协议都在', count($proto['json']['data'] ?? []) === 3);

$ev = $call('GET', '/webui/api/overview/events?limit=5', [], $token);
$ok('events 返回数组', is_array($ev['json']['data'] ?? null));

echo "== 群管理 ==\n";
$created = $call('POST', '/webui/api/groups', ['group_id' => '123456'], $token);
$ok('创建群', ($created['json']['data']['group_id'] ?? '') === '123456');
$list = $call('GET', '/webui/api/groups', [], $token);
$ok('群列表包含新群', in_array('123456', array_column($list['json']['data']['items'] ?? [], 'group_id'), true));
$show = $call('GET', '/webui/api/groups/123456', [], $token);
$ok('群详情', ($show['json']['data']['group_id'] ?? '') === '123456');
$meta = $call('PATCH', '/webui/api/groups/123456/meta', ['name' => '测试群', 'note' => '备注'], $token);
$ok('更新元数据', ($meta['json']['data']['name'] ?? '') === '测试群');
$status = $call('POST', '/webui/api/groups/123456/status', [], $token);
$ok('切换群开关', ($status['json']['data']['status'] ?? null) === true);

$plugins = $call('GET', '/webui/api/plugins', [], $token);
$items = $plugins['json']['data']['items'] ?? [];
$ok('插件列表非空', count($items) > 0, 'count=' . count($items));
$firstPlugin = (string)($items[0]['class'] ?? '');
if ($firstPlugin !== '') {
    $tg = $call('POST', '/webui/api/groups/123456/plugins/' . rawurlencode($firstPlugin), ['enabled' => false], $token);
    $ok('关闭单个插件', $tg['status'] === 200 && ($tg['json']['data']['enabled'] ?? null) === false);
    $show2 = $call('GET', '/webui/api/groups/123456', [], $token);
    $ok('群详情反映插件状态', ($show2['json']['data']['worker'][$firstPlugin] ?? null) === false, json_encode($show2['json']['data']['worker'] ?? []));
}
$helpToggle = $call('POST', '/webui/api/groups/123456/plugins/' . rawurlencode('App\\Plugins\\Help'), ['enabled' => false], $token);
$ok('help 插件拒绝关闭', ($helpToggle['json']['code'] ?? 0) !== 1);

$del = $call('DELETE', '/webui/api/groups/123456', [], $token);
$ok('删除群', ($del['json']['code'] ?? 0) === 1);

echo "== 协议 ==\n";
$pl = $call('GET', '/webui/api/protocols', [], $token);
$ok('协议列表', count($pl['json']['data'] ?? []) === 3);
$one = $call('GET', '/webui/api/protocols/qqbot', [], $token);
$ok('协议详情含 config', is_array($one['json']['data']['config'] ?? null));
$ep = $call('GET', '/webui/api/protocols/onebot/endpoints', [], $token);
$ok('端点清单', is_array($ep['json']['data'] ?? null) && count($ep['json']['data']) >= 2);
$acts = $call('GET', '/webui/api/protocols/milky/actions', [], $token);
$ok('Milky 不支持清单有内容', count($acts['json']['data']['unsupported'] ?? []) >= 10);
$ok('Milky 支持清单有内容', count($acts['json']['data']['supported'] ?? []) >= 50, 'got ' . count($acts['json']['data']['supported'] ?? []));
$qb = $call('GET', '/webui/api/protocols/qqbot/actions', [], $token);
$ok('QQ 不支持清单有内容', count($qb['json']['data']['unsupported'] ?? []) === 5);

echo "== 日志 ==\n";
$app->logger->info('webui test log');
$tail = $call('GET', '/webui/api/logs/tail?limit=10', [], $token);
$ok('日志 tail', count($tail['json']['data']['items'] ?? []) > 0);
$ok('日志 stats', isset($tail['json']['data']['stats']['total']));
$day = $call('GET', '/webui/api/logs/' . date('Y-m-d'), [], $token);
$ok('按天读取不报错', $day['status'] === 200);
$badDay = $call('GET', '/webui/api/logs/2020-13-99', [], $token);
$ok('非法日期被拒（400）', $badDay['status'] === 400 && ($badDay['json']['code'] ?? 0) !== 1);

echo "== 配置 ==\n";
$set = $call('GET', '/webui/api/settings', [], $token);
$ok('配置树', is_array($set['json']['data']['config'] ?? null));
$ok('配置含 protocol.qqbot', isset($set['json']['data']['config']['protocol']['qqbot']));
$ok('client_secret 已脱敏', ($set['json']['data']['config']['protocol']['qqbot']['client_secret'] ?? '') === '');
$raw = $call('GET', '/webui/api/settings/raw', [], $token);
$ok('原始配置可读', is_string($raw['json']['data']['text'] ?? null));

echo "== 系统 ==\n";
$health = $call('GET', '/webui/api/health');
$ok('健康检查免登录', $health['status'] === 200);
$disk = $call('GET', '/webui/api/system/disk', [], $token);
$ok('磁盘统计', is_array($disk['json']['data']['items'] ?? null));
$routes = $call('GET', '/webui/api/system/routes', [], $token);
$ok('路由清单 > 25 条', count($routes['json']['data']['items'] ?? []) > 25, 'got ' . count($routes['json']['data']['items'] ?? []));
$cache = $call('POST', '/webui/api/system/cache', [], $token);
$ok('清理缓存需 confirm', ($cache['json']['code'] ?? 0) !== 1);
$cache2 = $call('POST', '/webui/api/system/cache', ['confirm' => true], $token);
$ok('confirm 后清理成功', ($cache2['json']['code'] ?? 0) === 1);

echo "== 错误处理 ==\n";
$notFound = $call('GET', '/webui/api/nope', [], $token);
$ok('未知接口 404', $notFound['status'] === 404);
$wrongMethod = $call('DELETE', '/webui/api/overview', [], $token);
$ok('方法不允许 405', $wrongMethod['status'] === 405);

echo "== 登出 ==\n";
$out = $call('POST', '/webui/api/auth/logout', [], $token);
$ok('登出成功', ($out['json']['code'] ?? 0) === 1);
$after = $call('GET', '/webui/api/overview', [], $token);
$ok('登出后 token 失效', $after['status'] === 401);

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);