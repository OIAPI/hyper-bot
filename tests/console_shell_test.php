<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Console\Shell;

$shell = new Shell(__DIR__ . '/..', PHP_BINARY, static fn() => null, static fn() => null);
$pass = 0;
$fail = 0;

function prop(Shell $shell, string $name): mixed {
    $ref = new ReflectionProperty($shell, $name);
    return $ref->getValue($shell);
}
function call(Shell $shell, string $method, mixed ...$args): mixed {
    $ref = new ReflectionMethod($shell, $method);
    return $ref->invoke($shell, ...$args);
}
function check(string $name, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; }
    else { $fail++; fwrite(STDERR, "FAIL {$name}" . ($detail !== '' ? " ({$detail})" : '') . PHP_EOL); }
}

$shell->feed('status');
check('输入普通字符', prop($shell, 'buffer') === 'status' && prop($shell, 'cursor') === 6);
$shell->feed("\x1b[D");
check('左移光标', prop($shell, 'cursor') === 5);
$shell->feed("\x7f");
check('退格删除光标前字符', prop($shell, 'buffer') === 'stats' && prop($shell, 'cursor') === 4);
$shell->feed("\x1b[H");
check('Home', prop($shell, 'cursor') === 0);
$shell->feed("\x1b[F");
check('End', prop($shell, 'cursor') === 5);
$shell->feed("\x1b[3~");
check('Delete 不动光标', prop($shell, 'cursor') === 5);

$shell->feed("\x15"); // Ctrl+U
check('Ctrl+U 清空', prop($shell, 'buffer') === '');
$shell->feed('中文输入');
check('UTF-8 输入', prop($shell, 'buffer') === '中文输入');
$shell->feed("\x03"); // Ctrl+C
check('Ctrl+C 清空', prop($shell, 'buffer') === '' && prop($shell, 'historyIndex') === -1);

call($shell, 'remember', 'help');
call($shell, 'remember', 'status');
$shell->feed("\x1b[A");
check('↑ 最近一次', prop($shell, 'buffer') === 'status');
$shell->feed("\x1b[A");
check('↑ 更早一次', prop($shell, 'buffer') === 'help');
$shell->feed("\x1b[B");
check('↓ 回到较新', prop($shell, 'buffer') === 'status');
$shell->feed("\x1b[B");
check('↓ 回到当前输入', prop($shell, 'buffer') === '');

check('帮助文本包含透传命令', str_contains(file_get_contents(__DIR__ . '/../core/Console/Shell.php'), '透传给 bin/hyper'));

// 原生命令 + 透传 + 拦截（输出重定向到 /dev/null 由外层处理）
$shell->feed("version\n");
$shell->feed("serve\n");
$shell->feed("status\n");
check('命令执行后回到空行', prop($shell, 'buffer') === '' && prop($shell, 'cursor') === 0);
$hist = prop($shell, 'history');
check('历史包含刚输入的命令', in_array('version', $hist, true) && in_array('serve', $hist, true));

echo ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
