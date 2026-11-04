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
    // 接口现在返回完整群对象（避免前端覆盖整行时丢掉群名/时间），插件状态看 worker
    $ok('关闭单个插件', $tg['status'] === 200 && ($tg['json']['data']['worker'][$firstPlugin] ?? null) === false);
    $show2 = $call('GET', '/webui/api/groups/123456', [], $token);
    $ok('群详情反映插件状态', ($show2['json']['data']['worker'][$firstPlugin] ?? null) === false, json_encode($show2['json']['data']['worker'] ?? []));
}
$helpToggle = $call('POST', '/webui/api/groups/123456/plugins/' . rawurlencode('App\\Plugins\\Help'), ['enabled' => false], $token);
$ok('help 插件拒绝关闭', ($helpToggle['json']['code'] ?? 0) !== 1);

// v1 插件的钩子必须能被索引出来（文件名 Group.php、类名 group，两处大小写不一致，
// 曾经因此面板显示「插件在、钩子 0 个」）
$byShort = [];
foreach ($items as $it) {
    $byShort[strtolower((string)($it['short'] ?? ''))] = $it;
}
foreach (['group' => 5, 'help' => 6, 'sys' => 5] as $short => $min) {
    $hooks = $byShort[$short]['hooks'] ?? [];
    $ok("v1 插件 {$short} 有钩子", count($hooks) >= $min, 'hooks=' . count($hooks));
}
$allHooks = 0;
foreach ($items as $it) {
    $allHooks += count($it['hooks'] ?? []);
}
$ok('插件钩子总数合理', $allHooks >= 16, 'total=' . $allHooks);
$sample = $byShort['group']['hooks'][0] ?? [];
$ok('钩子带类型与方法', ($sample['type'] ?? '') !== '' && ($sample['method'] ?? '') !== '', json_encode($sample));
$ok('钩子带可读触发条件', ($sample['trigger'] ?? '') !== '', json_encode($sample));
$ok('钩子带中文类型', ($sample['label'] ?? '') !== '', json_encode($sample));

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
$healthFull = $call('GET', '/webui/api/health');
$ok('健康检查带 checks 数组', is_array($healthFull['json']['data']['checks'] ?? null) && isset($healthFull['json']['data']['ok']));
$disk = $call('GET', '/webui/api/system/disk', [], $token);
$ok('磁盘统计', is_array($disk['json']['data']['items'] ?? null));
$ok('磁盘统计含 total/free/used/dirs', isset($disk['json']['data']['total'], $disk['json']['data']['used']) && is_array($disk['json']['data']['dirs'] ?? null));
$dates = $call('GET', '/webui/api/logs/dates', [], $token);
$ok('日志日期同时给 dates 与 items', is_array($dates['json']['data']['dates'] ?? null) && is_array($dates['json']['data']['items'] ?? null));

// 纯文本 logger.log 里的历史记录也要能读出来（业务日志大量走这套，曾经导致日志页永远空白）
$plainDay = '2020-01-01'; // 用一个不会与真实日志撞车的日期，保证断言只命中这一条
$plainFile = (defined('__logger__') ? __logger__ : __root__ . '/runtime/log') . '/logger.log';
$plainBackup = is_file($plainFile) ? (string)file_get_contents($plainFile) : null;
file_put_contents($plainFile, "[{$plainDay} 08:00:00.000] [INFO] [测试] 纯文本日志回归用例" . PHP_EOL, FILE_APPEND);
register_shutdown_function(static function () use ($plainFile, $plainBackup): void {
    if ($plainBackup !== null) {
        file_put_contents($plainFile, $plainBackup);
    } else {
        @unlink($plainFile);
    }
});
$plainDayRes = $call('GET', '/webui/api/logs/' . $plainDay . '?limit=5', [], $token);
$plainHit = false;
foreach ($plainDayRes['json']['data']['items'] ?? [] as $row) {
    if (str_contains((string)($row['message'] ?? ''), '纯文本日志回归用例')) { $plainHit = true; }
}
$ok('纯文本日志能被按天读出', $plainHit);
$plainDates = $call('GET', '/webui/api/logs/dates', [], $token);
$ok('日期列表包含纯文本历史日期', in_array($plainDay, $plainDates['json']['data']['dates'] ?? [], true), json_encode($plainDates['json']['data']['dates'] ?? []));

