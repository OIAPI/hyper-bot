<?php
declare(strict_types=1);

/**
 * 定时任务：cron 解析 / 下次执行时间 / 任务仓库 / 执行器。
 *
 * 用临时目录当 runtime，不碰真实任务表。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Schedule\Cron;
use Core\Schedule\JobStore;
use Core\Schedule\Runner;

$ok = 0;
$fail = 0;
function check(string $name, bool $pass, string $detail = ''): void {
    global $ok, $fail;
    if ($pass) {
        $ok++;
        echo "  OK   {$name}" . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? "（{$detail}）" : '') . PHP_EOL;
    }
}

$tmp = sys_get_temp_dir() . '/hyper-schedule-test-' . getmypid();
@mkdir($tmp, 0775, true);
JobStore::path($tmp . '/jobs.json');

try {
    // ---- cron 解析 ----
    [$c, $e] = Cron::parse('0 8 * * *');
    check('解析每天 8 点', $e === null && $c !== null, (string)$e);
    check('下次是 8:00', $c !== null && date('H:i', (int)$c->nextRun(strtotime('2026-01-01 07:59:00'))) === '08:00');
    check('过了 8 点顺延到明天', $c !== null && date('Y-m-d H:i', (int)$c->nextRun(strtotime('2026-01-01 08:00:00'))) === '2026-01-02 08:00');

    [$c2, $e2] = Cron::parse('*/15 9-18 * * 1-5');
    check('解析步长与区间', $e2 === null && $c2 !== null, (string)$e2);
    check('工作日 9:15 触发', $c2 !== null && date('H:i', (int)$c2->nextRun(strtotime('2026-01-05 09:00:00'))) === '09:15', date('c', (int)($c2?->nextRun(strtotime('2026-01-05 09:00:00')) ?? 0)));

    [$c3] = Cron::parse('@daily');
    check('别名 @daily 归一为每天 0 点', $c3 !== null && date('H:i', (int)$c3->nextRun(strtotime('2026-01-01 12:00:00'))) === '00:00');

    [$bad1, $err1] = Cron::parse('60 * * * *');
    check('分超出范围被拒绝', $bad1 === null && str_contains((string)$err1, '范围'), (string)$err1);
    [$bad2, $err2] = Cron::parse('* * *');
    check('段数不足被拒绝', $bad2 === null && str_contains((string)$err2, '5 段'), (string)$err2);
    [$bad3, $err3] = Cron::parse('*/0 * * * *');
    check('步长为 0 被拒绝', $bad3 === null, (string)$err3);
    [$bad4, $err4] = Cron::parse('0 0 31 2 *');
    check('2 月 31 日的表达式合法但无匹配', $bad4 !== null && $bad4->nextRun(strtotime('2026-01-01')) === null);

    // ---- 任务仓库 ----
    $r1 = JobStore::save(['name' => '每日播报', 'cron' => '0 8 * * *', 'action' => ['type' => 'message', 'target' => '123456', 'text' => '早上好']]);
    check('创建任务', $r1['ok'] === true, (string)$r1['msg']);
    $id = (string)($r1['data']['id'] ?? '');
    check('任务有下次执行时间', ($r1['data']['next_run_at'] ?? 0) > time(), json_encode($r1['data']['next_run_at'] ?? null));
    check('任务落盘', is_file($tmp . '/jobs.json'));

    $r2 = JobStore::save(['id' => $id, 'name' => '改名了', 'cron' => '30 9 * * *', 'action' => ['type' => 'message', 'target' => '123456', 'text' => '早上好']]);
    check('按 id 覆盖而不是新增', $r2['ok'] === true && count(JobStore::all()) === 1);
    check('字段已更新', (JobStore::get($id)['name'] ?? '') === '改名了');

    $r3 = JobStore::save(['name' => '坏的', 'cron' => 'nope', 'action' => ['type' => 'message', 'target' => '1', 'text' => 'x']]);
    check('坏表达式被拒绝', $r3['ok'] === false && str_contains((string)$r3['msg'], '5 段'), (string)$r3['msg']);
    $r4 = JobStore::save(['name' => '空的', 'cron' => '0 8 * * *', 'action' => ['type' => 'message', 'target' => '', 'text' => '']]);
    check('空收件人被拒绝', $r4['ok'] === false, (string)$r4['msg']);
    $r5 = JobStore::save(['name' => '坏函数', 'cron' => '0 8 * * *', 'action' => ['type' => 'handler', 'handler' => 'nope']]);
    check('处理函数格式被校验', $r5['ok'] === false, (string)$r5['msg']);

    // ---- 预览 ----
    $pv = JobStore::preview('0 8 * * *', 3);
    check('预览返回 3 次', $pv['ok'] === true && count($pv['runs']) === 3, json_encode($pv['runs']));
    check('预览拒绝坏表达式', JobStore::preview('@@@', 3)['ok'] === false);

    // ---- 执行器：handler 动作 ----
    $r6 = JobStore::save(['name' => '调用处理器', 'cron' => '0 8 * * *', 'action' => ['type' => 'handler', 'handler' => 'ScheduleTestHandler@ping']]);
    $job = JobStore::get((string)$r6['data']['id']);
    $res = Runner::runJob($job);
    check('handler 执行成功', $res['ok'] === true && str_contains($res['message'], 'pong'), (string)$res['message']);
    check('执行结果写回', (JobStore::get((string)$r6['data']['id'])['last_status'] ?? '') === 'ok');

    // ---- 执行器：到点自动执行 ----
    // 直接改文件里的 next_run_at：save() 会按 cron 重算，构造不出「已到期」的状态
    $id6 = (string)$r6['data']['id'];
    $rawJobs = json_decode((string)file_get_contents($tmp . '/jobs.json'), true);
    foreach ($rawJobs['jobs'] as $i => $j) {
        if ($j['id'] === $id6) {
            $rawJobs['jobs'][$i]['next_run_at'] = time() - 5;
        }
    }
    file_put_contents($tmp . '/jobs.json', (string)json_encode($rawJobs, JSON_UNESCAPED_UNICODE));
    JobStore::reset(null);
    $ran = Runner::tick(true);
    $due = ['id' => $id6];
    check('tick 执行了到期任务', count($ran) >= 1, json_encode($ran));
    check('执行后下次时间被推进', (JobStore::get((string)$due['id'])['next_run_at'] ?? 0) > time());

    // ---- 执行器：发送失败要给出原因而不是抛异常 ----
    $r7 = JobStore::save(['name' => '发消息', 'cron' => '0 8 * * *', 'action' => ['type' => 'message', 'target' => '123', 'text' => 'hi']]);
    $res2 = Runner::runJob(JobStore::get((string)$r7['data']['id']));
    check('没有协议端时返回失败原因', $res2['ok'] === false && $res2['message'] !== '', (string)$res2['message']);

    check('删除任务', JobStore::remove((string)$r7['data']['id']) === true);
    check('删除不存在的任务返回 false', JobStore::remove('nope') === false);
} finally {
    if (is_dir($tmp)) {
        foreach (glob($tmp . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);

class ScheduleTestHandler {
    public static function ping(): string {
        return 'pong';
    }
}