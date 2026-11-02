<?php
declare(strict_types=1);
/**
 * 内核自检：不依赖 Swoole / Redis，可在任意 PHP 8 环境跑。
 *   php tests/kernel_test.php
 */
require __DIR__ . '/../Include.php';

$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($detail !== '' && !$cond ? "  ({$detail})" : '') . PHP_EOL;
    if (!$cond) $fail++;
};

echo "== Core\\Config ==\n";
$ok('读取 app.debug', \Core\Config::bool('app.debug', false) === true, 'got ' . var_export(\Core\Config::get('app.debug'), true));
$ok('读取 protocol.default', \Core\Config::string('protocol.default') === 'http');
$ok('带点号小节名 [protocol.qqbot]', \Core\Config::string('protocol.qqbot.api') === 'https://api.bot.qq.com');
$ok('不带前缀等价', \Core\Config::string('qqbot.api') === 'https://api.bot.qq.com');
$ok('int 转换', \Core\Config::int('http.port', 0) === 8008);
$ok('section 读取', \Core\Config::section('protocol.qqbot') !== []);
$ok('脱敏后不含明文 secret', !array_key_exists('client_secret', \Core\Config::all(true)['protocol']['qqbot'] ?? []) || (\Core\Config::all(true)['protocol']['qqbot']['client_secret'] ?? '') === '');

echo "== Core\\Http\\Router ==\n";
$router = new \Core\Http\Router();
$router->get('/a', fn($r) => \Core\Http\Response::ok(['hit' => 'a']), 'a');
$router->group('/g', function ($r) {
    $r->get('/b/{id}', fn($req, $p) => \Core\Http\Response::ok(['id' => $p['id']]), 'g.b');
});
$m = $router->match('GET', '/a');
$ok('静态路由匹配', $m !== null && $m['handler'] !== null);
$m2 = $router->match('GET', '/g/b/42');
$ok('动态路由参数', ($m2['params']['id'] ?? '') === '42');
$ok('未匹配返回 null', $router->match('GET', '/nope') === null);
$ok('allowedMethods 返回 GET', in_array('GET', $router->allowedMethods('/a'), true));
$ok('命名路由可反查', $router->name('g.b') === '/g/b/{id}');

echo "== Core\\Http\\Kernel + 中间件 ==\n";
$app = \Core\App::boot();
$store = new \Core\Store\MemoryStore();
$auth = new \Core\Http\Middleware\AuthMiddleware($store);
$token = $auth->createSession(['username' => 'admin', 'role' => 'admin']);
$k = new \Core\Http\Kernel($router, null, null, null);
$req = new \Core\Http\Request('GET', '/a', [], [], [], '', [], [], '1.2.3.4', microtime(true));
$res = $k->handle($req);
$ok('Kernel 返回 JSON', $res->status === 200 && str_contains($res->body, '"code":1'));
$req2 = new \Core\Http\Request('GET', '/missing', [], [], [], '', [], [], '', microtime(true));
$ok('404 状态码', $k->handle($req2)->status === 404);

echo "== 中间件鉴权 ==\n";
$pipeline = fn($req) => $auth->process($req, fn($r) => \Core\Http\Response::ok(['user' => $r->user()]));
$noToken = new \Core\Http\Request('GET', '/x', [], [], [], '', [], [], '', microtime(true));
$ok('无 token → 401', $pipeline($noToken)->status === 401);
$withToken = new \Core\Http\Request('GET', '/x', [], [], [], '', ['wb_session' => $token], [], '', microtime(true));
$authed = $pipeline($withToken);
$ok('有 token → 200 且带回用户', $authed->status === 200 && str_contains($authed->body, 'admin'));
$auth->destroySession($token);
$ok('销毁后 401', $pipeline($withToken)->status === 401);

