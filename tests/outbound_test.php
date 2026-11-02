<?php
/**
 * 发送链路（出站）自检。
 *
 * 接收侧是「各协议事件 → OneBot11 事件」；发送侧是反方向：
 * 业务代码用 OneBot11 风格 action（send_group_msg 等），由 Protocol\Core\Bridge
 * 按当前事件上下文挑协议后端，没有上下文时回落 {cq.api} HTTP 协议端。
 *
 * 运行：php tests/outbound_test.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/Include.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $extra = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($extra !== '' ? "  ({$extra})" : '') . "\n"; }
}

/** 记录收到的 action，按适配器协议返回统一结构 */
final class RecordingAdapter extends \Protocol\Core\Adapter {
    protected string $name = 'recording';
    protected string $title = '记录用适配器';
    public array $calls = [];
    public array $handles = [];

    public function call(string $action, array $params = [], string $method = 'POST'): object {
        $this->calls[] = ['action' => $action, 'params' => $params, 'method' => $method];
        return (object)[
            'status'  => 'ok',
            'retcode' => 0,
            'data'    => (object)['message_id' => 'fake-' . count($this->calls)],
            'message' => '',
        ];
    }

    public function handles(string $action): bool {
        return $this->handles === [] || in_array($action, $this->handles, true);
    }
}

echo "== 出站：Bridge 按上下文转发 ==\n";

$rec = new RecordingAdapter();
\Protocol\Core\Context::bind($rec);
ok('上下文已绑定', \Protocol\Core\Context::current() === $rec);

$res = \Protocol\Core\Bridge::call('send_group_msg', [
    'group_id' => 123456,
    'message'  => [['type' => 'text', 'data' => ['text' => '你好']]],
]);
ok('send_group_msg 转发到适配器', count($rec->calls) === 1 && $rec->calls[0]['action'] === 'send_group_msg');
ok('群号原样传递', ($rec->calls[0]['params']['group_id'] ?? null) === 123456);
ok('消息段原样传递', ($rec->calls[0]['params']['message'][0]['data']['text'] ?? '') === '你好');
ok('返回统一结构 status/data', ($res->status ?? '') === 'ok' && ($res->data->message_id ?? '') === 'fake-1');

\Protocol\Core\Bridge::call('send_private_msg', ['user_id' => 42, 'message' => 'hi']);
ok('send_private_msg 同样转发', count($rec->calls) === 2 && $rec->calls[1]['action'] === 'send_private_msg');
ok('私聊目标正确', ($rec->calls[1]['params']['user_id'] ?? null) === 42);

$rec->handles = ['send_group_msg'];
$rec->calls = [];
\Protocol\Core\Bridge::call('send_group_msg', ['group_id' => 1]);
\Protocol\Core\Bridge::call('get_group_info', ['group_id' => 1]);
ok('handles=false 的 action 不交给该适配器', count($rec->calls) === 1);

$thrower = new class extends \Protocol\Core\Adapter {
    protected string $name = 'thrower';
    public function call(string $action, array $params = [], string $method = 'POST'): object {
        throw new \RuntimeException('模拟适配器故障');
    }
};
\Protocol\Core\Context::bind($thrower);
$res = \Protocol\Core\Bridge::call('send_group_msg', ['group_id' => 9]);
ok('适配器异常不冒泡', is_object($res) && isset($res->status));

echo "\n== 出站：响应形态与旧 HTTP 直连一致 ==\n";

$shape = new class extends \Protocol\Core\Adapter {
    protected string $name = 'shape';
    public function call(string $action, array $params = [], string $method = 'POST'): object {
        return (object)[
            'status' => 'ok', 'retcode' => 0,
            'data' => ['message_id' => 456, 'list' => [['id' => 1]]],
            'message' => '',
        ];
    }
};
\Protocol\Core\Context::bind($shape);
$r = \Protocol\Core\Bridge::call('send_group_msg', ['group_id' => 1, 'message' => 'hi']);
ok('data 归一化为 stdClass（旧代码写 $res->data->message_id）', is_object($r->data));
ok('$res->data->message_id 可读', ($r->data->message_id ?? null) === 456);
ok('列表仍为数组（旧形态即 stdClass[]）', is_array($r->data->list) && ($r->data->list[0]->id ?? null) === 1);

\Protocol\Core\Context::clear();
ok('解绑后无当前适配器', \Protocol\Core\Context::current() === null);
ok('无适配器时 hasAdapter() = false（回落 HTTP）', \Protocol\Core\Bridge::hasAdapter() === false);

