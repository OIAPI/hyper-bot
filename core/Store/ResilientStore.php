<?php
namespace Core\Store;

use Utils\Logger;

/**
 * 会「自愈」的存储包装：主存储（Redis）出错时自动降级到文件存储。
 *
 * 为什么要这一层：Redis 的 TCP 连接可能连得上，但对端根本不是 Redis
 * （端口被 HTTP 服务占用、经过反向代理、配错 host），此时每次 get() 都会抛
 * "protocol error, got '{' as reply type byte"，面板每个请求都 500。
 * 对用户来说这就是「后台突然什么都用不了」，而实际上只要降级到文件存储就能继续用。
 */
final class ResilientStore implements Store {
    private bool $degraded = false;

    public function __construct(
        private Store $primary,
        private Store $fallback,
        private string $primaryName = 'redis',
    ) {}

    public function degraded(): bool {
        return $this->degraded;
    }

    /** 主存储实例（诊断命令用，比如告诉用户「面板实际在用 Redis」） */
    public function primary(): Store {
        return $this->primary;
    }

    public function primaryName(): string {
        return $this->primaryName;
    }

    /** @template T @param callable(Store):T $fn @return T */
    private function run(callable $fn, mixed $default = null): mixed
    {
        if ($this->degraded) {
            return $fn($this->fallback);
        }
        try {
            return $fn($this->primary);
        } catch (\Throwable $e) {
            $this->degraded = true;
            Logger::warning('主存储（' . $this->primaryName . '）不可用，已切换文件存储：' . $e->getMessage());
            return $fn($this->fallback);
        }
    }

    public function get(string $key): mixed
    {
        return $this->run(fn(Store $s) => $s->get($key));
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        return (bool)$this->run(fn(Store $s) => $s->set($key, $value, $ttl), false);
    }

    public function del(string ...$keys): int
    {
        return (int)$this->run(fn(Store $s) => $s->del(...$keys), 0);
    }

    public function has(string $key): bool
    {
        return (bool)$this->run(fn(Store $s) => $s->has($key), false);
    }

    /** @return list<string> */
    public function keys(string $prefix = '*'): array
    {
        return (array)$this->run(fn(Store $s) => $s->keys($prefix), []);
    }

    public function incr(string $key, int $by = 1): int
    {
        return (int)$this->run(fn(Store $s) => $s->incr($key, $by), 0);
    }

    public function zadd(string $key, float $score, string $member): bool
    {
        return (bool)$this->run(fn(Store $s) => $s->zadd($key, $score, $member), false);
    }

    /** @return list<string> */
    public function zrange(string $key, int $start, int $stop, bool $rev = false): array
    {
        return (array)$this->run(fn(Store $s) => $s->zrange($key, $start, $stop, $rev), []);
    }

    public function zrem(string $key, string ...$members): int
    {
        return (int)$this->run(fn(Store $s) => $s->zrem($key, ...$members), 0);
    }

    public function zcard(string $key): int
    {
        return (int)$this->run(fn(Store $s) => $s->zcard($key), 0);
    }

    public function ztrim(string $key, int $keep): int
    {
        return (int)$this->run(fn(Store $s) => $s->ztrim($key, $keep), 0);
    }
}
