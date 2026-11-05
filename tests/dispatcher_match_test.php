<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\App;
use Core\Bot\Dispatcher;
use Core\Bot\Registry;
use Core\Bot\Subscription;

$app = App::boot();
$dispatcher = new Dispatcher(
    $app->container->get(Registry::class),
    new Subscription(),
    $app->logger,
);
$m = new ReflectionMethod(Dispatcher::class, 'matchesRegex');
$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

$check('原始 notice 类型精确命中', $m->invoke($dispatcher, 'group_increase', 'group_increase') === true);
$check('原始 notice 类型不误命中', $m->invoke($dispatcher, 'group_increase', 'group_decrease') === false);
$check('普通正则在分隔符内仍可用', $m->invoke($dispatcher, '/^group_/', 'group_increase') === true);
$check('带分隔符的精确正则', $m->invoke($dispatcher, '/^group_increase$/', 'group_increase') === true);
$check('空模式匹配全部', $m->invoke($dispatcher, '', 'anything') === true);

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
