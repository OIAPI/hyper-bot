<?php
namespace Core\Log;

/**
 * 结构化日志器。
 *
 * 历史 `Utils\Logger` 把「终端着色 / 异步通道 / 文件轮转 / 屏幕刷新」全塞在一个类里，
 * 既不好测也没法给 WebUI 用（取不出结构化数据）。
 *
 * 本类只负责三件事：
 *  1. 生成结构化记录（level / message / context / time）；
 *  2. 分发给若干 Handler（文件、内存环形缓冲、控制台）；
 *  3. 提供 `tail()` 给 WebUI 实时读取。
 */
final class Logger {
    /** @var list<Handler> */
    private array $handlers = [];
    /** @var list<array> 最近 N 条（供 WebUI 概览/实时日志用） */
    private array $ring = [];
    private int $ringSize = 500;

    public const LEVELS = ['debug' => 10, 'info' => 20, 'success' => 25, 'warning' => 30, 'error' => 40, 'fatal' => 50];

    public function __construct(private string $channel = 'app', private int $minLevel = 10) {}

    public function pushHandler(Handler $handler): self {
        $this->handlers[] = $handler;
        return $this;
    }

    public function channel(): string {
        return $this->channel;
    }

    public function debug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }

    public function success(string $message, array $context = []): void {
        $this->log('success', $message, $context);
    }

    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }

    public function log(string $level, string $message, array $context = []): void {
        $level = strtolower($level);
        $weight = self::LEVELS[$level] ?? 20;
        if ($weight < $this->minLevel) {
            return;
        }
        $record = [
            'time'    => date('Y-m-d H:i:s'),
            'ts'      => microtime(true),
            'level'   => $level,
            'channel' => $this->channel,
            'message' => $message,
            'context' => $this->sanitize($context),
        ];

        $this->ring[] = $record;
        if (count($this->ring) > $this->ringSize) {
            array_shift($this->ring);
        }

        foreach ($this->handlers as $handler) {
            try {
                $handler->handle($record);
            } catch (\Throwable) {
                // 日志失败绝不影响业务
            }
        }
    }

    /** @return list<array> */
    public function tail(int $limit = 100, ?string $level = null): array {
        $records = $this->ring;
        if ($level !== null && $level !== '') {
            $min = self::LEVELS[strtolower($level)] ?? 0;
            $records = array_values(array_filter($records, fn($r) => (self::LEVELS[$r['level']] ?? 0) >= $min));
        }
        return array_slice($records, -max(1, $limit));
    }

    /** 各等级计数（WebUI 概览用） */
    public function stats(): array {
        $stats = array_fill_keys(array_keys(self::LEVELS), 0);
        foreach ($this->ring as $record) {
            $stats[$record['level']] = ($stats[$record['level']] ?? 0) + 1;
        }
        $stats['total'] = count($this->ring);
        return $stats;
    }

    private function sanitize(array $context): array {
        $secretKeys = ['token', 'secret', 'password', 'authorization', 'client_secret', 'access_token'];
        foreach ($context as $key => $value) {
            if (is_array($value)) {
                $context[$key] = $this->sanitize($value);
                continue;
            }
            if (in_array(strtolower((string)$key), $secretKeys, true) && is_string($value) && $value !== '') {
                $context[$key] = '***';
            }
        }
        return $context;
    }
}
