<?php
declare(strict_types=1);
$root = sys_get_temp_dir() . '/hyper-update-' . getmypid();
@mkdir($root . '/runtime/update', 0777, true);
@mkdir($root . '/app/Plugins', 0777, true);
define('__root__', $root);
require __DIR__ . '/../vendor/autoload.php';

use Core\Update\UpdateService;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n"; }
};

$src = $root . '/src';
@mkdir($src . '/app/Plugins', 0777, true);
file_put_contents($root . '/VERSION', "9.9.9\n");
file_put_contents($src . '/app/Plugins/Group.php', "<?php // upstream v1\n");

$r1 = UpdateService::applyFromDir($src, 'test');
$check('首次更新写入文件', $r1['changed'] === 1 && is_file($root . '/app/Plugins/Group.php'));

// 本地优化
file_put_contents($root . '/app/Plugins/Group.php', "<?php // local optimize\n");
file_put_contents($src . '/app/Plugins/Group.php', "<?php // upstream v2\n");
$r2 = UpdateService::applyFromDir($src, 'test');
$check('本地修改不被覆盖', $r2['preserved'] === 1, json_encode($r2));
$check('本地内容保留', str_contains((string)file_get_contents($root . '/app/Plugins/Group.php'), 'local optimize'));

// 显式 preserve 规则（无上游基线时也能保护）
@unlink($root . '/runtime/update/upstream.json');
file_put_contents($root . '/app/Plugins/Other.php', "<?php // local other\n");
file_put_contents($src . '/app/Plugins/Other.php', "<?php // upstream other\n");
file_put_contents($root . '/runtime/update/preserve.txt', "app/Plugins/Other.php\n");
$r3 = UpdateService::applyFromDir($src, 'test');
$check('preserve.txt 规则生效', $r3['preserved'] >= 1, json_encode($r3));
$check('显式保留内容不变', str_contains((string)file_get_contents($root . '/app/Plugins/Other.php'), 'local other'));

$check('结果带备份/警告字段', array_key_exists('warnings', $r3) && array_key_exists('backup', $r3));

@unlink($root . '/runtime/update/preserve.txt');
@unlink($root . '/runtime/update/upstream.json');
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
