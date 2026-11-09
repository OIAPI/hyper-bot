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

@unlink($file);
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
