<?php
namespace Core\Store;

/**
 * 进程内存储：单测、CLI、以及 Redis 不可用时的降级实现。
 * 语义与 Redis 实现保持一致，便于切换。
 */
final class MemoryStore implements Store {
    private array $data = [];
    private array $ttl = [];
    private array $zset = [];

    public function get(string $key): mixed {
        if ($this->expired($key)) {
            return null;
        }
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool {
        $this->data[$key] = $value;
        if ($ttl > 0) {
            $this->ttl[$key] = microtime(true) + $ttl;
        } else {
            unset($this->ttl[$key]);
        }
        return true;
    }

    public function del(string ...$keys): int {
        $count = 0;
        foreach ($keys as $key) {
            if (isset($this->data[$key]) || isset($this->zset[$key])) {
                $count++;
            }
            unset($this->data[$key], $this->ttl[$key], $this->zset[$key]);
        }
        return $count;
    }

    public function has(string $key): bool {
        return !$this->expired($key) && (array_key_exists($key, $this->data) || isset($this->zset[$key]));
    }

    public function keys(string $prefix = '*'): array {
        $regex = '~^' . str_replace(['\\*', '\\?'], ['.*', '.'], preg_quote($prefix, '~')) . '$~';
        $out = [];
        foreach (array_keys($this->data) as $key) {
            if (!$this->expired($key) && preg_match($regex, (string)$key)) {
                $out[] = (string)$key;
            }
        }
        return $out;
    }

    public function incr(string $key, int $by = 1): int {
        $current = (int)($this->get($key) ?? 0);
        $next = $current + $by;
        $this->set($key, $next);
        return $next;
    }

    public function zadd(string $key, float $score, string $member): bool {
        $this->zset[$key][$member] = $score;
        return true;
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array {
        $set = $this->zset[$key] ?? [];
        uasort($set, fn($a, $b) => $a <=> $b);
        $members = array_keys($set);
        if ($rev) {
            $members = array_reverse($members);
        }
        $stop = $stop < 0 ? count($members) + $stop : $stop;
        return array_slice($members, $start, $stop - $start + 1);
    }

    public function zrem(string $key, string ...$members): int {
        $count = 0;
        foreach ($members as $member) {
            if (isset($this->zset[$key][$member])) {
                unset($this->zset[$key][$member]);
                $count++;
            }
        }
        return $count;
    }

    public function zcard(string $key): int {
        return count($this->zset[$key] ?? []);
    }

    public function ztrim(string $key, int $keep): int {
        $set = $this->zset[$key] ?? [];
        if (count($set) <= $keep) {
            return 0;
        }
        uasort($set, fn($a, $b) => $b <=> $a);
        $drop = array_slice(array_keys($set), $keep, null, true);
        foreach ($drop as $member) {
            unset($this->zset[$key][$member]);
        }
        return count($drop);
    }

    public function flush(): void {
        $this->data = [];
        $this->ttl = [];
        $this->zset = [];
    }

    private function expired(string $key): bool {
        if (!isset($this->ttl[$key])) {
            return false;
        }
        if ($this->ttl[$key] < microtime(true)) {
            unset($this->data[$key], $this->ttl[$key]);
            return true;
        }
        return false;
    }
}
