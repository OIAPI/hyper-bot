<?php
namespace Core\Log;

/** 按天分文件的结构化日志（JSON Lines），便于 WebUI 与 grep 双重检索。 */
final class FileHandler implements Handler {
    public function __construct(private string $dir, private bool $enabled = true) {}

    public function handle(array $record): void {
        if (!$this->enabled) {
            return;
        }
        $day = date('Y-m-d', (int)$record['ts']);
        $dir = rtrim($this->dir, '/') . '/' . substr($day, 0, 7);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        @file_put_contents($dir . '/' . $day . '.jsonl', $line, FILE_APPEND | LOCK_EX);
    }

    /** 读取某天的记录（WebUI 日志页） */
    public function read(string $day, int $limit = 200, ?string $level = null): array {
        $file = rtrim($this->dir, '/') . '/' . substr($day, 0, 7) . '/' . $day . '.jsonl';
        if (!is_file($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_reverse($lines);
        $out = [];
        $min = $level !== null && $level !== '' ? (Logger::LEVELS[strtolower($level)] ?? 0) : 0;
        foreach ($lines as $line) {
            $record = json_decode($line, true);
            if (!is_array($record)) {
                continue;
            }
            if ($min > 0 && (Logger::LEVELS[$record['level'] ?? 'info'] ?? 0) < $min) {
                continue;
            }
            $out[] = $record;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /** 可用日志日期（倒序） */
    public function days(int $limit = 30): array {
        $root = rtrim($this->dir, '/');
        if (!is_dir($root)) {
            return [];
        }
        $days = [];
        foreach (scandir($root) ?: [] as $month) {
            if ($month === '.' || $month === '..') {
                continue;
            }
            $monthDir = $root . '/' . $month;
            if (!is_dir($monthDir)) {
                continue;
            }
            foreach (scandir($monthDir) ?: [] as $file) {
                if (str_ends_with($file, '.jsonl')) {
                    $days[] = substr($file, 0, -6);
                }
            }
        }
        rsort($days);
        return array_slice($days, 0, $limit);
    }
}
