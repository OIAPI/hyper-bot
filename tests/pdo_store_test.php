<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Store\PdoStore;
use Core\Store\MemoryStore;
use Core\Store\CachedStore;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

$file = sys_get_temp_dir() . '/hyper-pdo-' . getmypid() . '.sqlite';
@unlink($file);
$store = PdoStore::fromDsn('sqlite:' . $file, '', '', [], 'test_');
$check('set/get 字符串', $store->set('k1', 'v1') && $store->get('k1') === 'v1');
$check('set/get 数组', $store->set('k2', ['a' => 1]) && $store->get('k2') === ['a' => 1]);
$check('has', $store->has('k1') && !$store->has('none'));
$check('keys 前缀', in_array('k1', $store->keys('k*'), true) && in_array('k2', $store->keys('k*'), true));
$check('incr', $store->incr('cnt', 2) === 2 && $store->incr('cnt') === 3);
$check('del', $store->del('k1') === 1 && $store->get('k1') === null);
$check('zadd/zrange', $store->zadd('z', 2.0, 'b') && $store->zadd('z', 1.0, 'a')
    && $store->zrange('z', 0, 10) === ['a', 'b']);
$check('zrange rev', $store->zrange('z', 0, 10, true) === ['b', 'a']);
$check('zcard', $store->zcard('z') === 2);
$check('ztrim', $store->ztrim('z', 1) === 1 && $store->zcard('z') === 1);
$check('zrem', $store->zrem('z', 'b') === 1);

// 直接 new PDO + new PdoStore：不依赖 fromDsn 的默认 FETCH_ASSOC
$file2 = sys_get_temp_dir() . '/hyper-pdo-direct-' . getmypid() . '.sqlite';
@unlink($file2);
$direct = new PdoStore(new \PDO('sqlite:' . $file2), 'direct_');
$direct->set('d1', 'v1');
$direct->set('d2', ['a' => 1]);
$check('直接构造 get 字符串', $direct->get('d1') === 'v1');
$check('直接构造 get 数组', $direct->get('d2') === ['a' => 1]);
$check('直接构造 keys 不报 Undefined k', in_array('d1', $direct->keys('d*'), true));
@unlink($file2);

// PID 变化（模拟 Swoole worker fork）后应通过 factory 重连
$file3 = sys_get_temp_dir() . '/hyper-pdo-fork-' . getmypid() . '.sqlite';
@unlink($file3);
$forked = PdoStore::fromDsn('sqlite:' . $file3, '', '', [], 'fork_');
$forked->set('f1', 'v1');
$pidProp = new ReflectionProperty(PdoStore::class, 'pid');
$pidProp->setValue($forked, 0);
$check('PID 变化后自动重连', $forked->get('f1') === 'v1');
@unlink($file3);

// TTL
$store->set('ttl', 'x', 1);
$check('TTL 未过期可读', $store->get('ttl') === 'x');
sleep(2);
$check('TTL 到期读不到', $store->get('ttl') === null);

// CachedStore：持久层 + 缓存层
$primary = new MemoryStore();
$cache = new MemoryStore();
$cached = new CachedStore($primary, $cache, 30);
$check('缓存写入后持久层可读', $cached->set('a', '1') && $primary->get('a') === '1');
$primary->set('a', '2');
$check('缓存命中返回旧值（预期缓存语义）', $cached->get('a') === '1');
$cached->del('a');
$check('删除同时清缓存', $cached->get('a') === null && $primary->get('a') === null);

// ---------------------------------------------------------------------------
// 断线自愈：MySQL wait_timeout / 服务端重启 后长连接永久失效
//
// 真实故障（用户反馈）：长时间运行后每个事件都报
//   SQLSTATE[HY000]: General error: 2006 MySQL server has gone away
// 必须重启进程才恢复 —— 因为 PDO 不会自己重连，而 PdoStore 原先只处理了
// 「Swoole worker fork 导致 PID 变化」，没有处理「同一进程内连接被服务端断开」。
//
// 用 PDO 子类模拟一条「先正常、之后永久断线」的连接：断了以后任何语句都抛
// 2006，只有换成新对象才恢复 —— 和真实 MySQL 的行为一致。
// ---------------------------------------------------------------------------
final class FlakyPdo extends \PDO
{
    /** @var \PDO|null 真实连接（null 表示这条连接已断） */
    public static ?\PDO $real = null;
    public static int $opened = 0;
    /**
     * 模拟「链路已断」：为 true 时**已存在的**连接对象一律抛 2006，
     * 就像 MySQL wait_timeout 到点后服务端掐断长连接那样。
     * 新建的连接对象（factory 重连出来的）会把它复位，代表服务端已恢复接受连接。
     */
    public static bool $dead = false;

