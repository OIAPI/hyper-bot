<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\AccountContext;
use Core\Bot\Accounts;
use Core\Extension\Hooks;
use Protocol\Core\EventDispatcher;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

$tmp = sys_get_temp_dir() . '/hyper-event-account-' . getmypid() . '.json';
@unlink($tmp);
Accounts::path($tmp);
file_put_contents($tmp, json_encode(['accounts' => [[
    'id' => 'seed', 'name' => 'seed', 'protocol' => 'onebot', 'self_id' => '1', 'enable' => false,
]]]));
Accounts::upsert(['id' => 'acc-a', 'name' => 'A', 'protocol' => 'onebot', 'self_id' => '10001', 'api' => 'http://127.0.0.1:1']);

$seen = [];
Hooks::on('event.before_dispatch', function (array $event) use (&$seen): array {
    $seen[] = AccountContext::id();
    return $event;
}, 1, 'test:account');

$event = [
    'post_type' => 'message', 'message_type' => 'group',
    'self_id' => '99999', 'group_id' => '123456',
    'user_id' => '10001', 'raw_message' => 'hi', 'time' => time(),
];
EventDispatcher::dispatch($event, null);
$check('单账号 self_id 不匹配时回退到唯一账号', ($seen[0] ?? '') === 'acc-a');

// 第二个账号后不再猜测，必须 self_id 命中
Accounts::upsert(['id' => 'acc-b', 'name' => 'B', 'protocol' => 'onebot', 'self_id' => '10002', 'api' => 'http://127.0.0.1:2']);
$seen = [];
EventDispatcher::dispatch($event, null);
$check('多账号 self_id 不匹配时不乱猜', ($seen[0] ?? '') === '');
$seen = [];
$event['self_id'] = '10002';
EventDispatcher::dispatch($event, null);
$check('多账号按 self_id 命中账号', ($seen[0] ?? '') === 'acc-b');

Hooks::reset();
AccountContext::reset();
@unlink($tmp);
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
