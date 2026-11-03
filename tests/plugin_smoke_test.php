<?php
/**
 * 插件冒烟：v1 时代的老插件在 v2 内核里还能不能构造、能不能跑命令。
 *
 * 这些插件是「运行时才炸」的重灾区：构造里用 cache::$api、help 里 new markdown，
 * 静态检查看不出，php 架构的测试也覆盖不到，只有在聊天里发一条命令才暴露。
 */
declare(strict_types=1);

if (!class_exists('Swoole\Coroutine', false)) {
    eval('namespace Swoole; class Coroutine {
        public static function getCid(): int { return 1; }
        public static function getContext(): ?object { static $c = null; return $c ??= new \ArrayObject(); }
        public static function create(callable $fn, ...$args) { $fn(...$args); return null; }
        public static function sleep($t) {}
    }
    namespace Swoole\Coroutine;
    class System {
        public static function writeFile(string $f, string $data, int $flags = 0): bool { @mkdir(dirname($f), 0777, true); return @file_put_contents($f, $data, $flags) !== false; }
        public static function readFile(string $f): string|false { return @file_get_contents($f); }
    }');
}

require dirname(__DIR__) . '/Include.php';

use Core\Bot\Context;
use Core\Bot\Event;

$fail = 0;
$ok = function (string $name, bool $cond, string $extra = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($cond || $extra === '' ? '' : ' -- ' . $extra) . PHP_EOL;
    if (!$cond) {
        $fail++;
    }
};

$app = \Core\App::boot();
$registry = $app->container->get(\Core\Bot\Registry::class);
$registry->load();

// 1) v1 的 cache::$api 静态槽必须被内核填上（php 架构 / 队列 / cron 都要能用）
$api = \Controller\Api::getInstance();
$ok('cache::$api 指向 API 容器', \Utils\Cache::$api === $api);

// 2) Markdown 构造（v1 组件，依赖 cache::$api）
$mdOk = true;
$mdError = '';
try {
    $fence = str_repeat(chr(96), 3);
    $md = new \Utils\Markdown('555', $fence . "text\n/help\n" . $fence);
    $md->send();
} catch (\Throwable $e) {
    $mdOk = false;
    $mdError = get_class($e) . ': ' . $e->getMessage();
}
$ok('Markdown 可构造并发送（无适配器时静默）', $mdOk, $mdError);

// 3) 每个插件都能在当前上下文里构造
$event = Event::fromArray([
    'time' => time(), 'self_id' => 42, 'post_type' => 'message', 'message_type' => 'group',
    'group_id' => '555', 'user_id' => '77', 'message_id' => '1', 'raw_message' => '/help',
    'message' => [['type' => 'text', 'data' => ['text' => '/help']]],
    'sender' => ['user_id' => 77, 'nickname' => 'n'],
]);

$plugins = $registry->plugins();
$ok('注册表里能扫到插件', $plugins !== [], 'registry 为空');

foreach ($plugins as $meta) {
    $class = (string)($meta['class'] ?? '');
    if ($class === '') {
        continue;
    }
    $error = '';
    $built = false;
    try {
        Context::run(new Context($event, null), function () use ($class, $app, &$built) {
            new $class($app);
            $built = true;
        });
    } catch (\Throwable $e) {
        $error = get_class($e) . ': ' . $e->getMessage();
    }
    $ok('插件可构造：' . $class, $built, $error);
}

// 4) /help 命令端到端（v1 插件里最容易炸的一个）
$error = '';
$ran = false;
try {
    Context::run(new Context($event, null), function () use ($app, &$ran) {
        $help = new \App\Plugins\Help($app);
        $help->help();
        $ran = true;
    });
} catch (\Throwable $e) {
    $error = get_class($e) . ': ' . $e->getMessage();
}
$ok('/help 命令可执行', $ran, $error);

echo $fail === 0 ? "全部通过\n" : "失败 " . $fail . " 项\n";
exit($fail === 0 ? 0 : 1);
