<?php
namespace Core\Schedule;

use Core\Fs;

/**
 * 定时任务仓库：runtime/schedule/jobs.json。
 *
 * 一条任务 = 计划（cron）+ 动作 + 运行记录。刻意只支持两种动作：
 *   - message：到点往指定群 / 好友发一条文本（最常见的需求）
 *   - handler：调用一个已注册的处理函数（插件想自己干活时用，例如 Class@method）
 *
 * 不提供「执行任意 shell」：面板是给人用的，不该变成一个能跑命令的入口。
 */
final class JobStore
{
    private static ?array $cache = null;
    private static ?string $override = null;

    /** 传参可临时改路径（测试用）；不传返回当前生效路径 */
    public static function path(?string $path = null): string
    {
        if ($path !== null) {
            self::$override = $path;
            self::$cache = null;
            return $path;
        }
        if (self::$override !== null) {
            return self::$override;
        }
        $root = defined('__runtime__') ? __runtime__ : (defined('__root__') ? __root__ . '/runtime' : getcwd() . '/runtime');
        return $root . '/schedule/jobs.json';
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = self::path();
        if (!is_file($file)) {
            return self::$cache = [];
        }
        $data = json_decode((string)@file_get_contents($file), true);
        $jobs = is_array($data['jobs'] ?? null) ? $data['jobs'] : [];
        $out = [];
        foreach ($jobs as $job) {
            if (is_array($job) && ($job['id'] ?? '') !== '') {
                $out[] = $job;
            }
        }
        return self::$cache = $out;
    }

    public static function get(string $id): ?array
    {
        foreach (self::all() as $job) {
            if (($job['id'] ?? '') === $id) {
                return $job;
            }
        }
        return null;
    }

    /** 新增或覆盖保存（按 id） */
    public static function save(array $job): array
    {
        $now = time();
        $id = trim((string)($job['id'] ?? ''));
        if ($id === '') {
            $id = 'job-' . date('Ymd-His') . '-' . bin2hex(random_bytes(2));
        }
        [$cron, $error] = Cron::parse((string)($job['cron'] ?? ''));
        if ($cron === null) {
            return ['ok' => false, 'msg' => (string)$error, 'data' => null];
        }
        $action = is_array($job['action'] ?? null) ? $job['action'] : [];
        $type = strtolower((string)($action['type'] ?? 'message'));
        if (!in_array($type, ['message', 'handler'], true)) {
            return ['ok' => false, 'msg' => '动作类型只能是 message 或 handler', 'data' => null];
        }
        if ($type === 'message' && trim((string)($action['text'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '消息内容不能为空', 'data' => null];
        }
        if ($type === 'message' && trim((string)($action['target'] ?? '')) === '') {
            return ['ok' => false, 'msg' => '收件群号或 QQ 号不能为空', 'data' => null];
        }
        if ($type === 'handler' && !preg_match('/^[\w\\\\]+(@|::)\w+$/', (string)($action['handler'] ?? ''))) {
            return ['ok' => false, 'msg' => '处理函数格式应为 Class@method 或 Class::method', 'data' => null];
        }

        $previous = self::get($id);
        $row = [
            'id'          => $id,
            'name'        => trim((string)($job['name'] ?? '')) ?: '未命名任务',
            'cron'        => $cron->expression(),
            'enabled'     => (bool)($job['enabled'] ?? true),
            'action'      => [
                'type'       => $type,
                'target'     => trim((string)($action['target'] ?? '')),
                'target_type'=> (string)($action['target_type'] ?? 'group') === 'private' ? 'private' : 'group',
                'text'       => (string)($action['text'] ?? ''),
                'handler'    => trim((string)($action['handler'] ?? '')),
                'account'    => trim((string)($action['account'] ?? '')),
            ],
            'created_at'  => (int)($previous['created_at'] ?? $now),
            'updated_at'  => $now,
            'last_run_at' => (int)($previous['last_run_at'] ?? 0),
            'last_status' => (string)($previous['last_status'] ?? ''),
            'last_message'=> (string)($previous['last_message'] ?? ''),
            'next_run_at' => (int)($cron->nextRun($now) ?? 0),
        ];

        $jobs = self::all();
        $replaced = false;
        foreach ($jobs as $i => $existing) {
            if (($existing['id'] ?? '') === $id) {
                $jobs[$i] = $row;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $jobs[] = $row;
        }
        self::$cache = $jobs;
        self::flush();
        return ['ok' => true, 'msg' => $replaced ? '任务已更新' : '任务已创建', 'data' => $row];
    }

    public static function remove(string $id): bool
    {
        $jobs = self::all();
        $before = count($jobs);
        $jobs = array_values(array_filter($jobs, static fn(array $j): bool => ($j['id'] ?? '') !== $id));
        if (count($jobs) === $before) {
            return false;
        }
        self::$cache = $jobs;
        self::flush();
        return true;
    }

    /** 运行结果回写（不动 next_run，由 Runner 算） */
    public static function markRun(string $id, string $status, string $message, int $nextRun): void
    {
        $jobs = self::all();
        foreach ($jobs as $i => $job) {
            if (($job['id'] ?? '') === $id) {
                $jobs[$i]['last_run_at'] = time();
                $jobs[$i]['last_status'] = $status;
                $jobs[$i]['last_message'] = mb_substr($message, 0, 300);
                $jobs[$i]['next_run_at'] = $nextRun;
                break;
            }
        }
        self::$cache = $jobs;
        self::flush();
    }

    /** 下次执行时间预览：给面板校验表达式用 */
    public static function preview(string $expression, int $count = 3): array
    {
        [$cron, $error] = Cron::parse($expression);
        if ($cron === null) {
            return ['ok' => false, 'msg' => $error, 'runs' => []];
        }
        $runs = array_map(static fn(int $ts): string => date('Y-m-d H:i:s', $ts), $cron->upcoming(time(), $count));
        return ['ok' => true, 'msg' => 'ok', 'runs' => $runs];
    }

    public static function reset(?array $jobs = null): void
    {
        self::$cache = $jobs;
    }

    private static function flush(): void
    {
        $file = self::path();
        $dir = dirname($file);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        Fs::write($file, (string)json_encode(['jobs' => array_values(self::$cache ?? [])], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }
}