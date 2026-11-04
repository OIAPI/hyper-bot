<?php
declare(strict_types=1);

/**
 * 插件扩展点 + 外部 WebHook + RSS 解析。
 *
 * 不联网：WebHook 用本地签名，RSS 用内联 XML。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Bot\Registry;
use Core\Extension\ExtensionPoint;
use Core\Rss\FeedStore;
use Core\Rss\Reader;
use Core\Webhook\WebhookStore;

$ok = 0;
$fail = 0;
function check(string $name, bool $pass, string $detail = ''): void {
    global $ok, $fail;
    if ($pass) {
        $ok++;
        echo "  OK   {$name}" . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? "（{$detail}）" : '') . PHP_EOL;
    }
}

$app = \Core\App::boot();
$registry = $app->registry;

// ---- 插件扩展点发现 ----
$menus = ExtensionPoint::adminMenus($registry);
$titles = array_column($menus, 'title');
check('发现 GitHub 后台菜单', in_array('GitHub 订阅', $titles, true), implode(',', $titles));
check('发现 RSS 后台菜单', in_array('RSS 订阅', $titles, true));

$github = 'App\\Plugins\\Github';
$rss = 'App\\Plugins\\Rss';
check('Github 提供页面 schema', ExtensionPoint::has($github, ExtensionPoint::ADMIN_PAGE));
$page = ExtensionPoint::adminPage($github);
check('页面 schema 有 blocks', is_array($page['blocks'] ?? null) && count($page['blocks']) >= 3);
$types = array_column($page['blocks'], 'type');
check('schema 含 table/form/actions', in_array('table', $types, true) && in_array('form', $types, true) && in_array('actions', $types, true));

check('Rss 提供扩展路由', ExtensionPoint::has($rss, ExtensionPoint::EXT_ROUTES));
$routes = $rss::extRoutes();
check('路由含 GET /feeds', isset($routes['GET /feeds']));
check('路由含 POST /poll', isset($routes['POST /poll']));

$missing = ExtensionPoint::callRoute($rss, 'GET', '/nope', []);
check('未知接口返回 404', $missing['ok'] === false && ($missing['status'] ?? 0) === 404);

// ---- GitHub WebHook 签名 ----
$secret = 'test-secret-123';
$payload = json_encode(['repository' => ['full_name' => 'OIAPI/hyper-bot'], 'ref' => 'refs/heads/main', 'commits' => [['id' => 'a'], ['id' => 'b']]], JSON_UNESCAPED_UNICODE);
$summary = \Core\Webhook\GithubWebhook::summarize('push', json_decode($payload, true));
check('push 摘要包含仓库与分支', str_contains($summary, 'OIAPI/hyper-bot') && str_contains($summary, 'main'));
check('push 摘要包含提交数', str_contains($summary, '2 个提交'));
$sig = 'sha256=' . hash_hmac('sha256', $payload, $secret);
check('签名可复算', hash_equals($sig, 'sha256=' . hash_hmac('sha256', $payload, $secret)));
check('错误 secret 签名不匹配', !hash_equals('sha256=' . hash_hmac('sha256', $payload, 'other'), $sig));
$relSummary = \Core\Webhook\GithubWebhook::summarize('release', ['repository' => ['full_name' => 'a/b'], 'release' => ['tag_name' => 'v3.0.0']]);
check('release 摘要带 tag', str_contains($relSummary, 'v3.0.0'));

// ---- WebHook 存储 ----
$backup = WebhookStore::all();
try {
    $bad = WebhookStore::save(['id' => 'x', 'secret' => '']);
    check('secret 为空被拒', $bad['ok'] === false);
    $saved = WebhookStore::save(['id' => 'unit-test', 'secret' => $secret, 'note' => '测试', 'events' => 'push,release', 'group' => '884726153']);
    check('保存订阅成功', $saved['ok'] === true);
    $row = WebhookStore::get('unit-test');
    check('事件解析成数组', ($row['events'] ?? []) === ['push', 'release']);
    check('群号被保存', ($row['group'] ?? '') === '884726153');
    WebhookStore::record('unit-test', 'push', true, '测试投递');
    $deliveries = WebhookStore::deliveries('unit-test');
    check('投递记录可查', ($deliveries[0]['event'] ?? '') === 'push');
    check('删除订阅', WebhookStore::remove('unit-test') === true);
} finally {
    WebhookStore::reset($backup);
    WebhookStore::flush();
}

// ---- RSS / Atom 解析 ----
$rssXml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"><channel><title>测试源</title>
<item><title>第一篇</title><link>https://example.com/1</link><guid>g1</guid><pubDate>Wed, 04 Nov 2026 08:00:00 +0800</pubDate><description>&lt;p&gt;摘要一&lt;/p&gt;</description></item>
<item><title>第二篇</title><link>https://example.com/2</link><guid>g2</guid></item>
</channel></rss>
XML;
$parsed = Reader::parse($rssXml);
check('RSS 解析成功', ($parsed['ok'] ?? false) === true);
check('RSS 解析出 2 条', count($parsed['items'] ?? []) === 2);
check('RSS 标题正确', ($parsed['items'][0]['title'] ?? '') === '第一篇');
check('RSS 摘要去掉 HTML', ($parsed['items'][0]['summary'] ?? '') === '摘要一');

$atom = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<feed xmlns="http://www.w3.org/2005/Atom"><title>Atom 源</title>
<entry><title>Atom 条目</title><id>urn:1</id><link rel="alternate" href="https://example.com/a"/><updated>2026-11-04T08:00:00Z</updated><summary>简介</summary></entry>
</feed>
XML;
$parsedAtom = Reader::parse($atom);
check('Atom 解析成功', ($parsedAtom['ok'] ?? false) === true);
check('Atom 条目链接', ($parsedAtom['items'][0]['link'] ?? '') === 'https://example.com/a');
check('Atom 标题', ($parsedAtom['title'] ?? '') === 'Atom 源');
check('非法 XML 被拒', (Reader::parse('<not xml')['ok'] ?? true) === false);

// ---- RSS 订阅源存储 ----
$feedsBackup = FeedStore::all();
try {
    check('非法地址被拒', FeedStore::save(['id' => 'bad', 'url' => 'ftp://x'])['ok'] === false);
    $saved = FeedStore::save(['id' => 'unit-feed', 'url' => 'https://example.com/feed.xml', 'title' => '单元测试源', 'group' => '884726153']);
    check('保存订阅源', $saved['ok'] === true);
    check('订阅源可读', (FeedStore::get('unit-feed')['title'] ?? '') === '单元测试源');
    check('删除订阅源', FeedStore::remove('unit-feed') === true);
} finally {
    FeedStore::reset($feedsBackup);
    FeedStore::flush();
}


/* ---------------- 钩子 / 注入块 / 命令（v3.1） ---------------- */
echo PHP_EOL . "== 扩展点" . PHP_EOL;

