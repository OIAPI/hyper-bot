<?php
namespace Core\Log;

/** 控制台输出：带颜色，级别可过滤（CLI / 开发服务器用）。 */
final class ConsoleHandler implements Handler {
    private const COLORS = [
        'debug'   => "\033[34m",
        'info'    => "\033[36m",
        'success' => "\033[32m",
        'warning' => "\033[33m",
        'error'   => "\033[31m",
        'fatal'   => "\033[41m",
        'reset'   => "\033[0m",
    ];

    public function __construct(private bool $color = true, private bool $enabled = true) {}

    public function handle(array $record): void {
        if (!$this->enabled) {
            return;
        }
        $level = $record['level'];
        $ctx = $record['context'] === [] ? '' : ' ' . json_encode($record['context'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $line = "[{$record['time']}] [" . strtoupper($level) . "] [{$record['channel']}] {$record['message']}{$ctx}";
        if ($this->color) {
            $line = (self::COLORS[$level] ?? '') . $line . self::COLORS['reset'];
        }
        fwrite(STDERR, $line . PHP_EOL);
    }
}