// 日志项必须带 time/level/channel/message：前端表格按这四个字段渲染
$first = $tail['json']['data']['items'][0] ?? [];
$ok('日志项含 time/level/channel/message', isset($first['time'], $first['level'], $first['channel'], $first['message']), json_encode(array_keys($first)));
$ok('日志 time 是可读时间', is_string($first['time'] ?? null) && str_contains((string)($first['time'] ?? ''), ':'));

// 纯文本 logger.log 也要读出来（大量业务日志只写这里，曾经导致日志页永远空白）
$plainHit = false;
foreach ($day['json']['data']['items'] ?? [] as $row) {
    if (is_string($row['time'] ?? null) && str_contains((string)$row['time'], ':')) { $plainHit = true; break; }
}
$ok('按天日志含可读时间项', $plainHit);

// 消息记录：字段名与前端约定一致，且默认回落到最近有数据的那天
$msgs = $call('GET', '/webui/api/messages', [], $token);
$ok('消息记录返回 date/items/total', isset($msgs['json']['data']['date'], $msgs['json']['data']['total']) && is_array($msgs['json']['data']['items'] ?? null));
$ok('消息记录带可选日期列表', is_array($msgs['json']['data']['dates'] ?? null));

// 插件全局开关：停用后不再参与分发，重新启用恢复
$pOff = $call('PUT', '/webui/api/plugins/' . rawurlencode('App\Plugins\Group') . '/enable', ['enabled' => false], $token);
$ok('停用插件', ($pOff['json']['data']['enabled'] ?? null) === false);
$pList = $call('GET', '/webui/api/plugins', [], $token);
$offRow = null;
foreach ($pList['json']['data']['items'] ?? [] as $row) {
    if (strcasecmp((string)($row['class'] ?? ''), 'App\Plugins\Group') === 0) { $offRow = $row; }
}
$ok('列表里显示已停用', ($offRow['enabled'] ?? true) === false);
$pOn = $call('PUT', '/webui/api/plugins/' . rawurlencode('App\Plugins\Group') . '/enable', ['enabled' => true], $token);
$ok('重新启用插件', ($pOn['json']['data']['enabled'] ?? null) === true);
$routes = $call('GET', '/webui/api/system/routes', [], $token);
$ok('路由清单 > 25 条', count($routes['json']['data']['items'] ?? []) > 25, 'got ' . count($routes['json']['data']['items'] ?? []));
$cache = $call('POST', '/webui/api/system/cache', [], $token);
$ok('清理缓存需 confirm', ($cache['json']['code'] ?? 0) !== 1);
$cache2 = $call('POST', '/webui/api/system/cache', ['confirm' => true], $token);
$ok('confirm 后清理成功', ($cache2['json']['code'] ?? 0) === 1);

echo "== 权限（RBAC）==\n";
// 三档账号：主人 / 管理员 / 只读
\Webui\Auth::setUsersForTest([
    'boss'    => ['password' => password_hash('secret123', PASSWORD_BCRYPT), 'role' => 'owner',  'created' => 1],
    'manager' => ['password' => password_hash('secret123', PASSWORD_BCRYPT), 'role' => 'admin',  'created' => 2],
    'guest'   => ['password' => password_hash('secret123', PASSWORD_BCRYPT), 'role' => 'viewer', 'created' => 3],
]);
$loginAs = function (string $u) use ($call): string {
    $r = $call('POST', '/webui/api/auth/login', ['username' => $u, 'password' => 'secret123']);
    return (string)($r['json']['data']['token'] ?? '');
};
$ownerToken = $loginAs('boss');
$adminToken = $loginAs('manager');
$viewerToken = $loginAs('guest');
$ok('三种角色都能登录', $ownerToken !== '' && $adminToken !== '' && $viewerToken !== '');

