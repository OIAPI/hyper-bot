<?php
namespace Core\Store;

/**
 * 持久层 + Redis 缓存中间层。
 *
 * 固定数据落 MySQL/PostgreSQL，Redis 只做读缓存：
 *  - 写：先写持久层，再写缓存；
 *  - 读：缓存命中直接返回，未命中回源并回填；
 *  - Redis 不可用/超时：自动降级为直读持久层，不阻塞业务；
 *  - keys/incr/zset 等集合与计数操作直接走持久层，避免缓存一致性问题。
 */
final class CachedStore implements Store
{
    private bool $cacheOk = true;

    public function __construct(
        private readonly Store $primary,
        private readonly Store $cache,
        private readonly int $ttl = 30,
    ) {}

    private function cacheGet(string $key): mixed
    {
        if (!$this->cacheOk) {
            return null;
        }
        try {
            $cached = $this->cache->get($key);
            if (is_array($cached) && array_key_exists('v', $cached)) {
                return $cached['v'];
            }
            return null;
        } catch (\Throwable $e) {
            $this->cacheOk = false;
            \Utils\Logger::warning('[store] Redis 缓存读失败，本次进程降级为直读持久层：' . $e->getMessage());
            return null;
        }
    }

    private function cacheSet(string $key, mixed $value, int $ttl = 0): void
    {
        if (!$this->cacheOk) {
            return;
        }
        try {
            $this->cache->set($key, ['v' => $value], $ttl > 0 ? $ttl : $this->ttl);
        } catch (\Throwable $e) {
            $this->cacheOk = false;
            \Utils\Logger::warning('[store] Redis 缓存写失败，本次进程降级为直读持久层：' . $e->getMessage());
        }
    }

    private function cacheDel(string $key): void
    {
        if (!$this->cacheOk) {
            return;
        }
        try {
            $this->cache->del($key);
        } catch (\Throwable) {
            $this->cacheOk = false;
        }
    }

    public function get(string $key): mixed
    {
        $cached = $this->cacheGet($key);
        if ($cached !== null) {
            return $cached;
        }
        $value = $this->primary->get($key);
        if ($value !== null) {
            $this->cacheSet($key, $value);
        }
        return $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        $ok = $this->primary->set($key, $value, $ttl);
        if ($ok) {
            $this->cacheSet($key, $value);
        }
        return $ok;
    }

    public function del(string ...$keys): int
    {
        $count = $this->primary->del(...$keys);
        foreach ($keys as $key) {
            $this->cacheDel($key);
        }
        return $count;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    public function keys(string $prefix = '*'): array
    {
        return $this->primary->keys($prefix);
    }

    public function incr(string $key, int $by = 1): int
    {
        $value = $this->primary->incr($key, $by);
        $this->cacheSet($key, $value);
        return $value;
    }

    public function zadd(string $key, float $score, string $member): bool
    {
        return $this->primary->zadd($key, $score, $member);
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array
    {
        return $this->primary->zrange($key, $start, $stop, $rev);
    }

    public function zrem(string $key, string ...$members): int
    {
        return $this->primary->zrem($key, ...$members);
    }

    public function zcard(string $key): int
    {
        return $this->primary->zcard($key);
    }

    public function ztrim(string $key, int $keep): int
    {
        return $this->primary->ztrim($key, $keep);
    }
}
