<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Log\FileHandler;

/**
 * 日志：最近记录 / 按天读取 / 日期列表 / 消息记录。
 *
 * 这里有一个历史坑：程序同时存在两套日志落盘——
 *   1. Core\Log\Logger + FileHandler：结构化 JSON Lines，runtime/log/YYYY-MM/YYYY-MM-DD.jsonl；
 *   2. Utils\Logger：给人看的纯文本，runtime/log/logger.log（超限轮转为 logger.log.Ymd-His）。
 * 面板曾经只读第一套，而绝大多数业务日志走的是第二套，于是「日志页永远是空的」。
 * 现在两套都读：JSONL 优先，纯文本按行解析后合并，按时间倒序。
 */
final class LogsController extends Base {
    /** 纯文本日志最多读末尾这么多字节，避免日志大了把面板拖死 */
    private const PLAIN_TAIL_BYTES = 2 * 1024 * 1024;

    private function handler(): FileHandler {
        return new FileHandler($this->root . '/runtime/log');
    }

    /** 内存环形缓冲里的最近日志（实时刷新用）；缓冲为空时回落到文件尾部 */
    public function tail(Request $request): Response {
        $logger = \Core\App::boot()->logger;
        $limit = min(500, max(1, (int)$request->input('limit', 100)));
        $level = $request->input('level');
        $items = $this->normalizeAll($logger->tail($limit, is_string($level) ? $level : null));
        if ($items === []) {
            $items = $this->readPlain(date('Y-m-d'), $limit, is_string($level) ? $level : null);
        }
        foreach ($items as &$item) {
            unset($item['_sort']);
        }
        unset($item);
        return $this->ok([
            'items' => $items,
            'lines' => $items,
            'stats' => $logger->stats(),
            'file'  => $this->plainDir() . '/logger.log',
        ]);
    }

    /** 某天的日志文件（倒序） */
    public function day(Request $request, array $params): Response {
        $day = (string)($params['date'] ?? date('Y-m-d'));
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $day, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $this->fail('日期格式应为 YYYY-MM-DD，且必须是有效日期', -1, 400);
        }
        $limit = min(500, max(1, (int)$request->input('limit', 200)));
        $level = is_string($request->input('level')) ? (string)$request->input('level') : null;

        $json = $this->normalizeAll($this->handler()->read($day, $limit, $level));
        $plain = $this->readPlain($day, $limit, $level);
        // 两套日志合并：都可能有对方没有的记录
        $items = array_merge($json, $plain);
        usort($items, static fn(array $a, array $b): int => strcmp($b['_sort'] ?? '', $a['_sort'] ?? ''));
        $items = array_slice($items, 0, $limit);
        foreach ($items as &$item) {
            unset($item['_sort']);
        }
        unset($item);

