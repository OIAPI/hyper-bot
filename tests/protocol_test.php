<?php
/**
 * 协议层单元自检（不需要 Swoole 扩展）
 *
 * 覆盖：Milky 事件转换、Milky CQ 解析、QQ 官方事件转换、Config 读取
 * 运行：php tests/protocol_test.php
 */
declare(strict_types=1);

if (!class_exists('Swoole\Coroutine', false)) {
    eval('namespace Swoole; class Coroutine { public static function getCid(): int { return 0; } public static function getContext(): ?object { return null; } public static function create(callable $fn, ...$args) { return null; } public static function sleep($t) {} }');
}

define('__root__', dirname(__DIR__, 2) . '/hyper.lkaa.top');
require __root__ . '/Include.php';

$fail = 0;
$ok = function (string $name, bool $cond) use (&$fail) {
    echo ($cond ? "  OK   " : "  FAIL ") . $name . PHP_EOL;
    if (!$cond) $fail++;
};

echo "== Milky 事件转换 ==\n";
$milky = [
    'time' => 1234567890,
    'self_id' => 123456789,
    'event_type' => 'message_receive',
    'data' => [
        'message_scene' => 'group',
        'peer_id' => 987654321,
        'message_seq' => 23333,
        'sender_id' => 111222333,
        'time' => 1234567880,
        'segments' => [
            ['type' => 'text', 'data' => ['text' => 'Hello']],
            ['type' => 'mention', 'data' => ['user_id' => 222, 'name' => 'Bob']],
        ],
        'group' => ['group_id' => 987654321, 'group_name' => '测试群', 'member_count' => 10],
        'group_member' => ['user_id' => 111222333, 'nickname' => 'Bob', 'card' => '鲍勃', 'role' => 'admin'],
    ],
];
$ob = \Protocol\Milky\Converter::toOneBot($milky);
$ok('Milky 群消息 → OneBot message/group', ($ob['post_type'] ?? '') === 'message' && ($ob['message_type'] ?? '') === 'group');
$ok('群号与发送者映射正确', ($ob['group_id'] ?? 0) === 987654321 && ($ob['user_id'] ?? 0) === 111222333);
$ok('message_id 复用 message_seq', ($ob['message_id'] ?? 0) === 23333);
$ok('raw_message 还原为 CQ 码', str_starts_with((string)($ob['raw_message'] ?? ''), 'Hello[CQ:at,qq=222,name=Bob]'));
$ok('sender.role 保留', ($ob['sender']['role'] ?? '') === 'admin');

echo "== Milky 通知事件 ==\n";
$recall = \Protocol\Milky\Converter::toOneBot(['time' => 1, 'self_id' => 2, 'event_type' => 'message_recall', 'data' => ['message_scene' => 'group', 'peer_id' => 100, 'message_seq' => 5, 'sender_id' => 7, 'operator_id' => 7]]);
$ok('撤回 → group_recall', ($recall['notice_type'] ?? '') === 'group_recall' && ($recall['group_id'] ?? 0) === 100);
$inc = \Protocol\Milky\Converter::toOneBot(['time' => 1, 'self_id' => 2, 'event_type' => 'group_member_increase', 'data' => ['group_id' => 100, 'user_id' => 8, 'operator_id' => 9]]);
$ok('入群 → group_increase/approve', ($inc['notice_type'] ?? '') === 'group_increase' && ($inc['sub_type'] ?? '') === 'approve');
$unknown = \Protocol\Milky\Converter::toOneBot(['event_type' => 'peer_pin_change', 'time' => 1, 'self_id' => 2, 'data' => []]);
$ok('未知事件返回 null（忽略）', $unknown === null);

echo "== Milky CQ 解析 ==\n";
$segs = \Protocol\Milky\Adapter::cqToSegments('你好[CQ:at,qq=123]世界[CQ:image,file=https://a/b.png]');
$types = array_column($segs, 'type');
$ok('CQ 文本+at+image 解析顺序正确', $types === ['text', 'mention', 'text', 'image']);
$ok('at 段携带 user_id', ($segs[1]['data']['user_id'] ?? null) === 123);
$ok('image 段携带 uri', ($segs[3]['data']['uri'] ?? '') === 'https://a/b.png');

echo "== QQ 官方机器人事件转换 ==\n";
$payload = [
    'id' => 'EVENT1', 'op' => 0, 's' => 1, 't' => 'GROUP_AT_MESSAGE_CREATE',
    'd' => [
        'id' => 'ROBOT1.0_MSG', 'content' => '你好机器人',
        'group_openid' => 'GROUPOPENID', 'timestamp' => '2026-01-01T00:00:00+08:00',
        'author' => ['member_openid' => 'MEMBEROPENID', 'username' => '小明', 'member_role' => 'owner'],
        'attachments' => [['content_type' => 'image/png', 'url' => 'https://img/1.png', 'width' => 10, 'height' => 10]],
    ],
];
$q = \Protocol\QqBot\Converter::toOneBot($payload, 999);
$ok('官方群@消息 → OneBot message/group', ($q['post_type'] ?? '') === 'message' && ($q['message_type'] ?? '') === 'group');
$ok('openid 原样保留', ($q['group_id'] ?? '') === 'GROUPOPENID' && ($q['user_id'] ?? '') === 'MEMBEROPENID');
$ok('文本 + 图片附件解析', ($q['message'][0]['type'] ?? '') === 'text' && ($q['message'][1]['type'] ?? '') === 'image');
$ok('角色映射 owner', ($q['sender']['role'] ?? '') === 'owner');

$c2c = \Protocol\QqBot\Converter::toOneBot(['op' => 0, 's' => 2, 't' => 'C2C_MESSAGE_CREATE', 'd' => ['id' => 'M2', 'content' => 'hi', 'author' => ['user_openid' => 'UOPENID', 'username' => 'u']]], 999);
$ok('单聊消息 → private/friend', ($c2c['message_type'] ?? '') === 'private' && ($c2c['user_id'] ?? '') === 'UOPENID');

echo "== Config 读取 ==\n";
$ok('protocol 段存在', \Utils\Env::get('protocol.default', '') === 'http');
$ok('onebot 正向地址读取', \Protocol\Core\Config::string('onebot.forward.url') === 'ws://127.0.0.1:3001');
$ok('qqbot api 默认域名', \Protocol\Core\Config::string('qqbot.api') === 'https://api.bot.qq.com');
$ok('milky ws 服务路径', \Protocol\Core\Config::string('milky.ws.server_path') === '/milky/event');

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
