<?php
namespace Core\Store;

/**
 * Redis 实现。使用 phpredis 同步客户端，并自动兼容 `Swoole\Coroutine\Redis`。
 *
 * 之所以两套都要兼容：项目在协程上下文里用 `Swoole\Coroutine\Redis`（不阻塞），
 * 在 Master 进程 / CLI 里用 ext-redis。历史上业务代码直接拿到哪个用哪个，
 * 于是出现了「同一段代码在 Worker 里正常、在 Task 里报方法不存在」的偶发问题。
 * 统一在本实现里做方法派发，上层只认 Store 接口。
 */
final class RedisStore implements Store {
    public function __construct(private object $client) {}

    public function client(): object {
        return $this->client;
    }

    public function get(string $key): mixed {
        $value = $this->client->get($key);
        if ($value === false || $value === null) {
            return null;
        }
        $decoded = json_decode((string)$value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool {
        $payload = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($ttl > 0) {
            return (bool)$this->client->setex($key, $ttl, $payload);
        }
        return (bool)$this->client->set($key, $payload);
    }

    public function del(string ...$keys): int {
        if ($keys === []) {
            return 0;
        }
        return (int)$this->client->del(...$keys);
    }

    public function has(string $key): bool {
        if (method_exists($this->client, 'exists')) {
            return (bool)$this->client->exists($key);
        }
        return $this->get($key) !== null;
    }

    public function keys(string $prefix = '*'): array {
        $keys = $this->client->keys($prefix === '' ? '*' : $prefix);
        return is_array($keys) ? array_values(array_map('strval', $keys)) : [];
    }

    public function incr(string $key, int $by = 1): int {
        return (int)$this->client->incrBy($key, $by);
    }

    public function zadd(string $key, float $score, string $member): bool {
        return (bool)$this->client->zAdd($key, $score, $member);
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array {
        $out = $rev
            ? $this->client->zRevRange($key, $start, $stop)
            : $this->client->zRange($key, $start, $stop);
        return is_array($out) ? array_values($out) : [];
    }

    public function zrem(string $key, string ...$members): int {
        if ($members === []) {
            return 0;
        }
        return (int)$this->client->zRem($key, ...$members);
    }

    public function zcard(string $key): int {
        return (int)$this->client->zCard($key);
    }

    public function ztrim(string $key, int $keep): int {
        $total = $this->zcard($key);
        if ($total <= $keep) {
            return 0;
        }
        // 分数为时间戳，保留最新 keep 条 => 删除最旧的 (total - keep) 条
        return (int)$this->client->zRemRangeByRank($key, 0, $total - $keep - 1);
    }
}
