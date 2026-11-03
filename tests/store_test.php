<?php
declare(strict_types=1);

/** 存储降级：主存储（Redis）抛错时必须自动改用文件存储，而不是让整个面板 500 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Store\FileStore;
use Core\Store\ResilientStore;
use Core\Store\Store;

$ok = 0;
$fail = 0;
function check(string $name, bool $pass, string $detail = ''): void {
    global $ok, $fail;
    if ($pass) { $ok++; echo "  OK   {$name}" . PHP_EOL; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? "（{$detail}）" : '') . PHP_EOL; }
}

/** 模拟「连得上但不是 Redis」：每次调用都抛协议错误 */
final class BrokenStore implements Store {
    public int $calls = 0;
    private function boom(): never { throw new \RuntimeException("protocol error, got '{' as reply type byte"); }
    public function get(string $key): mixed { $this->calls++; $this->boom(); }
    public function set(string $key, mixed $value, int $ttl = 0): bool { $this->calls++; $this->boom(); }
    public function del(string ...$keys): int { $this->calls++; $this->boom(); }
    public function has(string $key): bool { $this->calls++; $this->boom(); }
    public function keys(string $prefix = '*'): array { $this->calls++; $this->boom(); }
    public function incr(string $key, int $by = 1): int { $this->calls++; $this->boom(); }
    public function zadd(string $key, float $score, string $member): bool { $this->calls++; $this->boom(); }
    public function zrange(string $key, int $start, int $stop, bool $rev = false): array { $this->calls++; $this->boom(); }
    public function zrem(string $key, string ...$members): int { $this->calls++; $this->boom(); }
    public function zcard(string $key): int { $this->calls++; $this->boom(); }
    public function ztrim(string $key, int $keep): int { $this->calls++; $this->boom(); }
}

$dir = sys_get_temp_dir() . '/hyper-store-test-' . getmypid();
@mkdir($dir, 0775, true);
$broken = new BrokenStore();
$store = new ResilientStore($broken, new FileStore($dir));

check('降级前 degraded=false', $store->degraded() === false);
check('set 失败时走文件存储', $store->set('k', ['a' => 1]) === true);
check('get 能读回降级后写入的值', $store->get('k') === ['a' => 1]);
check('降级后 degraded=true', $store->degraded() === true);
check('incr 在文件存储上可用', $store->incr('n', 2) === 2);
check('has 命中', $store->has('n') === true);
check('del 生效', $store->del('k') === 1);
check('主存储只被调用一次（之后不再打它）', $broken->calls === 1, 'calls=' . $broken->calls);
check('keys 可枚举', is_array($store->keys('*')));

// zset 语义在文件存储上也要能跑（事件时间线用）
$store->zadd('z', 1.0, 'a');
$store->zadd('z', 2.0, 'b');
check('zrange 正序', $store->zrange('z', 0, -1) === ['a', 'b']);
check('zcard', $store->zcard('z') === 2);

foreach (glob($dir . '/*') ?: [] as $f) { @unlink($f); }
@rmdir($dir);

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