        return $this->ok(['date' => $day, 'items' => $items, 'lines' => $items]);
    }

    public function dates(Request $request): Response {
        $days = $this->handler()->days(60);
        foreach ($this->plainDates() as $day) {
            if (!in_array($day, $days, true)) {
                $days[] = $day;
            }
        }
        rsort($days, SORT_STRING);
        $days = array_slice($days, 0, 60);
        // 前端历史上读的是 dates，这里两个键都给，避免再因为字段名对不上只剩「今天」
        return $this->ok(['items' => $days, 'dates' => $days]);
    }

    /** 消息日志（Utils\MessageLog 的 webui 视图） */
    public function messages(Request $request): Response {
        $requested = trim((string)$request->input('date', ''));
        $limit = min(200, max(1, (int)$request->input('limit', 50)));
        $available = $this->messageDays(60);

        // 不传日期时默认给「最近有数据的那天」：否则打开页面永远是空的（今天还没收到消息）
        $day = $requested !== '' ? $this->normalizeDay($requested) : ($available[0] ?? date('Y-m-d'));
        if ($day === null) {
            return $this->fail('日期格式应为 YYYY-MM-DD 或 YYYYMMDD', -1, 400);
        }
        [$y, $m, $d] = explode('-', $day);
        $dir = $this->root . '/runtime/message/' . $y . '/' . $m . '/' . $d;

        $items = [];
        // 同一天可能有 message / notice / request 多个分类文件，文件名大小写历史上也不统一
        foreach (is_dir($dir) ? (scandir($dir) ?: []) : [] as $name) {
            if (!preg_match('/\.log$/i', $name)) {
                continue;
            }
            foreach (file($dir . '/' . $name, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $decoded = json_decode($line, true);
                if (is_array($decoded)) {
                    $items[] = $decoded;
                }
            }
        }
        usort($items, static fn(array $a, array $b): int => strcmp((string)($b['log_time'] ?? ''), (string)($a['log_time'] ?? '')));

        return $this->ok([
            'date'  => $day,
            'items' => array_slice($items, 0, $limit),
            'total' => count($items),
            'dates' => $available,
        ]);
    }

    /** 有消息日志的日期（倒序） */
    private function messageDays(int $limit = 60): array {
        $root = $this->root . '/runtime/message';
        $days = [];
        foreach (glob($root . '/*/*/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $parts = explode('/', $dir);
            $n = count($parts);
            if ($n < 3) {
                continue;
            }
            $day = $parts[$n - 3] . '-' . $parts[$n - 2] . '-' . $parts[$n - 1];
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) && glob($dir . '/*.log')) {
                $days[] = $day;
            }
        }
        $days = array_values(array_unique($days));
        rsort($days, SORT_STRING);
        return array_slice($days, 0, $limit);
    }

    /** "2026-11-03" / "20261103" / "" -> "Y-m-d" */
    private function normalizeDay(string $value): ?string {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return null;
    }

    // ------------------------------------------------------------ 纯文本日志

    private function plainDir(): string {
        return defined('__logger__') ? (string)constant('__logger__') : $this->root . '/runtime/log';
    }

    /**
     * 日期 => 纯文本日志文件列表。
     * 轮转文件名里带日期（logger.log.20261104-012640），当天文件则按行内时间戳判断。
     * @return array<string, string[]>
     */
    private function plainIndex(): array {
        $dir = $this->plainDir();
        $out = [];
        if (is_file($dir . '/logger.log')) {
            $out[date('Y-m-d', (int)@filemtime($dir . '/logger.log') ?: time())][] = $dir . '/logger.log';
        }
        foreach (glob($dir . '/logger.log.*') ?: [] as $file) {
            if (preg_match('/\.(\d{4})(\d{2})(\d{2})-\d{6}$/', $file, $m)) {
                $out[$m[1] . '-' . $m[2] . '-' . $m[3]][] = $file;
            }
        }
        return $out;
    }

    /**
     * 纯文本日志里出现过的日期。
     * 轮转文件按文件名取；当前文件只能扫行内时间戳（只扫末尾 2MB，够用且不拖慢页面）。
     * @return string[]
     */
    private function plainDates(): array {
        $days = array_keys($this->plainIndex());
        $current = $this->plainDir() . '/logger.log';
        if (is_file($current) && is_readable($current)) {
            foreach ($this->tailLines($current, self::PLAIN_TAIL_BYTES) as $line) {
                if (preg_match('/^[(d{4}-d{2}-d{2}) d{2}:d{2}:d{2}/', $line, $m)) {
                    $days[] = $m[1];
                }
            }
        }
        $days = array_values(array_unique($days));
        rsort($days, SORT_STRING);
        return $days;
    }

    /** 读某天纯文本日志（倒序），按时间戳过滤日期 */
    private function readPlain(string $day, int $limit, ?string $level): array {
        $candidates = [$this->plainDir() . '/logger.log'];
        foreach ($this->plainIndex()[$day] ?? [] as $file) {
            if (!in_array($file, $candidates, true)) {
                $candidates[] = $file;
            }
        }
        $items = [];
        foreach ($candidates as $file) {
            if (!is_file($file) || !is_readable($file)) {
                continue;
            }
            $lines = $this->tailLines($file, self::PLAIN_TAIL_BYTES);
            foreach (array_reverse($lines) as $line) {
                $record = $this->parseLine($line);
                if ($record === null || !str_starts_with((string)$record['time'], $day)) {
                    continue;
                }
                if ($level !== null && $level !== '' && strcasecmp((string)$record['level'], $level) !== 0) {
                    continue;
                }
                $record['_sort'] = $record['time'];
                $items[] = $record;
                if (count($items) >= $limit) {
                    break 2;
                }
            }
        }
        return $items;
    }

    /** 只读文件末尾 N 字节并切成行（避免整文件读入） */
    private function tailLines(string $file, int $bytes): array {
        $size = (int)@filesize($file);
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return [];
        }
        if ($size > $bytes) {
            fseek($handle, $size - $bytes);
            fgets($handle); // 丢掉可能被截断的第一行
        }
        $chunk = (string)stream_get_contents($handle);
        fclose($handle);
        return $chunk === '' ? [] : explode("\n", rtrim($chunk, "\n"));
    }

    /** 解析 "[2026-11-04 01:26:40.017] [WARNING ] [OneBot-WS] 反向 WS 客户端断开" */
    private function parseLine(string $line): ?array {
        if (!preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})(?:\.\d+)?\]\s+\[(\w+)\s*\]\s*(.*)$/', $line, $m)) {
            return null;
        }
        $rest = $m[3];
        $channel = '';
        if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $rest, $ch)) {
            $channel = $ch[1];
            $rest = $ch[2];
        }
        return [
            'time'    => $m[1],
            'level'   => strtoupper($m[2]),
            'channel' => $channel,
            'message' => $rest,
        ];
    }

    /** 把 Core\Log 的 {ts,...} 记录统一成前端要的 {time,level,channel,message} */
    private function normalizeAll(array $records): array {
        $out = [];
        foreach ($records as $record) {
            if (!is_array($record)) {
                continue;
            }
            $ts = $record['ts'] ?? null;
            $time = is_numeric($ts) ? date('Y-m-d H:i:s', (int)$ts) : (string)($record['time'] ?? '');
            $out[] = [
                'time'    => $time,
                'level'   => strtoupper((string)($record['level'] ?? 'INFO')),
                'channel' => (string)($record['channel'] ?? ''),
                'message' => (string)($record['message'] ?? ''),
                'context' => $record['context'] ?? null,
                '_sort'   => $time,
            ];
        }
        return $out;
    }
}