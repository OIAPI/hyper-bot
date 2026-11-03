<?php
declare(strict_types=1);
/**
 * 运行架构解析自检。
 *   php tests/runtime_test.php
 */
require __DIR__ . '/../Include.php';

use Core\Runtime;

$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($detail !== '' && !$cond ? "  ({$detail})" : '') . PHP_EOL;
    if (!$cond) $fail++;
};

$hasSwoole = extension_loaded('swoole');

echo "== 默认值 ==\n";
$def = Runtime::detect();
$ok('不传任何参数时请求 swoole', $def->requested === Runtime::SWOOLE, $def->describe());
if ($hasSwoole) {
    $ok('有扩展时就是 swoole', $def->mode === Runtime::SWOOLE && !$def->degraded);
} else {
    $ok('无扩展时降级为 php', $def->mode === Runtime::PHP && $def->degraded);
    $ok('降级原因可读', str_contains($def->reason, 'swoole'), $def->reason);
}

$auto = Runtime::detect('auto');
$ok('auto 仍按扩展检测', $auto->mode === ($hasSwoole ? Runtime::SWOOLE : Runtime::PHP));

echo "== 显式指定 ==\n";
$php = Runtime::detect('php');
$ok('php 不被降级', $php->mode === Runtime::PHP && !$php->degraded);
$webui = Runtime::detect('webui');
$ok('webui 可用且不处理事件', $webui->mode === Runtime::WEBUI && !$webui->handlesEvents());
$bad = Runtime::detect('nope');
$ok('未知架构回落 php 并说明', $bad->mode === Runtime::PHP && $bad->degraded, $bad->reason);
$ok('未知架构文案含原值', str_contains($bad->reason, 'nope'));

echo "== 配置文件 ==\n";
$fromCfg = Runtime::fromConfig();
$ok('Config.ini 默认 arch=swoole', $fromCfg->requested === Runtime::SWOOLE, $fromCfg->describe());
$ok('Config.ini 的 runtime.arch 已写入', \Core\Config::string('runtime.arch', '') === 'swoole', \Core\Config::string('runtime.arch', ''));

putenv('HYPER_ARCH=php');
$env = Runtime::fromConfig();
$ok('环境变量优先级高于配置', $env->mode === Runtime::PHP && $env->requested === Runtime::PHP);
putenv('HYPER_ARCH');

echo "== 能力开关 ==\n";
$ok('swoole 支持 WS 服务端', Runtime::detect('swoole')->supportsWebSocketServer() === $hasSwoole);
$ok('php 不支持常驻客户端', Runtime::detect('php')->supportsPersistentClients() === false);
$ok('webui 不处理事件', Runtime::detect('webui')->handlesEvents() === false);

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
