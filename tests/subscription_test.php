<?php
/**
 * 消息订阅回归测试（core/Bot/Subscription.php）
 *
 * 重点覆盖三类问题：
 *   1. v1/v2 两套配置键的兼容与优先级；
 *   2. 「显式清空」不能被 v1 老键复活（曾经的 bug：WebUI 取消全部勾选、重启后又全开）；
 *   3. 目录完整性：三个协议转换器能产出的 notice_type 必须全部在 noticeCatalog 里，
 *      漏一个 = 用户界面上根本没有那个开关。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Bot\Event;
use Core\Bot\Subscription;
use Core\Config;

$pass = 0;
$fail = 0;
$sandbox = sys_get_temp_dir() . '/sub-test-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0775, true);
$origPath = __root__ . '/Config.ini';

function ok(string $label, mixed $actual, mixed $expected): void {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }
    $fail++;
    printf("  \033[31m✗\033[0m %s\n      期望 %s\n      实际 %s\n",
        $label,
        json_encode($expected, 256 | JSON_UNESCAPED_UNICODE),
        json_encode($actual, 256 | JSON_UNESCAPED_UNICODE));
}

function fixture(string $name, string $body): void {
    global $sandbox;
    $path = $sandbox . '/' . $name;
    file_put_contents($path, $body);
    Config::path($path);
    Config::refresh();
}

function ev(array $data): Event {
    return Event::fromArray($data);
}

// ---------------------------------------------------------------- 1
echo "\n=== 1. v1 三个老小节仍能读出来 ===\n";
fixture('v1.ini', "[type]\nmessage = true\nnotice = true\nrequest = false\nmeta_event = true\nignore_self = true\nignore_robots = true\n[message]\ntype = protected\n[notice]\ngroup_increase = 1\ngroup_recall = 1\n");
$s = Subscription::fromConfig();
ok('message', $s->message, true);
ok('request=false', $s->request, false);
ok('meta_event=true', $s->metaEvent, true);
ok('protected 归一为 private', $s->messageScope, 'private');
ok('通知白名单来自 [notice]', $s->noticeTypes, ['group_increase', 'group_recall']);
ok('空群消息被 scope 拒收', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'group', 'raw_message' => 'hi'])), '消息范围仅私聊，当前 group');

// ---------------------------------------------------------------- 2
echo "\n=== 2. v2 新键优先于 v1 ===\n";
fixture('v2.ini', "[type]\nmessage = true\nnotice = true\n[subscription]\nmessage = false\nmessage_scope = all\nnotice_types = group_increase, group_ban\nignore_self = false\nignore_robots = false\nrobot_prefix = 3889\n");
$s = Subscription::fromConfig();
ok('message 被 v2 关掉', $s->message, false);
ok('scope=all', $s->messageScope, 'all');
ok('notice_types 来自 v2', $s->noticeTypes, ['group_increase', 'group_ban']);
ok('ignore_self=false', $s->ignoreSelf, false);

// ---------------------------------------------------------------- 3
echo "\n=== 3. 显式清空不会被 v1 复活（P0）===\n";
fixture('clear.ini', "[notice]\ngroup_increase = 1\nfriend_add = 1\n[subscription]\nnotice_types =\n");
$s = Subscription::fromConfig();
ok('清空后为空数组', $s->noticeTypes, []);
ok('确实存在该键', Config::has('subscription.notice_types'), true);
ok('通知被拒收', $s->rejectReason(ev(['post_type' => 'notice', 'notice_type' => 'group_increase'])), '未启用任何通知类型');

// ---------------------------------------------------------------- 4
echo "\n=== 4. 只有 [subscription] 但没写 notice_types 时才回退 v1 ===\n";
fixture('partial.ini', "[notice]\ngroup_increase = 1\n[subscription]\nmessage = true\n");
$s = Subscription::fromConfig();
ok('回退到 v1 白名单', $s->noticeTypes, ['group_increase']);

// ---------------------------------------------------------------- 5
echo "\n=== 5. 消息范围与频道消息 ===\n";
fixture('scope.ini', "[subscription]\nmessage_scope = group\n");
$s = Subscription::fromConfig();
ok('群消息通过', $s->allows(ev(['post_type' => 'message', 'message_type' => 'group', 'group_id' => '1', 'raw_message' => 'x'])), true);
ok('QQ 频道消息按群聊处理', $s->allows(ev(['post_type' => 'message', 'message_type' => 'guild', 'group_id' => 'g1', 'raw_message' => 'x'])), true);
ok('私聊被拒', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'private', 'raw_message' => 'x'])), '消息范围仅群聊，当前 private');
ok('群消息缺 group_id 被拒', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'group', 'raw_message' => 'x'])), '群消息缺少 group_id');
ok('空消息体被拒', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'group', 'group_id' => '1'])), '缺少消息内容 raw_message');

// ---------------------------------------------------------------- 6
echo "\n=== 6. 自身与机器人过滤 ===\n";
fixture('filter.ini', "[subscription]\nmessage_scope = all\nignore_self = true\nignore_robots = true\nrobot_prefix = 3889\n");
$s = Subscription::fromConfig();
ok('忽略自己', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'group', 'group_id' => '1', 'user_id' => '100', 'self_id' => '100', 'raw_message' => 'x'])), '忽略机器人自身消息');
ok('忽略官方机器人前缀', $s->rejectReason(ev(['post_type' => 'message', 'message_type' => 'group', 'group_id' => '1', 'user_id' => '38890001', 'self_id' => '100', 'raw_message' => 'x'])), '忽略官方机器人消息');
ok('正常用户通过', $s->allows(ev(['post_type' => 'message', 'message_type' => 'group', 'group_id' => '1', 'user_id' => '200', 'self_id' => '100', 'raw_message' => 'x'])), true);

// ---------------------------------------------------------------- 7
echo "\n=== 7. 通道开关 ===\n";
fixture('chan.ini', "[subscription]\nnotice = false\nrequest = false\nmeta_event = false\n");
$s = Subscription::fromConfig();
ok('notice 关闭', $s->rejectReason(ev(['post_type' => 'notice', 'notice_type' => 'group_increase'])), '类型 notice 未订阅');
ok('request 关闭', $s->rejectReason(ev(['post_type' => 'request', 'request_type' => 'friend'])), '类型 request 未订阅');
ok('meta_event 关闭', $s->rejectReason(ev(['post_type' => 'meta_event', 'meta_event_type' => 'heartbeat'])), '类型 meta_event 未订阅');
ok('缺少 post_type 被拒', $s->rejectReason(ev(['post_type' => ''])), '缺少 post_type');

// ---------------------------------------------------------------- 8
echo "\n=== 8. 目录完整性：转换器产出的 notice_type 必须都有开关 ===\n";
$catalog = Subscription::noticeCatalog();
$sources = [
    'protocol/OneBot/Adapter.php',
    'protocol/Milky/Converter.php',
    'protocol/QqBot/Converter.php',
];
$found = [];
foreach ($sources as $rel) {
    $path = __root__ . '/' . $rel;
    $code = @file_get_contents($path);
    if ($code === false) {
        ok('可读 ' . $rel, false, true);
        continue;
    }
    foreach (preg_split('/\R/', $code) ?: [] as $line) {
        if (!str_contains($line, 'notice_type')) {
            continue;
        }
        if (preg_match_all("/'([a-z][a-z0-9_]{2,})'/", $line, $m)) {
            foreach ($m[1] as $literal) {
                // 同一行里的场景/字段名不是通知类型，排除掉
                $noise = ['notice_type', 'post_type', 'group', 'private', 'guild', 'single', 'channel'];
                if (in_array($literal, $noise, true)) {
                    continue;
                }
                $found[$literal] = $rel;
            }
        }
    }
}
$missing = [];
foreach ($found as $type => $rel) {
    if (!isset($catalog[$type])) {
        $missing[$type] = $rel;
    }
}
ok('没有遗漏的通知类型', $missing, []);
ok('目录规模合理（>= 25 项）', count($catalog) >= 25, true);

// ---------------------------------------------------------------- 9
echo "\n=== 9. 分组目录与扁平目录一致 ===\n";
$flat = [];
$dupes = [];
foreach (Subscription::noticeGroups() as $group) {
    ok('分组有标题：' . ($group['title'] ?? '?'), isset($group['title']) && $group['title'] !== '', true);
    foreach ($group['types'] as $key => $label) {
        if (isset($flat[$key])) {
            $dupes[] = $key;
        }
        $flat[$key] = $label;
        if (!is_string($label) || $label === '') {
            $dupes[] = $key . '(空标签)';
        }
    }
}
ok('分组间没有重复键', $dupes, []);
ok('扁平目录 = 分组展开', $flat, Subscription::noticeCatalog());

// ---------------------------------------------------------------- 10
echo "\n=== 10. 保存后回读一致（含清空）===\n";
fixture('save.ini', "[subscription]\nmessage = true\nmessage_scope = group\nnotice_types = group_increase\n");
$s = Subscription::fromConfig();
ok('保存成功', $s->save([
    'notice'        => true,
    'message_scope' => 'all',
    'notice_types'  => ['group_ban', 'poke'],
    'ignore_self'   => false,
    'robot_prefix'  => '1234',
]), true);
$s2 = Subscription::fromConfig();
ok('scope 已改', $s2->messageScope, 'all');
ok('通知类型已改', $s2->noticeTypes, ['group_ban', 'poke']);
ok('robot_prefix 已改', $s2->robotPrefix, '1234');
ok('ignore_self 已改', $s2->ignoreSelf, false);

ok('清空后保存成功', $s2->save(['notice_types' => []]), true);
$s3 = Subscription::fromConfig();
ok('清空后回读为空', $s3->noticeTypes, []);
ok('再次保存不会报错', $s3->save(['notice_types' => []]), true);

ok('非法 scope 被拒', $s3->save(['message_scope' => 'nonsense']), false);

// ---------------------------------------------------------------- 清理
foreach (glob($sandbox . '/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($sandbox);
Config::path($origPath);
Config::refresh();

printf("\n%s 通过 %d，失败 %d\n", $fail === 0 ? '\033[32m全部通过\033[0m' : '\033[31m有失败\033[0m', $pass, $fail);
exit($fail === 0 ? 0 : 1);