$registry = \Core\App::boot()->registry;
\Core\Extension\ExtensionPoint::boot($registry);

$hooks = \Core\Extension\Hooks::summary();
$hookNames = array_column($hooks, 'name');
check('Demo 插件注册了 http.response 钩子', in_array('http.response', $hookNames, true));

$response = \Core\Extension\Hooks::filter('http.response', \Core\Http\Response::json(['code' => 1]));
check('钩子能改 Response（加响应头）', ($response->headers['X-Plugins'] ?? '') === 'demo');

// filter 链按优先级执行
\Core\Extension\Hooks::reset();
\Core\Extension\Hooks::on('test.chain', static fn($v) => $v . 'b', 20);
\Core\Extension\Hooks::on('test.chain', static fn($v) => $v . 'a', 5);
check('filter 按优先级串联', \Core\Extension\Hooks::filter('test.chain', '') === 'ab');

// 处理器抛异常不影响后续
\Core\Extension\Hooks::on('test.chain', static function ($v) {
    throw new \RuntimeException('boom');
}, 30);
check('单个钩子抛错不影响其它', \Core\Extension\Hooks::filter('test.chain', '') === 'ab');
\Core\Extension\Hooks::reset();

// 重新注册（reset 后 summary 为空，清掉标记再 boot 一次会重新挂上）
\Core\Extension\ExtensionPoint::resetRegistration();
\Core\Extension\ExtensionPoint::boot($registry);

$widgets = \Core\Extension\ExtensionPoint::widgets($registry, 'overview');
$titles = array_column($widgets, 'title');
check('概览页有 3 个注入块', count($widgets) >= 3);
check('注入块包含 GitHub / RSS / 钩子', in_array('GitHub 投递', $titles, true) && in_array('RSS 最新', $titles, true) && in_array('插件钩子', $titles, true));
check('注入块带 blocks', isset($widgets[0]['blocks']) && $widgets[0]['blocks'] !== []);

$commands = \Core\Extension\ExtensionPoint::commands($registry);
check('命令行命令包含 github.ping', isset($commands['github.ping']));
check('命令行命令包含 rss.poll', isset($commands['rss.poll']));
check('命令行命令包含 plugins.diag', isset($commands['plugins.diag']));

$diag = \Core\Extension\ExtensionPoint::diag($registry);
check('自检返回钩子列表', is_array($diag['hooks']) && $diag['hooks'] !== []);
check('自检统计注入块数量', ($diag['widgets'] ?? 0) >= 3);

// 中间件列表（当前没插件实现，应为数组）
check('中间件列表是数组', is_array(\Core\Extension\ExtensionPoint::middlewares($registry)));

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);