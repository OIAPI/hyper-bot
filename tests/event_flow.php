<?php
/**
 * 事件链路自检：Milky 幂等去重 + QQ 官方被动回复上下文（不依赖 Swoole）
 * 运行：php tests/event_flow.php
 */
declare(strict_types=1);

// 同步执行版 Coroutine 桩，让 EventDispatcher 的分发逻辑可测
if (!class_exists('Swoole\Coroutine', false)) {
    eval('namespace Swoole; class Coroutine {
        public static function getCid(): int { return 1; }
        public static function getContext(): ?object { static $c = null; return $c ??= new \ArrayObject(); }
        public static function create(callable $fn, ...$args) { $fn(...$args); return null; }
        public static function sleep($t) {}
    }
    namespace Swoole\Coroutine;
    class System {
        public static function writeFile(string $f, string $data, int $flags = 0): bool {
            @mkdir(dirname($f), 0777, true);
            return @file_put_contents($f, $data, $flags) !== false;
        }
        public static function readFile(string $f): string|false { return @file_get_contents($f); }
    }');
}

require dirname(__DIR__) . '/Include.php';

$fail = 0;
$ok = function (string $name, bool $cond) use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . PHP_EOL;
    if (!$cond) $fail++;
};

echo "== Milky 幂等去重 ==\n";
$event = [
    'time' => 1000, 'self_id' => 42, 'event_type' => 'message_receive',
    'data' => [
        'message_scene' => 'group', 'peer_id' => 555, 'message_seq' => 66, 'sender_id' => 77, 'time' => 1000,
        'segments' => [['type' => 'text', 'data' => ['text' => 'hi']]],
        'group' => ['group_id' => 555, 'group_name' => 'g'],
        'group_member' => ['user_id' => 77, 'nickname' => 'n'],
    ],
];
$r1 = \Protocol\Milky\EventClient::handleEvent($event, 'webhook');
$r2 = \Protocol\Milky\EventClient::handleEvent($event, 'webhook');
$ok('首次事件被接收', ($r1['retcode'] ?? -1) === 0);
$ok('重复事件被去重（retcode 仍为 0）', ($r2['retcode'] ?? -1) === 0);

// 换个 message_seq 应视为新事件
$event2 = $event;
$event2['data']['message_seq'] = 67;
$r3 = \Protocol\Milky\EventClient::handleEvent($event2, 'webhook');
$ok('新 seq 不被误判为重复', ($r3['retcode'] ?? -1) === 0);

echo "== QQ 官方被动回复上下文 ==\n";
$groupPayload = [
    'id' => 'E1', 'op' => 0, 's' => 1, 't' => 'GROUP_AT_MESSAGE_CREATE',
    'd' => [
        'id' => 'MSG_A', 'content' => 'hello', 'group_openid' => 'GOPENID',
        'author' => ['member_openid' => 'MOPENID', 'username' => 'u'],
    ],
];
\Protocol\QqBot\EventClient::handlePayload($groupPayload, 'webhook');
$ctx1 = \Protocol\QqBot\EventClient::passiveContext('group:GOPENID');
$ok('群消息登记被动上下文', $ctx1 !== null && $ctx1['msg_id'] === 'MSG_A');
$ok('首次 msg_seq = 1', ($ctx1['msg_seq'] ?? 0) === 1);
$ctx2 = \Protocol\QqBot\EventClient::passiveContext('group:GOPENID');
$ok('第二次 msg_seq 递增到 2', ($ctx2['msg_seq'] ?? 0) === 2);
for ($i = 3; $i <= 5; $i++) { \Protocol\QqBot\EventClient::passiveContext('group:GOPENID'); }
$ctx6 = \Protocol\QqBot\EventClient::passiveContext('group:GOPENID');
$ok('超过 5 次后返回 null（需转主动消息）', $ctx6 === null);

// 单聊上限 4 次
\Protocol\QqBot\EventClient::handlePayload(['op' => 0, 's' => 2, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['id' => 'MSG_B', 'content' => 'x', 'author' => ['user_openid' => 'UOPENID']]], 'webhook');
for ($i = 1; $i <= 4; $i++) { \Protocol\QqBot\EventClient::passiveContext('user:UOPENID'); }
$ok('单聊超过 4 次后返回 null', \Protocol\QqBot\EventClient::passiveContext('user:UOPENID') === null);

echo "== intents 计算 ==\n";
$ok('默认 intents = 1<<25 | 1<<24', \Protocol\QqBot\EventClient::intents() === ((1 << 25) | (1 << 24)));

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);