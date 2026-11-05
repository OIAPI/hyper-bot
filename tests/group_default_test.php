<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\App;
use Core\Bot\AccountContext;
use Core\Bot\Context;
use Core\Bot\Event;
use Protocol\OneBot\Adapter;

$app = App::boot();
$store = $app->store;
$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

// 1) 群开关：首次默认关闭，需要显式打开
$gid = 'test-group-default-' . getmypid();
$key = $gid . '.info';
$store->del($key);
$method = new ReflectionMethod(App::class, 'groupEnabled');
$groupMsg = Event::fromArray([
    'post_type' => 'message', 'message_type' => 'group',
    'group_id' => $gid, 'user_id' => '10001', 'raw_message' => 'hello',
]);
$privateMsg = Event::fromArray([
    'post_type' => 'message', 'message_type' => 'private',
    'user_id' => '10001', 'raw_message' => 'hi',
]);
$check('未登记群默认关闭', $method->invoke($app, $groupMsg) === false);
$check('不会自动写入群配置', $store->get($key) === null);
$store->set($key, ['status' => 1, 'worker' => []]);
$check('打开后放行', $method->invoke($app, $groupMsg) === true);
$store->set($key, ['status' => 0, 'worker' => []]);
$check('关闭后拦截', $method->invoke($app, $groupMsg) === false);
$check('私聊不受群开关影响', $method->invoke($app, $privateMsg) === true);

// 2) BaseApp 首次读群信息仍是默认关闭，已有状态保留
$plugin = new \App\Plugins\Demo();
$gid2 = 'test-baseapp-' . getmypid();
$key2 = $gid2 . '.info';
$store->del($key2);
$info = $plugin->getGroupInfo($gid2);
$check('BaseApp getGroupInfo 新群默认关闭', ((int)($info->status ?? 1)) === 0);
$store->del($key2);
$info = $plugin->initGroupInfo($gid2);
$check('BaseApp initGroupInfo 新群默认关闭', ((int)($info->status ?? 1)) === 0);
$store->set($key2, ['status' => 1, 'worker' => []]);
$info = $plugin->initGroupInfo($gid2);
$check('BaseApp 保留已有 status=1', ((int)($info->status ?? 0)) === 1);
$store->del($key2);
$store->del($key);

// 3) 插件发消息复用来事件适配器（含 fd）
$source = new Adapter([], 42);
$ctx = new Context($groupMsg, $source);
Context::run($ctx, function () use ($source, $check): void {
    $check('上下文内复用来源适配器', Adapter::currentAdapter() === $source);
});
$check('上下文外新建适配器', Adapter::currentAdapter() !== $source);

// 4) 账号上下文：非协程环境仍可正常设置/清理
AccountContext::use(['id' => 'acc-1', 'protocol' => 'onebot', 'api' => 'http://x']);
$check('AccountContext 设置', AccountContext::id() === 'acc-1');
AccountContext::reset();
$check('AccountContext 清理', AccountContext::current() === null);

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
