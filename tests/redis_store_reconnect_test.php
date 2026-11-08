<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Store\RedisStore;

class FakeRedis {
    public function __construct(public array $values = [], public bool $throw = false) {}
    public function get(string $key): mixed {
        if ($this->throw) throw new RuntimeException('stale connection');
        return $this->values[$key] ?? false;
    }
    public function set(string $key, mixed $value): bool { $this->values[$key] = $value; return true; }
    public function setex(string $key, int $ttl, mixed $value): bool { $this->values[$key] = $value; return true; }
    public function del(string ...$keys): int { $n = 0; foreach ($keys as $k) { if (isset($this->values[$k])) { unset($this->values[$k]); $n++; } } return $n; }
    public function exists(string $key): int { return isset($this->values[$key]) ? 1 : 0; }
    public function keys(string $p): array { return []; }
    public function incrBy(string $k, int $n): int { return 1; }
    public function zAdd(string $k, float $s, string $m): bool { return true; }
    public function zRange(string $k, int $a, int $b): array { return []; }
    public function zRevRange(string $k, int $a, int $b): array { return []; }
    public function zRem(string $k, string ...$m): int { return 0; }
    public function zCard(string $k): int { return 0; }
    public function zRemRangeByRank(string $k, int $a, int $b): int { return 0; }
}

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; } else { $fail++; echo "  FAIL {$name}\n"; }
};

$first = new FakeRedis(['a' => json_encode('old')]);
$second = new FakeRedis(['a' => json_encode('new')]);
$store = new RedisStore($first, fn() => $second);
$check('正常读取', $store->get('a') === 'old');

// 模拟 fork 后 PID 变化：客户端应通过 factory 重建
$prop = new ReflectionProperty(RedisStore::class, 'pid');
$prop->setValue($store, 0);
$check('PID 变化后自动重连', $store->get('a') === 'new');

// 模拟命令异常后重连重试
$bad = new FakeRedis([], true);
$good = new FakeRedis(['b' => json_encode('ok')]);
$store2 = new RedisStore($bad, fn() => $good);
$check('命令异常后重连重试', $store2->get('b') === 'ok');

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