echo "== Store: Memory ==\n";
$ms = new \Core\Store\MemoryStore();
$ms->set('k', ['a' => 1]);
$ok('get/set', $ms->get('k') === ['a' => 1]);
$ok('has', $ms->has('k') === true);
$ok('incr', $ms->incr('n', 3) === 3);
$ok('keys 前缀', in_array('k', $ms->keys('*'), true));
$ms->zadd('z', 1.0, 'a');
$ms->zadd('z', 2.0, 'b');
$ok('zrange 正序', $ms->zrange('z', 0, -1) === ['a', 'b']);
$ok('zrange 倒序', $ms->zrange('z', 0, -1, true) === ['b', 'a']);
$ok('zcard', $ms->zcard('z') === 2);
$ms->ztrim('z', 1);
$ok('ztrim 保留最新', $ms->zcard('z') === 1);
$ms->del('k');
$ok('del', $ms->has('k') === false);

echo "== Store: File ==\n";
$dir = sys_get_temp_dir() . '/wb-store-' . bin2hex(random_bytes(4));
$fs = new \Core\Store\FileStore($dir);
$fs->set('x', ['v' => 42]);
$ok('文件存储 get/set', $fs->get('x') === ['v' => 42]);
$fs->set('ttl', 'v', 1);
$ok('ttl 生效（初始可读）', $fs->get('ttl') === 'v');
$fs->zadd('zl', 1.0, 'a');
$ok('文件 zset', $fs->zrange('zl', 0, -1) === ['a']);
$nulls = [];
foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
    if ($f->isFile()) { @unlink($f->getPathname()); }
}
@rmdir($dir);

echo "== 结构化日志 ==\n";
$logger = new \Core\Log\Logger('test', 10);
$logger->info('hello', ['user' => 'admin', 'token' => 'secret-value']);
$logger->error('boom');
$tail = $logger->tail(10);
$ok('记录条数', count($tail) === 2);
$ok('敏感字段脱敏', ($tail[0]['context']['token'] ?? '') === '***');
$ok('stats 含 error', ($logger->stats()['error'] ?? 0) === 1);
$onlyErr = $logger->tail(10, 'error');
$ok('按级别过滤', count($onlyErr) === 1 && $onlyErr[0]['message'] === 'boom');

echo "== Container ==\n";
$c = new \Core\Container();
$c->bind('x', 1);
$ok('bind', $c->get('x') === 1);
$c->singleton('y', fn() => new \stdClass());
$ok('singleton 同一实例', $c->get('y') === $c->get('y'));


echo "== Store: 通配匹配（回归） ==
";
// 回归：*.info 曾被当成前缀 ".info" 处理，导致群配置全部读不到。
$g = new \Core\Store\FileStore($dir = sys_get_temp_dir() . '/wb-glob-' . bin2hex(random_bytes(4)));
$g->set('123456.info', ['status' => 1]);
$g->set('abc.info', ['status' => 0]);
$g->set('webui:groups', ['1' => ['name' => 'x']]);
$ok('后缀通配 *.info', count($g->keys('*.info')) === 2);
$ok('后缀通配结果正确', in_array('123456.info', $g->keys('*.info'), true));
$ok('前缀通配 123*', $g->keys('123*') === ['123456.info']);
$ok('中间通配 webui:*', in_array('webui:groups', $g->keys('webui:*'), true));
$ok('全通配不遗漏', count($g->keys('*')) === 3);
@unlink($dir . '/probe');

echo "== Config: 写回（回归） ==\n";
// 回归 1：替换串用了字面量前缀，把 "debug = true" 写成了 "1false"。
// 回归 2：只按键名替换，会改到同名键所在的其它小节。
$cfg = \Core\Config::path();
$backup = is_file($cfg) ? \Core\Fs::read($cfg) : null;
try {
    $src = "\n[app]\ndebug = true\nname = app1\n\n[probe]\ndebug = keep\n";
    \Core\Fs::write($cfg, $src);
    \Core\Config::refresh();
    $ok('写入返回 true', \Core\Config::set('app.debug', false) === true);
    \Core\Config::refresh();
    $ok('布尔值被读回为 false', \Core\Config::bool('app.debug') === false);
    $ok('同名键未被误改', \Core\Config::string('probe.debug') === 'keep');
    $head = \Core\Fs::read($cfg);
    $ok('未被写成 "1false"', !str_contains($head, '1false'));
    $ok('行格式保持 "键 = 值"', (bool)preg_match('/^debug = false$/m', $head));
} finally {
    if ($backup !== null) { \Core\Fs::write($cfg, $backup); \Core\Config::refresh(); }
}

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);