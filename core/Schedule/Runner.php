<?php
namespace Core\Schedule;

use Core\Scheduler;
use Utils\Logger;

/**
 * 定时任务执行器。
 *
 * 触发点有三个，都调同一个 tick()：
 *   - swoole 架构：worker 0 里 Swoole\Timer::tick(30s)
 *   - php 架构：bin/hyper worker 每秒轮询
 *   - HTTP 请求：顺带跑一次（节流 20 秒），仅当上面两种都没跑时才兜底，
 *     这样用户没配常驻进程时定时任务也不会永远不执行
 *
 * 并发：用文件锁保证同一时刻只有一个 tick 在跑（swoole 多 worker 会同时触发）。
 */
final class Runner
{
    private static float $lastRun = 0.0;

    /** 顺带把 Scheduler 的延迟队列也清掉（php 架构下没有常驻 worker 时的兜底） */
    public static function tick(bool $force = false): array
    {
        $now = microtime(true);
        if (!$force && $now - self::$lastRun < 20) {
            return [];
        }
        self::$lastRun = $now;

        $lockFile = dirname(JobStore::path()) . '/.tick.lock';
        if (!is_dir(dirname($lockFile))) {
            @mkdir(dirname($lockFile), 0775, true);
        }
        $fp = @fopen($lockFile, 'c');
        if ($fp === false) {
            return [];
        }
        if (!@flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            return [];
        }

        $executed = [];
        try {
            // 1) 延迟队列（Scheduler::after / every 在 php 架构下写文件）
            foreach (Scheduler::due() as $entry) {
                Scheduler::run($entry);
            }

            // 2) RSS 订阅源（各自按 last_poll 到期，最多 5 个/次）
            try {
                \Core\Rss\Poller::pollDue();
            } catch (\Throwable $e) {
                Logger::debug('[rss] 轮询异常：' . $e->getMessage());
            }

            // 3) cron 任务
            foreach (JobStore::all() as $job) {
                if (empty($job['enabled'])) {
                    continue;
                }
                $next = (int)($job['next_run_at'] ?? 0);
                if ($next > time()) {
                    continue;
                }
                $result = self::runJob($job);
                $executed[] = ['id' => $job['id'], 'name' => $job['name'] ?? '', 'result' => $result];
            }
        } finally {
            @flock($fp, LOCK_UN);
            fclose($fp);
        }
        return $executed;
    }

    /** 立即执行一条任务（面板的「立即执行」按钮也走这里） */
    public static function runJob(array $job): array
    {
        $action = is_array($job['action'] ?? null) ? $job['action'] : [];
        $type = (string)($action['type'] ?? 'message');
        try {
            $result = $type === 'handler' ? self::runHandler($action) : self::runMessage($action);
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        [$cron] = Cron::parse((string)($job['cron'] ?? ''));
        $next = $cron !== null ? (int)($cron->nextRun(time()) ?? 0) : 0;
        JobStore::markRun((string)$job['id'], $result['ok'] ? 'ok' : 'failed', (string)$result['message'], $next);

        $line = sprintf(
            '[定时任务] %s（%s）%s：%s',
            (string)($job['name'] ?? $job['id']),
            (string)($job['cron'] ?? ''),
            $result['ok'] ? '成功' : '失败',
            (string)$result['message']
        );
        $result['ok'] ? Logger::info($line) : Logger::warning($line);

        return $result;
    }

    /** @return array{ok:bool,message:string} */
    private static function runMessage(array $action): array
    {
        $target = trim((string)($action['target'] ?? ''));
        $text = (string)($action['text'] ?? '');
        if ($target === '' || $text === '') {
            return ['ok' => false, 'message' => '收件人或内容为空'];
        }
        $isPrivate = (string)($action['target_type'] ?? 'group') === 'private';
        $adapter = \Protocol\OneBot\Adapter::currentAdapter();
        $response = $adapter->call($isPrivate ? 'send_private_msg' : 'send_group_msg', [
            $isPrivate ? 'user_id' : 'group_id' => $isPrivate ? $target : (int)$target,
            'message' => $text,
        ]);
        $status = (string)($response->status ?? '');
        if ($status === 'ok' || $status === 'async') {
            return ['ok' => true, 'message' => '已发送到 ' . ($isPrivate ? 'QQ ' : '群 ') . $target];
        }
        $err = (string)($response->message ?? $response->wording ?? '协议端没有返回 ok');
        return ['ok' => false, 'message' => '发送失败：' . $err];
    }

    /** @return array{ok:bool,message:string} */
    private static function runHandler(array $action): array
    {
        $spec = (string)($action['handler'] ?? '');
        [$class, $method] = preg_split('/@|::/', $spec, 2) + [1 => ''];
        $class = ltrim(trim($class), '\\');
        $method = trim($method);
        if (!class_exists($class) || !method_exists($class, $method)) {
            return ['ok' => false, 'message' => '处理函数不存在：' . $spec];
        }
        $callable = [$class, $method];
        if (!is_callable($callable)) {
            return ['ok' => false, 'message' => '处理函数不可调用（需为静态方法）：' . $spec];
        }
        $out = $callable();
        return ['ok' => true, 'message' => '已执行 ' . $spec . (is_string($out) ? '：' . $out : '')];
    }
}