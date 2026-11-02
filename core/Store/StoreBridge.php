<?php
namespace Core\Store;

/**
 * Redis 风格的门面（v2）：把 `$redis->get/set/exists/del` 这类调用映射到 Store。
 *
 * 为什么需要它：v1 的插件代码（app/BaseApp.php、app/Plugins/*.php、controller/Traits/Worker.php）
 * 里到处都是 `$this->Redis->get("{$group}.info")`。这些代码分布在几十个文件里，
 * 而且很多是用户自己写的插件——不可能要求它们全部改写。
 *
 * 与此同时，v1 强制依赖 ext-redis：没装 Redis 扩展时，插件构造直接就 fatal。
 * v2 的内核已经抽象出 Store（Redis / 文件 / 内存三种实现），
 * 所以这里提供一个「长得像 Redis」的适配器，让老代码自动落到 Store 上——
 * 装了 Redis 就用 Redis，没装就落文件，**插件代码一行都不用改**。
 */
final class StoreBridge
{
    private int $db = 0;

    public function __construct(private readonly Store $store) {}

    public function store(): Store
    {
        return $this->store;
    }

    // ---------------- 基础读写 ----------------

    public function get(string $key): string|false
    {
        $value = $this->store->get($this->scope($key));
        if ($value === null) {
            return false;
        }
        // 老代码普遍把值当 JSON 字符串再 json_decode，这里保持字符串语义
        return is_scalar($value) ? (string)$value : (string)json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
    {
        // 老代码传的是 json_encode 后的字符串，原样存即可
        return $this->store->set($this->scope($key), $value, $ttl);
    }

    /** 兼容 setex / psetex 之类 */
    public function setex(string $key, int $ttl, mixed $value): bool
    {
        return $this->store->set($this->scope($key), $value, $ttl);
    }

    public function exists(string ...$keys): int|bool
    {
        $count = 0;
        foreach ($keys as $key) {
            if ($this->store->has($this->scope($key))) {
                $count++;
            }
        }
        // Redis 返回 int，但老代码写的是 if($redis->exists($k))，两种都成立
        return $count;
    }

    public function del(string ...$keys): int
    {
        $scoped = array_map(fn($k) => $this->scope($k), $keys);
        return $this->store->del(...$scoped);
    }

    public function keys(string $pattern = '*'): array
    {
        return array_map(fn($k) => $this->unscope($k), $this->store->keys($this->scope($pattern)));
    }

    public function incr(string $key, int $by = 1): int
    {
        return $this->store->incr($this->scope($key), $by);
    }

    public function decr(string $key, int $by = 1): int
    {
        return $this->store->incr($this->scope($key), -$by);
    }

    // ---------------- 连接性（v1 代码会检查） ----------------

    public function isConnected(): bool
    {
        return true;
    }

    public function connect(string $host = '', int $port = 0, float $timeout = 0): bool
    {
        return true;
    }

    public function pconnect(string $host = '', int $port = 0, float $timeout = 0): bool
    {
        return true;
    }

    public function auth(string $password = ''): bool
    {
        return true;
    }

    /** 逻辑库：以 key 前缀的形式模拟，保证不同 select 不串数据 */
    public function select(int $db): bool
    {
        $this->db = max(0, $db);
        return true;
    }

    public function getDbNum(): int
    {
        return $this->db;
    }

    public function ping(): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    // ---------------- 有序集合（WebUI 时间线用） ----------------

    public function zadd(string $key, float $score, string $member): bool
    {
        return $this->store->zadd($this->scope($key), $score, $member);
    }

    public function zrange(string $key, int $start, int $stop, bool $rev = false): array
    {
        return $this->store->zrange($this->scope($key), $start, $stop, $rev);
    }

    public function zrem(string $key, string ...$members): int
    {
        $scoped = $this->scope($key);
        return $this->store->zrem($scoped, ...$members);
    }

    public function zcard(string $key): int
    {
        return $this->store->zcard($this->scope($key));
    }

    // ---------------- 内部：逻辑库前缀 ----------------

    private function scope(string $key): string
    {
        return $this->db > 0 ? "db{$this->db}:{$key}" : $key;
    }

    private function unscope(string $key): string
    {
        return $this->db > 0 ? preg_replace('/^db' . $this->db . ':/', '', $key) : $key;
    }
}