$meV = $call('GET', '/webui/api/auth/me', [], $viewerToken);
$ok('me 带 role / permissions / is_owner', ($meV['json']['data']['role'] ?? '') === 'viewer'
    && is_array($meV['json']['data']['permissions'] ?? null)
    && ($meV['json']['data']['is_owner'] ?? true) === false);
$ok('viewer 没有写权限', !in_array('settings.write', $meV['json']['data']['permissions'] ?? [], true));
$meO = $call('GET', '/webui/api/auth/me', [], $ownerToken);
$ok('owner is_owner=true', ($meO['json']['data']['is_owner'] ?? false) === true);

$vList = $call('GET', '/webui/api/users', [], $viewerToken);
$ok('viewer 看账号列表 → 403', $vList['status'] === 403);
$aList = $call('GET', '/webui/api/users', [], $adminToken);
$ok('admin 看账号列表 → 403（仅主人）', $aList['status'] === 403);
$oList = $call('GET', '/webui/api/users', [], $ownerToken);
$items = $oList['json']['data']['items'] ?? [];
$ok('owner 看账号列表', $oList['status'] === 200 && count($items) === 3);
$ok('列表不含密码哈希', !isset($items[0]['password']));
$ok('角色表随列表返回', ($oList['json']['data']['roles'] ?? []) === ['owner', 'admin', 'viewer']);

$upd = $call('GET', '/webui/api/update', [], $ownerToken);
$ok('更新页信息带版本与 git 状态', ($upd['json']['data']['version'] ?? '') !== '' && isset($upd['json']['data']['git']));
$vUpd = $call('POST', '/webui/api/update/check', [], $viewerToken);
$ok('viewer 检查更新 → 403', $vUpd['status'] === 403);
$badAttach = $call('POST', '/webui/api/update/attach', ['url' => 'not-a-url'], $ownerToken);
$ok('关联仓库：非法地址被拒', $badAttach['status'] === 400 && str_contains((string)($badAttach['json']['message'] ?? ''), 'http'));
$vAttach = $call('POST', '/webui/api/update/attach', ['url' => 'https://github.com/OIAPI/hyper-bot.git'], $viewerToken);
$ok('关联仓库：viewer → 403', $vAttach['status'] === 403);
$badGit = $call('POST', '/webui/api/update/git', ['url' => 'not-a-url'], $ownerToken);
$ok('非法仓库地址被拒', $badGit['status'] === 400 && str_contains((string)($badGit['json']['message'] ?? ''), 'http'));

$vCache = $call('POST', '/webui/api/system/cache', ['confirm' => true], $viewerToken);
$ok('viewer 清缓存 → 403', $vCache['status'] === 403);
$aCache = $call('POST', '/webui/api/system/cache', ['confirm' => true], $adminToken);
$ok('admin 清缓存 → 200', ($aCache['json']['code'] ?? 0) === 1);

$new = $call('POST', '/webui/api/users', ['username' => 'temp', 'password' => 'temp1234', 'role' => 'viewer'], $ownerToken);
$ok('owner 建账号', ($new['json']['code'] ?? 0) === 1 && count($new['json']['data']['items'] ?? []) === 4);
$dup = $call('POST', '/webui/api/users', ['username' => 'temp', 'password' => 'temp1234', 'role' => 'viewer'], $ownerToken);
$ok('重名被拒', ($dup['json']['code'] ?? 1) !== 1);
$weak = $call('POST', '/webui/api/users', ['username' => 'shortpw', 'password' => '123', 'role' => 'viewer'], $ownerToken);
$ok('弱密码被拒', ($weak['json']['code'] ?? 1) !== 1);