    public function __construct()
    {
        self::$opened++;
    }

    private function live(): \PDO
    {
        if (self::$dead || self::$real === null) {
            throw new PDOException(
                'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away',
                2006
            );
        }
        return self::$real;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return $this->live()->prepare($query, $options);
    }

    public function exec(string $statement): int|false
    {
        return $this->live()->exec($statement);
    }

    public function getAttribute(int $attribute): mixed
    {
        // 连接断了也要能报出驱动名，否则 configure()/init() 无法重建
        return self::$real?->getAttribute($attribute) ?? 'sqlite';
    }

    public function setAttribute(int $attribute, mixed $value): bool
    {
        return self::$real?->setAttribute($attribute, $value) ?? true;
    }

    public function beginTransaction(): bool
    {
        return $this->live()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->live()->commit();
    }

    public function rollBack(): bool
    {
        return $this->live()->rollBack();
    }

    public function inTransaction(): bool
    {
        try {
            return $this->live()->inTransaction();
        } catch (PDOException) {
            return false;
        }
    }
}

$file4 = sys_get_temp_dir() . '/hyper-pdo-reconnect-' . getmypid() . '.sqlite';
@unlink($file4);
FlakyPdo::$real = new \PDO('sqlite:' . $file4);
FlakyPdo::$dead = false;
FlakyPdo::$opened = 0;

// factory 代表「重连」：新建一条可用连接（服务端已恢复）
$flakyFactory = static function (): \PDO {
    FlakyPdo::$dead = false;
    return new FlakyPdo();
};
$reconnecting = new PdoStore(new FlakyPdo(), 'rc_', $flakyFactory);
$check('断线测试：初始可写', $reconnecting->set('r1', 'v1'));
$check('断线测试：初始可读', $reconnecting->get('r1') === 'v1');

// 模拟 MySQL wait_timeout 到点：连接被服务端掐断，此后永远 2006
FlakyPdo::$dead = true;
$check('断线测试：连接已断时 get 不抛异常（自动重连）', $reconnecting->get('r1') === 'v1');
$check('断线测试：重连后 set 正常', $reconnecting->set('r2', 'v2') === true);
$check('断线测试：重连后读回新值', $reconnecting->get('r2') === 'v2');
$check('断线测试：确实新建过连接', FlakyPdo::$opened >= 1);
$check('断线测试：has/keys 也走重连', $reconnecting->has('r2') && in_array('r2', $reconnecting->keys('r*'), true));
$check('断线测试：incr 走重连', $reconnecting->incr('rcnt', 2) === 2);
$check('断线测试：zadd/zrange 走重连',
    $reconnecting->zadd('rz', 1.0, 'a') && $reconnecting->zrange('rz', 0, 5) === ['a']);
$check('断线测试：zcard/ztrim 走重连',
    $reconnecting->zcard('rz') === 1 && $reconnecting->ztrim('rz', 1) === 0);
$check('断线测试：del 走重连', $reconnecting->del('r2') === 1);

// 没有 factory 时无法重连：必须把真实错误抛出去，而不是静默返回空值
// 注意：连接必须先建成（构造时要建表），再模拟「之后链路断掉」
FlakyPdo::$real = new \PDO('sqlite:' . $file4);
FlakyPdo::$dead = false;
FlakyPdo::$opened = 0;
$noFactory = new PdoStore(new FlakyPdo(), 'nof_');
FlakyPdo::$dead = true;
$threw = false;
try {
    $noFactory->get('whatever');
} catch (\Throwable $e) {
    $threw = str_contains($e->getMessage(), 'gone away') || $e->getCode() == 2006;
}
$check('断线测试：无 factory 时如实抛出断线错误', $threw);

// 非连接类错误不应被当成断线吞掉重试
final class BrokenPdo extends \PDO
{
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        throw new PDOException('SQLSTATE[42S02]: Base table or view not found', 42);
    }
    public function getAttribute(int $attribute): mixed { return 'sqlite'; }
    public function setAttribute(int $attribute, mixed $value): bool { return true; }
    public function exec(string $statement): int|false { return 0; }
}
$broken = new PdoStore(new BrokenPdo(), 'brk_');
$propagated = false;
try {
    $broken->get('x');
} catch (\Throwable $e) {
    $propagated = str_contains($e->getMessage(), 'Base table');
}
$check('断线测试：SQL 错误照常抛出（不误判为断线）', $propagated);

FlakyPdo::$dead = false;
FlakyPdo::$real = null;
@unlink($file4);

@unlink($file);
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
