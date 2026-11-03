<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;

final class OverviewController extends Base {
    /** 一览：应用信息 + 运行时长 + 计数 + 协议 + 最近事件 */
    public function index(Request $request): Response {
        return $this->ok($this->metrics->overview());
    }

    /** 只取计数（前端轮询用，负载最小） */
    public function counts(Request $request): Response {
        return $this->ok($this->metrics->counts());
    }

    /** 只取协议状态 */
    public function protocols(Request $request): Response {
        return $this->ok($this->metrics->protocols());
    }

    /** 事件时间线 */
    public function events(Request $request): Response {
        $limit = min(200, max(1, (int)$request->input('limit', 20)));
        $type = $request->input('type');
        $range = (string)$request->input('date', '');
        $items = $this->metrics->recentEvents(
            $range === '' ? $limit : max($limit, 2000),
            is_string($type) && $type !== '' ? $type : null
        );
        if ($range !== '') {
            $items = array_values(array_filter($items, static function (array $e) use ($range): bool {
                $ts = (int)($e['received'] ?? $e['time'] ?? 0);
                return match ($range) {
                    'today'     => date('Y-m-d', $ts) === date('Y-m-d'),
                    'yesterday' => date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day')),
                    '7d'        => $ts >= strtotime('-7 days'),
                    default     => true,
                };
            }));
            $items = array_slice($items, 0, $limit);
        }
        return $this->ok($items);
    }
}