$promote = $call('PATCH', '/webui/api/users/temp', ['role' => 'admin'], $ownerToken);
$ok('改角色', ($promote['json']['code'] ?? 0) === 1);
$demote = $call('PATCH', '/webui/api/users/boss', ['role' => 'viewer'], $ownerToken);
$ok('唯一 owner 不能被降级', ($demote['json']['code'] ?? 0) !== 1);
$selfDel = $call('DELETE', '/webui/api/users/boss', [], $ownerToken);
$ok('不能删自己 / 唯一 owner', ($selfDel['json']['code'] ?? 0) !== 1);
$del = $call('DELETE', '/webui/api/users/temp', [], $ownerToken);
$ok('删账号', ($del['json']['code'] ?? 0) === 1);

$vGit = $call('POST', '/webui/api/plugins/import/git', ['url' => 'https://example.com/x.git'], $viewerToken);
$ok('viewer 导入插件 → 403', $vGit['status'] === 403);
$vZip = $call('POST', '/webui/api/plugins/import/zip', ['name' => 'x'], $viewerToken);
$ok('viewer 上传插件 → 403', $vZip['status'] === 403);
$oEmpty = $call('POST', '/webui/api/plugins/import/git', ['url' => ''], $ownerToken);
$ok('owner 空地址 → 400', $oEmpty['status'] === 400 && ($oEmpty['json']['code'] ?? 0) !== 1);
$oPkg = $call('POST', '/webui/api/plugins/import/git', ['url' => 'https://example.invalid/nope.git'], $adminToken);
$ok('admin 有导入权限（网络失败而非 403）', $oPkg['status'] !== 403);

echo "== 订阅目录 ==\n";
$catTmp = sys_get_temp_dir() . '/sub-custom-' . bin2hex(random_bytes(3)) . '.json';
\Core\Bot\SubscriptionCatalog::path($catTmp);
$cat = $call('GET', '/webui/api/subscription/catalog', [], $viewerToken);
$ok('viewer 可读目录', $cat['status'] === 200 && count($cat['json']['data']['groups'] ?? []) >= 6);
$ok('目录含内置统计', ($cat['json']['data']['stats']['builtin'] ?? 0) >= 40);
$ok('目录与订阅接口同源', in_array('group_increase', array_keys($cat['json']['data']['types'] ?? []), true));

$vAdd = $call('POST', '/webui/api/subscription/catalog', ['type' => 'demo_event', 'label' => '演示'], $viewerToken);
$ok('viewer 添加订阅 → 403', $vAdd['status'] === 403);

$oAdd = $call('POST', '/webui/api/subscription/catalog', ['type' => 'demo_event', 'label' => '演示事件', 'group' => '自定义'], $ownerToken);
$ok('owner 添加订阅', ($oAdd['json']['code'] ?? 0) === 1);
$types = array_keys($oAdd['json']['data']['types'] ?? []);
$ok('目录里出现新类型', in_array('demo_event', $types, true));

$dup = $call('POST', '/webui/api/subscription/catalog', ['type' => 'demo_event', 'label' => '重复'], $ownerToken);
$ok('重复添加 → 400', $dup['status'] === 400);
$bad = $call('POST', '/webui/api/subscription/catalog', ['type' => 'Bad Name', 'label' => 'x'], $ownerToken);
$ok('非法类型名 → 400', $bad['status'] === 400);

$delBuiltin = $call('DELETE', '/webui/api/subscription/catalog/group_increase', [], $ownerToken);
$ok('内置类型不可删 → 400', $delBuiltin['status'] === 400);
$delCustom = $call('DELETE', '/webui/api/subscription/catalog/demo_event', [], $ownerToken);
$ok('删除自定义类型', ($delCustom['json']['code'] ?? 0) === 1);
@unlink($catTmp);
\Core\Bot\SubscriptionCatalog::path((defined('__root__') ? __root__ : getcwd()) . '/runtime/subscription-custom.json');

