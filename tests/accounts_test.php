<?php
declare(strict_types=1);
/**
 * 多账号自检：账号仓库、事件路由、协议配置覆盖。
 *   php tests/accounts_test.php
 */
require __DIR__ . '/../Include.php';

$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$fail) {
    echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($detail !== '' && !$cond ? "  ({$detail})" : '') . PHP_EOL;
    if (!$cond) $fail++;
};

$tmp = sys_get_temp_dir() . '/acc-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0775, true);
\Core\Bot\Accounts::path($tmp . '/accounts.json');

echo "== 账号仓库 ==\n";
$r1 = \Core\Bot\Accounts::upsert([
    'id' => 'onebot-111', 'name' => '主号', 'protocol' => 'onebot', 'self_id' => '111',
    'api' => 'http://127.0.0.1:3000', 'token' => 'tok-a', 'forward_url' => 'ws://127.0.0.1:6700',
]);
$ok('创建 onebot 账号', $r1['ok'], $r1['msg']);
$r2 = \Core\Bot\Accounts::upsert([
    'id' => 'milky-222', 'name' => '测试号', 'protocol' => 'milky', 'self_id' => '222',
    'api' => 'http://127.0.0.1:3100', 'token' => 'tok-b',
]);
$ok('创建 milky 账号', $r2['ok'], $r2['msg']);
$ok('两个账号都在', count(\Core\Bot\Accounts::all()) === 2);

$ok('bySelfId 命中 onebot', (\Core\Bot\Accounts::bySelfId('111')['id'] ?? '') === 'onebot-111');
$ok('bySelfId 命中 milky', (\Core\Bot\Accounts::bySelfId('222')['id'] ?? '') === 'milky-222');
$ok('bySelfId 未知返回 null', \Core\Bot\Accounts::bySelfId('999') === null);

$dup = \Core\Bot\Accounts::upsert(['protocol' => 'onebot', 'self_id' => '111', 'id' => 'onebot-333']);
$ok('同协议 self_id 重复被拒', !$dup['ok'], $dup['msg']);
$dup2 = \Core\Bot\Accounts::upsert(['protocol' => 'milky', 'self_id' => '111']);
$ok('不同协议 self_id 相同允许', $dup2['ok']);

$badId = \Core\Bot\Accounts::upsert(['protocol' => 'onebot', 'self_id' => '444', 'id' => 'Bad Id!']);
$ok('非法 id 被拒', !$badId['ok']);
$badApi = \Core\Bot\Accounts::upsert(['protocol' => 'onebot', 'self_id' => '555', 'api' => 'ftp://x']);
$ok('非 http(s) api 被拒', !$badApi['ok']);
$badSelf = \Core\Bot\Accounts::upsert(['protocol' => 'onebot', 'self_id' => 'has space']);
$ok('self_id 非法字符被拒', !$badSelf['ok']);

$row = \Core\Bot\Accounts::publicRow(\Core\Bot\Accounts::get('onebot-111'));
$ok('publicRow 不回显 token', !isset($row['token']));
$ok('publicRow 标记 token_set', ($row['token_set'] ?? false) === true);

$upd = \Core\Bot\Accounts::upsert(['id' => 'onebot-111', 'protocol' => 'onebot', 'self_id' => '111',
    'api' => 'http://127.0.0.1:3001', 'name' => '改名', 'enable' => false]);
$ok('更新账号', $upd['ok'], $upd['msg']);
$ok('空 token 不清空原值', (\Core\Bot\Accounts::get('onebot-111')['token'] ?? '') === 'tok-a');
$ok('更新生效', (\Core\Bot\Accounts::get('onebot-111')['name'] ?? '') === '改名');
$ok('enable 可关闭', (\Core\Bot\Accounts::get('onebot-111')['enable'] ?? true) === false);
$ok('enabled() 只返回启用的', count(\Core\Bot\Accounts::enabled()) === count(\Core\Bot\Accounts::all()) - 1);

echo "== 协议配置覆盖 ==\n";
// 全局配置里 onebot.api 是全局值，压栈后应变成账号自己的
$globalApi = \Protocol\Core\Config::string('onebot.api', '');
\Core\Bot\AccountContext::use(\Core\Bot\Accounts::get('milky-222'));
$ok('账号内 onebot.api 仍是全局（协议不匹配）', \Protocol\Core\Config::string('onebot.api', '') === $globalApi);
$ok('账号内 milky.api 取账号值', \Protocol\Core\Config::string('milky.api', '') === 'http://127.0.0.1:3100');
$ok('账号内 milky.token 取账号值', \Protocol\Core\Config::string('milky.token', '') === 'tok-b');
$ok('account.id 可读', \Core\Bot\AccountContext::id() === 'milky-222');
$ok('account.self_id 可读', \Protocol\Core\Config::string('account.self_id', '') === '222');
\Core\Bot\AccountContext::reset();
$ok('出栈后恢复全局', \Core\Bot\AccountContext::current() === null);

echo "== 事件路由 ==\n";
$dispatched = \Protocol\Core\EventDispatcher::dispatch([
    'post_type' => 'message', 'message_type' => 'private', 'self_id' => 222,
    'user_id' => 1001, 'message' => 'hi', 'raw_message' => 'hi', 'time' => time(),
]);
$ok('dispatch 返回 ok', ($dispatched['retcode'] ?? -1) === 0);
$ok('事件处理完已弹栈', \Core\Bot\AccountContext::current() === null);

echo "== 删除保护 ==\n";
$d1 = \Core\Bot\Accounts::remove('milky-222');
$ok('删除非最后一个账号', $d1['ok'], $d1['msg']);
// 清到只剩一个，再删就该被拒
$guard = 0;
while (count(\Core\Bot\Accounts::all()) > 1 && $guard++ < 10) {
    $rows = \Core\Bot\Accounts::all();
    \Core\Bot\Accounts::remove((string)$rows[0]['id']);
}
$ok('已清到只剩一个', count(\Core\Bot\Accounts::all()) === 1);
$lastId = (string)\Core\Bot\Accounts::all()[0]['id'];
$d2 = \Core\Bot\Accounts::remove($lastId);
$ok('拒绝删除最后一个账号', !$d2['ok'], $d2['msg']);

@unlink($tmp . '/accounts.json');
@rmdir($tmp);

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);
