<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Schedule\Cron;
use Core\Schedule\JobStore;
use Core\Schedule\Runner;

/**
 * 定时任务：列表、增删改、立即执行、表达式预览。
 *
 * 任务本身由 Core\Schedule\Runner 在后台跑（swoole 定时器 / worker 轮询 /
 * HTTP 请求顺带 tick 三种入口），这里只负责管理面。
 */
final class ScheduleController extends Base
{
    public function index(Request $request): Response
    {
        $jobs = JobStore::all();
        $now = time();
        $rows = [];
        foreach ($jobs as $job) {
            $job['next_run_at'] = (int)($job['next_run_at'] ?? 0);
            $job['next_run_text'] = $job['next_run_at'] > 0 ? date('Y-m-d H:i:s', $job['next_run_at']) : '';
            $job['last_run_text'] = (int)($job['last_run_at'] ?? 0) > 0 ? date('Y-m-d H:i:s', (int)$job['last_run_at']) : '';
            $job['overdue'] = $job['enabled'] && $job['next_run_at'] > 0 && $job['next_run_at'] < $now - 120;
            $rows[] = $job;
        }
        return $this->ok([
            'items'    => $rows,
            'presets'  => Cron::presets(),
            'stats'    => [
                'total'    => count($rows),
                'enabled'  => count(array_filter($rows, static fn(array $j): bool => !empty($j['enabled']))),
                'failed'   => count(array_filter($rows, static fn(array $j): bool => ($j['last_status'] ?? '') === 'failed')),
            ],
            'path'     => JobStore::path(),
            'arch'     => \Core\Runtime::fromConfig()->mode,
        ]);
    }

    public function create(Request $request): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $result = JobStore::save($this->payload($request));
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok(['saved' => $result['data']], $result['msg']);
    }

    public function update(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $id = (string)($params['id'] ?? '');
        $current = JobStore::get($id);
        if ($current === null) {
            return $this->fail('任务不存在：' . $id, -1, 404);
        }
        $payload = $this->payload($request);
        $payload['id'] = $id;
        if (!isset($payload['cron'])) {
            $payload['cron'] = $current['cron'] ?? '';
        }
        if (!array_key_exists('enabled', $payload)) {
            $payload['enabled'] = (bool)($current['enabled'] ?? true);
        }
        if (!isset($payload['name'])) {
            $payload['name'] = $current['name'] ?? '';
        }
        if (!isset($payload['action'])) {
            $payload['action'] = $current['action'] ?? [];
        }
        $result = JobStore::save($payload);
        return $result['ok']
            ? $this->ok(['saved' => $result['data']], $result['msg'])
            : $this->fail($result['msg'], -1, 400);
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        return JobStore::remove((string)($params['id'] ?? ''))
            ? $this->ok(null, '任务已删除')
            : $this->fail('任务不存在', -1, 404);
    }

    /** 立即执行一次（不影响下次计划） */
    public function run(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $job = JobStore::get((string)($params['id'] ?? ''));
        if ($job === null) {
            return $this->fail('任务不存在', -1, 404);
        }
        $result = Runner::runJob($job);
        return $result['ok']
            ? $this->ok($result, $result['message'])
            : $this->ok($result, $result['message']);
    }

    /** 表达式预览：返回接下来几次执行时间，面板实时显示 */
    public function preview(Request $request): Response
    {
        $cron = (string)$request->input('cron', '');
        $result = JobStore::preview($cron, 3);
        return $result['ok'] ? $this->ok($result) : $this->fail((string)$result['msg'], -1, 400);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $input = $request->params();
        $out = [];
        foreach (['id', 'name', 'cron', 'enabled', 'action'] as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }
        if (isset($out['enabled'])) {
            $out['enabled'] = (bool)$out['enabled'];
        }
        return $out;
    }
}
