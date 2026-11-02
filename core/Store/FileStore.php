<?php
namespace Core\Store;

/**
 * 文件存储：无需 Redis 也能跑完整功能（单机小规模部署 / 本地开发）。
 * 数据落在 runtime/store，键名做 sha1 后分两级目录，避免单目录文件过多。
 */
final class FileStore implements Store {
    private array $cache = [];

    public function __construct(private string $root) {
        if (!is_dir($root)) {
            @mkdir($root, 0775, true);
        }
    }

    public function get(string $key): mixed {
        $raw = $this->read($key);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded['value'] ?? null : $raw;
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool {
        $record = ['value' => $value, 'ttl' => $ttl > 0 ? time() + $ttl : 0];
        return $this->write($key, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'null');
    }

    public function del(string ...$keys): int {
        $count = 0;
        foreach ($keys as $key) {
            $path = $this->path($key);
            if (is_file($path)) {
                @unlink($path);
                $count++;
            }
            unset($this->cache[$key]);
        }
        return $count;
    }

    public function has(string $key): bool {
        return $this->get($key) !== null;
    }

    public function keys(string $prefix = '*'): array {
        $out = [];
        foreach ($this->scan($this->root) as $path) {
            $record = json_decode((string)@file_get_contents($path), true);
            if (!is_array($record)) {
                continue;
            }
            if (!empty($record['ttl']) && $record['ttl'] < time()) {
                @unlink($path);
                continue;
            }
            $key = $record['key'] ?? null;
            if (is_string($key) && ($prefix === '*' || $prefix === '' || str_starts_with($key, rtrim($prefix, '*')))) {
                $out[] = $key;
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
        $set = $this->zset($key);
        $set[$member] = $score;
        return $this->set($key . ':z', $set);
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array {
        $set = $this->zset($key);
        asort($set);
        $members = array_keys($set);
        if ($rev) {
            $members = array_reverse($members);
        }
        $stop = $stop < 0 ? count($members) + $stop : $stop;
        return array_slice($members, $start, $stop - $start + 1);
    }

    public function zrem(string $key, string ...$members): int {
        $set = $this->zset($key);
        $count = 0;
        foreach ($members as $member) {
            if (isset($set[$member])) {
                unset($set[$member]);
                $count++;
            }
        }
        $this->set($key . ':z', $set);
        return $count;
    }

    public function zcard(string $key): int {
        return count($this->zset($key));
    }

    public function ztrim(string $key, int $keep): int {
        $set = $this->zset($key);
        if (count($set) <= $keep) {
            return 0;
        }
        asort($set);
        $drop = array_slice(array_keys($set), 0, count($set) - $keep);
        foreach ($drop as $member) {
            unset($set[$member]);
        }
        $this->set($key . ':z', $set);
        return count($drop);
    }

    private function zset(string $key): array {
        $set = $this->get($key . ':z');
        return is_array($set) ? $set : [];
    }

    private function path(string $key): string {
        $hash = sha1($key);
        return $this->root . '/' . substr($hash, 0, 2) . '/' . substr($hash, 2, 2) . '/' . $hash . '.json';
    }

    private function read(string $key): ?string {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $record = json_decode((string)@file_get_contents($path), true);
        if (!is_array($record)) {
            return null;
        }
        if (!empty($record['ttl']) && $record['ttl'] < time()) {
            @unlink($path);
            return null;
        }
        return json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function write(string $key, string $payload): bool {
        $path = $this->path($key);
        $dir = dirname($path);
        if (!\Core\Fs::ensureDir($dir)) {
            return false;
        }
        $record = json_decode($payload, true);
        if (is_array($record)) {
            $record['key'] = $key;
            $payload = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        return \Core\Fs::write($path, $payload);
    }

    /** @return list<string> */
    private function scan(string $dir): array {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                array_push($out, ...$this->scan($path));
            } elseif (str_ends_with($entry, '.json')) {
                $out[] = $path;
            }
        }
        return $out;
    }
}