<?php
declare(strict_types=1);
/**
 * GitHub 消息模板：内置变量 + {json.path[0].key} 解析。
 */
require __DIR__ . '/../vendor/autoload.php';

use App\Plugins\Github;

$payload = [
    'repository' => [
        'full_name' => 'OIAPI/hyper-bot',
        'html_url' => 'https://github.com/OIAPI/hyper-bot',
        'stargazers_count' => 42,
    ],
    'sender' => ['login' => 'alice'],
    'action' => 'opened',
    'ref' => 'refs/heads/main',
    'issue' => [
        'number' => 12,
        'title' => '修复一个 bug',
        'html_url' => 'https://github.com/OIAPI/hyper-bot/issues/12',
        'labels' => [['name' => 'bug'], ['name' => 'help wanted']],
    ],
    'commits' => [
        ['id' => 'abc123', 'message' => 'first commit', 'author' => ['name' => 'Alice']],
    ],
];
$ctx = [
    'event' => 'issues',
    'repo' => 'OIAPI/hyper-bot',
    'sender' => 'alice',
    'hook' => 'h1',
    'summary' => '摘要',
    'payload' => $payload,
    'delivery' => 'del-1',
    'received_at' => 1700000000,
];

$pass = 0;
$fail = 0;
function check(string $name, string $want, string $got): void {
    global $pass, $fail;
    if ($want === $got) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n       期待: " . json_encode($want, 256) . "\n       实际: " . json_encode($got, 256) . "\n"; }
}

check('内置变量', 'OIAPI/hyper-bot main opened 12', Github::renderTemplate('{repo} {branch} {action} {number}', $ctx));
check('JSON 字段', '修复一个 bug', Github::renderTemplate('{json.issue.title}', $ctx));
check('数组下标', 'help wanted', Github::renderTemplate('{json.issue.labels[1].name}', $ctx));
check('嵌套数组', 'Alice', Github::renderTemplate('{json.commits[0].author.name}', $ctx));
check('数字', '42', Github::renderTemplate('{json.repository.stargazers_count}', $ctx));
check('html_url', 'https://github.com/OIAPI/hyper-bot/issues/12', Github::renderTemplate('{url}', $ctx));
check('缺失字段为空', '前缀--后缀', Github::renderTemplate('前缀-{json.issue.missing}-后缀', $ctx));
check('未知变量原样保留', '{nope}', Github::renderTemplate('{nope}', $ctx));
check('{json} 输出整段', '1', str_contains(Github::renderTemplate('{json}', $ctx), '"full_name":"OIAPI/hyper-bot"') ? '1' : '0');
check('多行模板', "[issues] OIAPI/hyper-bot\n修复一个 bug", Github::renderTemplate("[{event}] {repo}\n{json.issue.title}", $ctx));

echo PHP_EOL . ($fail === 0 ? "全部通过" : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