echo "\n== 出站：各协议参数映射 ==\n";

$map = new ReflectionMethod(\Protocol\Milky\Adapter::class, 'mapAction');
$milky = new \Protocol\Milky\Adapter(['api' => 'http://127.0.0.1:3010']);

$g = $map->invoke($milky, 'send_group_msg', [
    'group_id' => 100,
    'message'  => [['type' => 'text', 'data' => ['text' => 'a']]],
]);
ok('Milky 群消息 → send_group_message', ($g[0] ?? '') === 'send_group_message');
ok('Milky 群号转 int', ($g[1]['group_id'] ?? null) === 100);
ok('Milky 文本段转换', ($g[1]['message'][0]['type'] ?? '') === 'text');
ok('Milky 群里 @全体 → mention_all', (function () use ($map, $milky) {
    $r = $map->invoke($milky, 'send_group_msg', [
        'group_id' => 1,
        'message'  => [['type' => 'at', 'data' => ['qq' => 'all']]],
    ]);
    return ($r[1]['message'][0]['type'] ?? '') === 'mention_all';
})());
ok('Milky 私聊 → send_private_message', ($map->invoke($milky, 'send_private_msg', [
    'user_id' => 200, 'message' => [['type' => 'text', 'data' => ['text' => 'b']]],
])[0] ?? '') === 'send_private_message');
ok('Milky 合并转发 → send_group_message + forward 段', (function () use ($map, $milky) {
    $r = $map->invoke($milky, 'send_group_forward_msg', ['group_id' => 1, 'messages' => []]);
    return ($r[0] ?? '') === 'send_group_message' && ($r[1]['message'][0]['type'] ?? '') === 'forward';
})());

$qq = new \Protocol\QqBot\Adapter(['app_id' => '1', 'client_secret' => 'x']);
$call = new ReflectionMethod(\Protocol\QqBot\Adapter::class, 'call');
$r1 = $call->invoke($qq, 'send_group_msg', ['group_id' => 'GOPEN', 'message' => 'hello']);
$r2 = $call->invoke($qq, 'send_private_msg', ['user_id' => 'UOPEN', 'message' => 'hello']);
ok('QQ 官方群消息返回统一结构', is_object($r1) && isset($r1->status));
ok('QQ 官方私聊消息返回统一结构', is_object($r2) && isset($r2->status));
ok('未配置凭证时明确失败而非静默成功', ($r1->status ?? '') === 'failed' && ($r1->retcode ?? 0) !== 0);
ok('QQ 官方不支持的动作给 -501 并说明原因', (function () use ($qq, $call) {
    $r = $call->invoke($qq, 'get_group_list', []);
    return ($r->retcode ?? 0) === -501 && str_contains((string)($r->message ?? ''), '不支持');
})());

$ob = new \Protocol\OneBot\Adapter([]);
$r3 = $ob->call('send_group_msg', ['group_id' => 1, 'message' => 'x']);
ok('OneBot 无 WS 时回落 HTTP 并返回统一结构', is_object($r3) && isset($r3->status) && $r3->status === 'failed');

echo "\n== 出站：Bridge 自动补齐会话参数 ==\n";

$rec2 = new RecordingAdapter();
\Protocol\Core\Context::bind($rec2);
\Utils\Cache::set('param', (object)['group_id' => 888, 'user_id' => 999, 'message_type' => 'group']);

\Protocol\Core\Bridge::call('delete_msg', ['message_id' => 77]);
ok('delete_msg 自动补 group_id', ($rec2->calls[0]['params']['group_id'] ?? null) === 888, json_encode($rec2->calls[0]['params'] ?? []));
ok('delete_msg 自动补 user_id', ($rec2->calls[0]['params']['user_id'] ?? null) === 999);

$rec2->calls = [];
\Protocol\Core\Bridge::call('delete_msg', ['message_id' => 77, 'group_id' => 555]);
ok('显式传入的 group_id 不被覆盖', ($rec2->calls[0]['params']['group_id'] ?? null) === 555);

$rec2->calls = [];
\Protocol\Core\Bridge::call('send_group_msg', ['group_id' => 1, 'message' => 'x']);
ok('非 recall/get 类动作不补参数', !isset($rec2->calls[0]['params']['message_type']));

\Utils\Cache::set('param', null);

echo "\n" . ($fail === 0 ? "全部通过（{$pass} 项）\n" : "失败 {$fail} 项\n");
exit($fail === 0 ? 0 : 1);