echo "== 细分开关与按群覆盖 ==\n";
$subInfo = $call('GET', '/webui/api/subscription', [], $viewerToken);
$ok('订阅返回细分开关', array_key_exists('request_types', $subInfo['json']['data']['subscription'] ?? []));
$ok('订阅返回按群覆盖', array_key_exists('group_overrides', $subInfo['json']['data']['subscription'] ?? []));
$ok('订阅返回架构字段', array_key_exists('receives_events', $subInfo['json']['data'] ?? []));
$ok('订阅返回架构模式', is_string($subInfo['json']['data']['arch'] ?? null));

$ovTmp = sys_get_temp_dir() . '/sub-groups-' . bin2hex(random_bytes(3)) . '.json';
\Core\Bot\SubscriptionOverride::path($ovTmp);
$g0 = $call('GET', '/webui/api/subscription/groups', [], $viewerToken);
$ok('viewer 可读按群覆盖', $g0['status'] === 200 && ($g0['json']['data']['groups'] ?? null) === []);
$vSet = $call('PUT', '/webui/api/subscription/groups/100', ['mute' => true], $viewerToken);
$ok('viewer 写按群覆盖 → 403', $vSet['status'] === 403);
$oSet = $call('PUT', '/webui/api/subscription/groups/100', ['mute' => true], $ownerToken);
$ok('owner 写入按群覆盖', ($oSet['json']['code'] ?? 0) === 1);
$ok('返回里带上该群', isset($oSet['json']['data']['groups']['100']));
$oBadType = $call('PUT', '/webui/api/subscription/groups/100', ['notice_types' => ['nope']], $ownerToken);
$ok('未知通知类型 → 400', $oBadType['status'] === 400);
$oDelGroup = $call('DELETE', '/webui/api/subscription/groups/100', [], $ownerToken);
$ok('owner 删按群覆盖', ($oDelGroup['json']['code'] ?? 0) === 1);
@unlink($ovTmp);
\Core\Bot\SubscriptionOverride::path((defined('__root__') ? __root__ : getcwd()) . '/runtime/subscription-groups.json');

// 恢复成单个管理员账号，后面的登出用例继续用它
\Webui\Auth::setUsersForTest(['admin' => ['password' => password_hash('secret123', PASSWORD_BCRYPT), 'role' => 'admin', 'created' => time()]]);

echo "== 机器人账号（多账号） ==\n";
$accTmp = $tmp . '/accounts.json';
\Core\Bot\Accounts::path($accTmp);
$al = $call('GET', '/webui/api/accounts', [], $token);
$ok('账号列表 200', $al['status'] === 200 && is_array($al['json']['data']['accounts'] ?? null), $al['raw']);
$ok('带协议字典', isset($al['json']['data']['protocols']['onebot']));
$ok('带统计', isset($al['json']['data']['stats']['total']));

$ac = $call('POST', '/webui/api/accounts', [
    'id' => 'onebot-1001', 'name' => '测试号', 'protocol' => 'onebot',
    'self_id' => '1001001', 'api' => 'http://127.0.0.1:3000', 'token' => 'secret-token',
], $token);
$ok('创建账号', ($ac['json']['code'] ?? 0) === 1, $ac['raw']);
$ok('响应不回显 token', !isset($ac['json']['data']['saved']['token']));
$ok('响应标记 token_set', ($ac['json']['data']['saved']['token_set'] ?? false) === true);
$ok('账号落盘', is_file($accTmp));

$dup = $call('POST', '/webui/api/accounts', ['protocol' => 'onebot', 'self_id' => '1001001'], $token);
$ok('同协议 self_id 重复 → 400', $dup['status'] === 400);

$badProto = $call('POST', '/webui/api/accounts', ['protocol' => 'nope', 'self_id' => '1002002'], $token);
$ok('未知协议 → 400', $badProto['status'] === 400);

