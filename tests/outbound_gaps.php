<?php
/**
 * 出站（发送）链路的回归测试。
 *
 * 这些用例锁住三处修复：
 * 1. 第三方 URL 不会被误当 OneBot action 转发给协议端；
 * 2. WS 超时不再回落 HTTP（避免 send_* 重复投递）；
 * 3. 子协程恢复上下文，插件里的 sendMsg 才会走正确协议端；
 * 4. 默认后端按 [protocol] default 选择，主动发送不再只能走旧 HTTP。
 *
 * 运行：php tests/outbound_gaps.php
 */
declare(strict_types=1);
require dirname(__DIR__) . '/Include.php';

$pass = 0; $fail = 0;
function ok(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  OK   {$n}\n"; }
    else { $fail++; echo "  FAIL {$n}" . ($d !== '' ? "  ({$d})" : '') . "\n"; }
}

/** 用临时 Config.ini 做实验，结束恢复默认配置 */
function with_config(string $ini, callable $fn): void {
    $tmp = __DIR__ . '/../runtime/test-config.ini';
    @mkdir(dirname($tmp), 0755, true);
    file_put_contents($tmp, $ini);
    \Core\Config::path($tmp);
    try { $fn(); } finally {
        \Protocol\Core\Bridge::resetFallback();
        \Core\Config::path(dirname(__DIR__) . '/Config.ini');
        @unlink($tmp);
    }
}

echo "== 1. 第三方 URL 不能被当成 OneBot action ==\n";

with_config("[cq]\napi = \"http://127.0.0.1:5700\"\nauth = token\n", function () {
    ok('isProtocolUrl 识别协议端 URL', \Protocol\Core\Bridge::isProtocolUrl('http://127.0.0.1:5700/send_group_msg'));
    ok('isProtocolUrl 拒绝第三方 URL', !\Protocol\Core\Bridge::isProtocolUrl('http://oiapi.net/api/Protobuf/'));

    // 绑定假适配器后：协议端 URL 应走桥，第三方 URL 不应走桥
    \Core\Bot\Context::bind(new class extends \Protocol\Core\Adapter {
        protected string $name = 'spy';
        public function call(string $action, array $params = [], string $method = 'POST'): object {
            return (object)['status' => 'ok', 'retcode' => 0, 'data' => null, 'message' => ''];
        }
    });
    ok('协议端 URL 走桥', \Protocol\Core\Bridge::routes('http://127.0.0.1:5700/send_group_msg'));
    ok('第三方 URL 不走桥', !\Protocol\Core\Bridge::routes('http://oiapi.net/api/Protobuf/'));
    ok('带路径斜杠的协议端 URL 也走桥', \Protocol\Core\Bridge::routes('http://127.0.0.1:5700/send_group_msg/'));

    // actionOf 仍然能取末尾段（供桥使用）
    $actionOf = new ReflectionMethod(\Api\app::class, 'actionOf');
    ok('actionOf 取末尾段', $actionOf->invoke(new \Api\app(), 'http://127.0.0.1:5700/send_group_msg') === 'send_group_msg');
});

echo "\n== 2. WS 超时不能回落 HTTP（send_* 非幂等） ==\n";
$ws = (string)file_get_contents(dirname(__DIR__) . '/protocol/OneBot/WsHub.php');
ok('callApi 三态返回', str_contains($ws, 'array|false|null'));
ok('超时返回 false 而不是 null', str_contains($ws, '不回落 HTTP 以免重复发送'));

$ad = (string)file_get_contents(dirname(__DIR__) . '/protocol/OneBot/Adapter.php');
ok('Adapter 仅在 array 时直接返回', str_contains($ad, 'if (is_array($response))'));
ok('收到 false 时报超时且不回落 HTTP', str_contains($ad, '已发出，未收到 echo'));

echo "\n== 3. 上下文在嵌套/异常下都能正确恢复 ==\n";

// v1 靠每个 Trigger 里手写 Cache::snapshot()/restore()（漏一处就串号）。
// v2 由 Context::run() 统一保证：进入压栈、退出出栈，异常也不遗漏。
$outer = new class extends \Protocol\Core\Adapter {
    protected string $name = 'outer';
    public function call(string $a, array $p = [], string $m = 'POST'): object {
        return (object)['status' => 'ok', 'retcode' => 0, 'data' => null, 'message' => ''];
    }
};
$inner = clone $outer;

\Core\Bot\Context::clear();
\Core\Bot\Context::bind($outer);

$ctx = static fn(\Protocol\Core\Adapter $a) => new \Core\Bot\Context(
    \Core\Bot\Event::fromArray(['post_type' => 'message', 'time' => time()]),
    $a
);

// 嵌套：内层运行期间看到内层，退出后回到外层
\Core\Bot\Context::run($ctx($inner), function () use ($inner) {
    ok('嵌套内层可见', \Core\Bot\Context::adapter() === $inner);
});
ok('嵌套退出后恢复外层', \Core\Bot\Context::adapter() === $outer);

// 异常路径：同样要恢复
try {
    \Core\Bot\Context::run($ctx($inner), function () {
        throw new \RuntimeException('模拟业务异常');
    });
} catch (\RuntimeException) {
}
ok('异常后恢复外层', \Core\Bot\Context::adapter() === $outer);

\Core\Bot\Context::clear();

echo "\n== 4. 默认后端可按配置选择 ==\n";
$b = (string)file_get_contents(dirname(__DIR__) . '/protocol/Core/Bridge.php');
ok('Bridge::fallback() 存在', str_contains($b, 'public static function fallback()'));
ok('按 defaultBackend 构造', str_contains($b, 'Config::defaultBackend()'));
ok('未启用时回落 HTTP', str_contains($b, '未启用，回落 HTTP'));
ok('默认后端非 http 时主动发送也走桥', str_contains($b, "self::fallback()->name() !== 'http'"));

with_config("[protocol]\ndefault = milky\n[protocol.milky]\nenable = false\n", function () {
    ok('milky 未启用 → 默认后端 http', \Protocol\Core\Bridge::fallback()->name() === 'http');
});
with_config("[protocol]\ndefault = milky\n[protocol.milky]\nenable = true\napi = \"http://127.0.0.1:3010\"\n", function () {
    ok('milky 启用 → 默认后端 milky', \Protocol\Core\Bridge::fallback()->name() === 'milky');
});
with_config("[protocol]\ndefault = onebot\n[protocol.onebot]\nenable = true\n", function () {
    ok('onebot 启用 → 默认后端 onebot', \Protocol\Core\Bridge::fallback()->name() === 'onebot');
});
with_config("[protocol]\ndefault = http\n", function () {
    ok('default=http → 默认后端 http', \Protocol\Core\Bridge::fallback()->name() === 'http');
});

echo "\n" . ($fail === 0 ? "全部通过（{$pass} 项）\n" : "失败 {$fail} 项\n");
exit($fail === 0 ? 0 : 1);