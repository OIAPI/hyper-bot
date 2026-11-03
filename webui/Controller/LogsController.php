<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Log\FileHandler;

/** 日志：最近记录 / 按天读取 / 日期列表 */
final class LogsController extends Base {
    private function handler(): FileHandler {
        return new FileHandler($this->root . '/runtime/log');
    }

    /** 内存环形缓冲里的最近日志（实时刷新用） */
    public function tail(Request $request): Response {
        $logger = \Core\App::boot()->logger;
        $limit = min(500, max(1, (int)$request->input('limit', 100)));
        $level = $request->input('level');
        return $this->ok([
            'items' => $logger->tail($limit, is_string($level) ? $level : null),
            'stats' => $logger->stats(),
        ]);
    }

    /** 某天的日志文件（倒序） */
    public function day(Request $request, array $params): Response {
        $day = (string)($params['date'] ?? date('Y-m-d'));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $this->fail('日期格式应为 YYYY-MM-DD，且必须是有效日期', -1, 400);
        }
        $limit = min(500, max(1, (int)$request->input('limit', 200)));
        $level = $request->input('level');
        return $this->ok([
            'date'  => $day,
            'items' => $this->handler()->read($day, $limit, is_string($level) ? $level : null),
        ]);
    }

    public function dates(Request $request): Response {
        $days = $this->handler()->days(60);
        // 前端历史上读的是 dates，这里两个键都给，避免再因为字段名对不上只剩「今天」
        return $this->ok(['items' => $days, 'dates' => $days]);
    }

    /** 消息日志（Utils\MessageLog 的 webui 视图） */
    public function messages(Request $request): Response {
        $date = (string)$request->input('date', date('Ymd'));
        $limit = min(200, max(1, (int)$request->input('limit', 50)));
        $dir = $this->root . '/runtime/message/' . date('Y') . '/' . date('m') . '/' . date('d');
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $date, $m)) {
            $dir = $this->root . '/runtime/message/' . $m[1] . '/' . $m[2] . '/' . $m[3];
        }
        $file = $dir . '/message.log';
        if (!is_file($file)) {
            return $this->ok(['items' => [], 'total' => 0]);
        }
        $lines = array_reverse(file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
        $items = [];
        foreach (array_slice($lines, 0, $limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $items[] = $decoded;
            }
        }
        return $this->ok(['items' => $items, 'total' => count($lines)]);
    }
}