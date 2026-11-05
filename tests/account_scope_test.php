<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\App;
use Core\Bot\AccountContext;
use Core\Bot\Accounts;
use Core\Bot\GroupState;
use Core\Bot\MemberPermissions;
use Core\Bot\SubscriptionOverride;
use Core\Store\StoreBridge;

$app = App::boot();
$store = $app->store;
$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

$tmp = sys_get_temp_dir() . '/hyper-account-scope-' . getmypid() . '.json';
@unlink($tmp);
Accounts::path($tmp);
file_put_contents($tmp, json_encode(['accounts' => [[
    'id' => 'seed', 'name' => 'seed', 'protocol' => 'onebot', 'self_id' => '1', 'enable' => false,
]]]));
Accounts::upsert(['id' => 'acc-a', 'name' => 'A', 'protocol' => 'onebot', 'self_id' => '10001', 'api' => 'http://127.0.0.1:1']);
Accounts::upsert(['id' => 'acc-b', 'name' => 'B', 'protocol' => 'onebot', 'self_id' => '10002', 'api' => 'http://127.0.0.1:2']);

$a = Accounts::get('acc-a');
$b = Accounts::get('acc-b');
$group = '900000001';
$groupB = '900000002';

AccountContext::use($a);
GroupState::setInfo($store, $group, ['status' => 1, 'worker' => ['App\\Plugins\\Help' => false]]);
GroupState::setMeta($store, [$group => ['name' => 'A 的群']]);
$check('A 能读自己的群配置', (GroupState::getInfo($store, $group)['status'] ?? 0) === 1);
$check('A 的群列表包含该群', in_array($group, GroupState::ids($store), true));
$check('A 的群名隔离', (GroupState::meta($store)[$group]['name'] ?? '') === 'A 的群');

AccountContext::use($b);
$check('B 读不到 A 的群配置', GroupState::getInfo($store, $group) === null);
$check('B 的群列表不含 A 的群', !in_array($group, GroupState::ids($store), true));
$check('B 读不到 A 的群名', !isset(GroupState::meta($store)[$group]));
GroupState::setInfo($store, $groupB, ['status' => 1, 'worker' => []]);
$check('B 自己的群可写', (GroupState::getInfo($store, $groupB)['status'] ?? 0) === 1);

// 权限隔离
AccountContext::use($a);
MemberPermissions::set('123456', 'admin');
$check('A 权限已设置', MemberPermissions::get('123456') === 'admin');
AccountContext::use($b);
$check('B 读不到 A 的权限', MemberPermissions::get('123456') === null);
MemberPermissions::set('123456', 'member');
$check('B 可设自己的权限', MemberPermissions::get('123456') === 'member');
AccountContext::use($a);
$check('A 权限未被 B 覆盖', MemberPermissions::get('123456') === 'admin');

// 按群订阅覆盖隔离
$soTmp = sys_get_temp_dir() . '/hyper-sub-override-' . getmypid() . '.json';
SubscriptionOverride::path($soTmp);
AccountContext::use($a);
SubscriptionOverride::set($group, ['mute' => true]);
$check('A 的群覆盖已保存', (SubscriptionOverride::get($group)['mute'] ?? false) === true);
AccountContext::use($b);
$check('B 读不到 A 的群覆盖', SubscriptionOverride::get($group) === null);
SubscriptionOverride::set($group, ['mute' => false]);
AccountContext::use($a);
$check('A 的覆盖未被 B 覆盖', (SubscriptionOverride::get($group)['mute'] ?? true) === true);
@unlink($soTmp);
@unlink(preg_replace('/\.json$/', '.acc-a.json', $soTmp));
@unlink(preg_replace('/\.json$/', '.acc-b.json', $soTmp));

// StoreBridge（插件缓存）隔离
$bridge = new StoreBridge($store);
AccountContext::use($a);
$bridge->set('plugin.cache', 'a-value');
$check('A 插件缓存可读', $bridge->get('plugin.cache') === 'a-value');
AccountContext::use($b);
$check('B 读不到 A 的插件缓存', $bridge->get('plugin.cache') === false);
$bridge->set('plugin.cache', 'b-value');
$check('B 自己的插件缓存', $bridge->get('plugin.cache') === 'b-value');
AccountContext::use($a);
$check('A 插件缓存未被覆盖', $bridge->get('plugin.cache') === 'a-value');

// 清理
AccountContext::use($a);
GroupState::delInfo($store, $group);
GroupState::delInfo($store, $groupB);
GroupState::setMeta($store, []);
MemberPermissions::remove('123456');
$bridge->del('plugin.cache');
AccountContext::use($b);
GroupState::delInfo($store, $groupB);
GroupState::setMeta($store, []);
MemberPermissions::remove('123456');
$bridge->del('plugin.cache');
AccountContext::reset();
@unlink($tmp);

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
