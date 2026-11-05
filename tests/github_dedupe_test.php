<?php
declare(strict_types=1);
define('__runtime__', sys_get_temp_dir() . '/hyper-github-dedupe-' . getmypid());
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\Registry;
use Core\Log\Logger;
use Core\Webhook\GithubWebhook;
use Test\Github\HooksFixture;

@mkdir(__runtime__, 0775, true);
require_once __DIR__ . '/fixtures/HooksFixture.php';

$logger = new Logger('test');
$registry = new Registry($logger, ['Test\\Github\\' => __DIR__ . '/fixtures']);

$payload = [
    'ref' => 'refs/tags/v3.2.21',
    'repository' => ['full_name' => 'OIAPI/hyper-bot', 'html_url' => 'https://github.com/OIAPI/hyper-bot'],
    'sender' => ['login' => 'tester'],
    'release' => ['tag_name' => 'v3.2.21'],
];
$ref = new ReflectionMethod(GithubWebhook::class, 'broadcast');
$ref->invoke(null, $registry, 'push', $payload, 'test-hook', 'delivery-1');

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};
$check('每条事件只广播一次', count(HooksFixture::$topics) === 1);
$check('topic 为 github.push', (HooksFixture::$topics[0] ?? '') === 'github.push');

@exec('rm -rf ' . escapeshellarg(__runtime__));
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
