<?php
namespace Core\Store;

/**
 * 存储抽象。
 *
 * 历史上所有代码直接 `Cache::$api->redis`，导致：
 *  - 单测 / CLI 环境必须有 Redis 才能跑；
 *  - 协程版与同步版 Redis 两套 API 混用（`Swoole\Coroutine\Redis` vs \Redis）；
 *  - 无法替换为文件/内存实现。
 *
 * 本接口把「键值 + 少量集合语义」收敛成 8 个方法，实现可自由替换。
 */
interface Store {
    public function get(string $key): mixed;
    public function set(string $key, mixed $value, int $ttl = 0): bool;
    public function del(string ...$keys): int;
    public function has(string $key): bool;
    /** @return list<string> */
    public function keys(string $prefix = '*'): array;
    public function incr(string $key, int $by = 1): int;
    /** 有序集合：按分数排序取区间（用于事件时间线） */
    public function zadd(string $key, float $score, string $member): bool;
    /** @return list<string> */
    public function zrange(string $key, int $start, int $stop, bool $rev = false): array;
    public function zrem(string $key, string ...$members): int;
    public function zcard(string $key): int;
    public function ztrim(string $key, int $keep): int;
}
