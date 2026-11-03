<?php
/**
 * 日志回归测试（utils/Logger.php、utils/MessageLog.php）
 *
 * 覆盖的都是曾经真实存在的缺陷：
 *   - Logger::configure(null) 把日志文件置空，文件日志静默消失
 *   - Logger 轮转备份无限堆积
 *   - MessageLog::read($date) 忽略参数，永远读当天
 *   - MessageLog 异常分支 catch (Exception) 永远不命中
 *   - 脱敏逻辑被注释掉，密码明文落盘
 *   - 超长 payload 写成几 MB 的单行
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Utils\Logger;
use Utils\MessageLog;

$pass = 0;
$fail = 0;
$sandbox = sys_get_temp_dir() . '/log-test-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0775, true);

function ok(string $label, mixed $actual, mixed $expected): void {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }
    $fail++;
    printf("  \033[31m✗\033[0m %s\n      期望 %s\n      实际 %s\n",
        $label,
        json_encode($expected, 256 | JSON_UNESCAPED_UNICODE),
        json_encode($actual, 256 | JSON_UNESCAPED_UNICODE));
}

// ---------------------------------------------------------------- Logger
echo "\n=== 1. Logger 基本写入 ===\n";
Logger::setConsole(false);
Logger::setFile(true);
$logFile = $sandbox . '/logger.log';
Logger::configure($logFile, 4096, false);
Logger::info('hello');
Logger::error('boom');
$lines = file($logFile, FILE_IGNORE_NEW_LINES) ?: [];
ok('写入了 2 行', count($lines), 2);
ok('带级别', str_contains($lines[0], '[INFO'), true);
ok('带毫秒时间戳', (bool)preg_match('/^\[\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}\]/', $lines[0]), true);

echo "\n=== 2. configure(null) 不改变目标文件（回归）===\n";
Logger::configure(null, null, null);
Logger::info('still here');
ok('文件路径未变', Logger::file(), $logFile);
ok('新增行写进同一个文件', count(file($logFile) ?: []), 3);

echo "\n=== 3. 轮转只保留最近 N 个备份 ===\n";
Logger::configure($sandbox . '/rot.log', 200, false);
for ($i = 0; $i < 400; $i++) {
    Logger::info('rotate-' . $i . ' ' . str_repeat('x', 40));
    // 同一秒内多次轮转会产生同名备份，加一点间隔保证名字唯一
    if ($i % 40 === 0) {
        usleep(1100000);
    }
}
$backups = glob($sandbox . '/rot.log.*') ?: [];
ok('备份数量不超过上限', count($backups) <= Logger::MAX_BACKUPS, true);
ok('至少发生过一次轮转', count($backups) >= 1, true);
ok('当前文件仍存在', is_file($sandbox . '/rot.log'), true);

echo "\n=== 4. debug 级别默认不落盘 ===\n";
$debugFile = $sandbox . '/debug.log';
Logger::configure($debugFile, 0, false);
Logger::debug('should-not-appear');
ok('未定义 debug 时不写', is_file($debugFile), false);

// ---------------------------------------------------------------- MessageLog
echo "\n=== 5. MessageLog 写入与读取 ===\n";
$msgDir = $sandbox . '/message';
MessageLog::configure($msgDir, 262144, 33554432, 3);
ok('写入成功', MessageLog::write(['msg' => 'hi', 'user' => '100'], 'message'), true);
$today = MessageLog::read();
ok('今天能读到', count($today), 1);
ok('内容正确', $today[0]['msg'] ?? null, 'hi');
ok('带 log_type', $today[0]['log_type'] ?? null, 'message');
ok('带毫秒时间戳', (bool)preg_match('/\.\d{3}$/', (string)($today[0]['log_time'] ?? '')), true);

echo "\n=== 6. read(\$date) 真的按日期读（回归）===\n";
ok('历史日期为空', MessageLog::read('20240101'), []);
ok('Y-m-d 形式同样可用', MessageLog::read('2024-01-01'), []);
ok('空参数 = 今天', count(MessageLog::read(date('Ymd'))), 1);

echo "\n=== 7. 敏感字段脱敏 ===\n";
MessageLog::write([
    'msg'      => 'login',
    'password' => 'p@ssw0rd',
    'nested'   => ['access_token' => 'abc123', 'safe' => 'ok'],
    'headers'  => [['Authorization' => 'Bearer xyz']],
], 'message');
$rows = MessageLog::read('', 'message');
$last = $rows[count($rows) - 1];
ok('顶层密码被脱敏', $last['password'] ?? null, '******');
ok('嵌套 token 被脱敏', $last['nested']['access_token'] ?? null, '******');
ok('普通字段不受影响', $last['nested']['safe'] ?? null, 'ok');
ok('数组内 Authorization 被脱敏', $last['headers'][0]['Authorization'] ?? null, '******');

echo "\n=== 8. 超长 payload 截断 ===\n";
MessageLog::write(['msg' => str_repeat('a', 400000)], 'message');
$file = MessageLog::path();
$line = (string)file($file)[count(file($file)) - 1];
ok('单行不超过上限 + 提示', strlen($line) <= MessageLog::MAX_LINE_BYTES + 200, true);
ok('带截断标记', str_contains($line, '[truncated'), true);

echo "\n=== 9. 按大小轮转并清理旧备份 ===\n";
MessageLog::configure($sandbox . '/rot-message', 1024, 2048, 2);
for ($i = 0; $i < 60; $i++) {
    MessageLog::write(['msg' => str_repeat('b', 900), 'i' => $i], 'message');
    if ($i % 20 === 0) {
        usleep(1100000);
    }
}
$files = glob($sandbox . '/rot-message/*/*/*/message.log*') ?: [];
$rotated = array_values(array_filter($files, static fn($f) => !str_ends_with($f, '/message.log')));
ok('发生过轮转', count($rotated) >= 1, true);
ok('备份数量不超过上限', count($rotated) <= 2, true);
$stats = MessageLog::stats();
ok('stats 有文件数', $stats['files'] >= 1, true);

echo "\n=== 10. dates() 与路径 ===\n";
ok('dates 含今天', in_array(date('Ymd'), MessageLog::dates(), true), true);
ok('path 指到当天文件', str_ends_with(MessageLog::path(), date('Y') . '/' . date('m') . '/' . date('d') . '/message.log'), true);
$evil = MessageLog::path('', '../../etc/passwd');
ok('非法类型被清洗（无路径穿越）', str_contains($evil, '..') || str_contains($evil, '/etc/'), false);
ok('非法类型仍落在日志目录内', str_starts_with($evil, MessageLog::dir() . '/'), true);

// ---------------------------------------------------------------- 清理
foreach (glob($sandbox . '/*') ?: [] as $entry) {
    if (is_dir($entry)) {
        exec('rm -rf ' . escapeshellarg($entry));
    } else {
        @unlink($entry);
    }
}
@rmdir($sandbox);

printf("\n%s 通过 %d，失败 %d\n", $fail === 0 ? '\033[32m全部通过\033[0m' : '\033[31m有失败\033[0m', $pass, $fail);
exit($fail === 0 ? 0 : 1);
