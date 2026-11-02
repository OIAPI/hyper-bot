<?php
declare(strict_types=1);
require '/storage/emulated/0/model/hyper.lkaa.top/Include.php';

$fail = 0;
$ok = function (string $name, bool $cond, string $d = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($d !== '' ? "  ({$d})" : '') . PHP_EOL;
    if (!$cond) $fail++;
};

echo "== 适配器注册与元信息 ==\n";
foreach ([
    'onebot' => \Protocol\OneBot\Adapter::class,
    'milky'  => \Protocol\Milky\Adapter::class,
    'qqbot'  => \Protocol\QqBot\Adapter::class,
] as $name => $cls) {
    $a = new $cls();
    $ok("{$name} 适配器实例化", $a instanceof \Protocol\Core\Adapter);
    $ok("{$name}.name() = {$name}", $a->name() === $name, $a->name());
    $ok("{$name}.handles 任意动作", $a->handles('send_msg') === true);
    $meta = $a->meta();
    $ok("{$name}.meta() 可序列化", is_array($meta) && json_encode($meta) !== false);
}

echo "== HTTP 兜底适配器 ==\n";
$h = new \Protocol\Core\HttpAdapter();
$ok('HttpAdapter.name() = http', $h->name() === 'http');
$ok('Bridge 只在非 http 适配器时启用', (function () {
    \Utils\Cache::set('adapter', new \Protocol\Core\HttpAdapter());
    return \Protocol\Core\Bridge::hasAdapter() === false;
})());

echo "== NullResponder ==\n";
$n = new \Protocol\Core\NullResponder();
$ok('isEnd() 恒为 true', $n->isEnd() === true);
$ok('sendSuccess 不抛异常', (function () use ($n) { try { $n->sendSuccess(['a' => 1]); return true; } catch (\Throwable $e) { return false; } })());

echo "== 协议状态结构 ==\n";
$st = ['onebot' => \Utils\Cache::get('adapter')];
$ok('WsHub 有 hasReverse', method_exists(\Protocol\OneBot\WsHub::class, 'hasReverse'));

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
