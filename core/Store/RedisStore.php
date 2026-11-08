<?php
namespace Core\Store;

/**
 * Redis 实现。phpredis 同步客户端，兼容 Swoole\Coroutine\Redis。
 *
 * 连接生命周期：
 *  - App 在 master 里验证过连接，但 Swoole worker fork 后继承同一 TCP socket
 *    会串包/假死；这里记录创建 PID，worker 首次使用时用 factory 重连。
 *  - 单次命令异常自动重连重试一次；set 返回 false 也按连接失效重试。
 *  - 每进程长连接，不是每请求短连接；PHP 内置服务器每次请求新进程天然新连接。
 */
final class RedisStore implements Store {
    private ?object $client;
    private ?\Closure $factory;
    private int $pid;

    public function __construct(?object $client, ?\Closure $factory = null) {
        if ($client instanceof \Closure) {
            $this->factory = $client;
            $this->client = null;
        } else {
            $this->client = $client;
            $this->factory = $factory;
        }
        $this->pid = getmypid() ?: 0;
    }

    public function client(): object {
        if ($this->client === null || $this->pid !== (getmypid() ?: 0)) {
            if ($this->factory === null) {
                throw new \RuntimeException('Redis 连接已失效，且没有可用的重连工厂');
            }
            $this->client = ($this->factory)();
            $this->pid = getmypid() ?: 0;
        }
        return $this->client;
    }

    private function reset(): void {
        $this->client = null;
        $this->pid = 0;
    }

    private function run(callable $fn): mixed {
        $client = $this->client();
        try {
            return $fn($client);
        } catch (\Throwable $e) {
            $this->reset();
            return $fn($this->client());
        }
    }

    public function get(string $key): mixed {
        $value = $this->run(fn(object $c) => $c->get($key));
        if ($value === false || $value === null) return null;
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool {
        $payload = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $run = fn() => $ttl > 0
            ? (bool)$this->run(fn(object $c) => $c->setex($key, $ttl, $payload))
            : (bool)$this->run(fn(object $c) => $c->set($key, $payload));
        $ok = $run();
        if (!$ok) {
            $this->reset();
            $ok = $run();
        }
        return $ok;
    }

    public function del(string ...$keys): int {
        if ($keys === []) return 0;
        return (int)$this->run(fn(object $c) => $c->del(...$keys));
    }

    public function has(string $key): bool {
        return (bool)$this->run(fn(object $c) => method_exists($c, 'exists') ? $c->exists($key) : $c->get($key) !== false);
    }

    public function keys(string $prefix = '*'): array {
        $keys = $this->run(fn(object $c) => $c->keys($prefix === '' ? '*' : $prefix));
        return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
    }

    public function incr(string $key, int $by = 1): int {
        return (int)$this->run(fn(object $c) => $c->incrBy($key, $by));
    }

    public function zadd(string $key, float $score, string $member): bool {
        return (bool)$this->run(fn(object $c) => $c->zAdd($key, $score, $member));
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array {
        $out = $this->run(fn(object $c) => $rev ? $c->zRevRange($key, $start, $stop) : $c->zRange($key, $start, $stop));
        return is_array($out) ? array_values($out) : [];
    }

    public function zrem(string $key, string ...$members): int {
        if ($members === []) return 0;
        return (int)$this->run(fn(object $c) => $c->zRem($key, ...$members));
    }

    public function zcard(string $key): int {
        return (int)$this->run(fn(object $c) => $c->zCard($key));
    }

    public function ztrim(string $key, int $keep): int {
        $total = $this->zcard($key);
        if ($total <= $keep) return 0;
        return (int)$this->run(fn(object $c) => $c->zRemRangeByRank($key, 0, $total - $keep - 1));
    }
}