$badApi = $call('POST', '/webui/api/accounts', ['protocol' => 'milky', 'self_id' => '1003003', 'api' => 'ftp://x'], $token);
$ok('非 http api → 400', $badApi['status'] === 400);

$au = $call('PUT', '/webui/api/accounts/onebot-1001', ['remark' => '改过备注'], $token);
$ok('更新账号', ($au['json']['code'] ?? 0) === 1);
$ok('更新后仍保留 token_set', (bool)($au['json']['data']['saved']['token_set'] ?? false));

$viewerAcc = $call('GET', '/webui/api/accounts', [], $viewerToken);
$ok('viewer 可读账号', $viewerAcc['status'] === 200);
$viewerWrite = $call('POST', '/webui/api/accounts', ['protocol' => 'qqbot', 'self_id' => '1004004'], $viewerToken);
$ok('viewer 建号 → 403', $viewerWrite['status'] === 403);

$ac2 = $call('POST', '/webui/api/accounts', ['protocol' => 'qqbot', 'self_id' => '1002002', 'name' => '第二个号'], $token);
$ok('同一机器人可加多个协议账号', ($ac2['json']['code'] ?? 0) === 1);

$ad = $call('DELETE', '/webui/api/accounts/onebot-1001', [], $token);
$ok('删除账号', ($ad['json']['code'] ?? 0) === 1);
$adLast = $call('DELETE', '/webui/api/accounts/1002002', [], $token);
$ok('删到剩一个 → 拒绝（至少保留一个账号）', $adLast['status'] === 400);

echo "== 第三方登录 ==\n";
$pv = $call('GET', '/webui/api/auth/oauth/providers', [], $token);
$ok('provider 列表 200', $pv['status'] === 200, $pv['raw']);
$ids = array_column($pv['json']['data']['providers'] ?? [], 'id');
$ok('含 github/gitee/linuxdo', $ids === ['github', 'gitee', 'linuxdo'], implode(',', $ids));
$ok('未配密钥时 enabled=false', ($pv['json']['data']['providers'][0]['enabled'] ?? true) === false);
$ok('回调地址带 provider 占位', str_contains((string)($pv['json']['data']['callback'] ?? ''), '{provider}'));

$startAnon = $call('GET', '/webui/api/auth/oauth/github/start');
$ok('未配置时 start 拒绝（400/302）', in_array($startAnon['status'], [400, 302], true));
$startBad = $call('GET', '/webui/api/auth/oauth/nope/start');
$ok('未知 provider → 404', $startBad['status'] === 404);

$unbind = $call('DELETE', '/webui/api/auth/oauth/github', [], $token);
$ok('未绑定解绑 → 404', $unbind['status'] === 404);

@unlink($accTmp);

echo "== 群名同步 / 发送自检 ==\n";
$vSync = $call('POST', '/webui/api/groups/sync', [], $viewerToken);
$ok('viewer 同步群列表 → 403', $vSync['status'] === 403);
$tSend = $call('POST', '/webui/api/protocols/onebot/test', [], $token);
$ok('发送自检返回结构', isset($tSend['json']['data']['ok']), $tSend['raw']);
$ok('自检给出接口地址与提示', array_key_exists('api', $tSend['json']['data'] ?? []) && array_key_exists('hint', $tSend['json']['data'] ?? []));
$sync = $call('POST', '/webui/api/groups/sync', [], $token);
$ok('无协议端时同步给出明确原因', in_array($sync['status'], [400, 502], true), $sync['raw']);

// 指定不存在的账号时要明确 404，而不是默默用配置里的地址去发
$badAcc = $call('POST', '/webui/api/protocols/onebot/test', ['account' => 'no-such-account'], $token);
$ok('自检指定不存在的账号 → 404', $badAcc['status'] === 404, $badAcc['raw']);
$badSync = $call('POST', '/webui/api/groups/sync', ['account' => 'no-such-account'], $token);
$ok('同步指定不存在的账号 → 404', $badSync['status'] === 404, $badSync['raw']);

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