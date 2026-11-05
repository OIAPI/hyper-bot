<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\AccountContext;
use Core\Bot\Accounts;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}" . ($detail !== '' ? " ({$detail})" : '') . "\n"; }
};

$tmp = sys_get_temp_dir() . '/hyper-accounts-qqbot-' . getmypid() . '.json';
@unlink($tmp);
Accounts::path($tmp);
file_put_contents($tmp, json_encode(['accounts' => [[
    'id' => 'seed-onebot', 'name' => 'seed', 'protocol' => 'onebot', 'self_id' => '1', 'enable' => false,
]]]));

$id = 'qqbot-test-' . getmypid();

$r = Accounts::upsert(['id' => $id, 'protocol' => 'qqbot', 'name' => '缺 AppID']);
$check('缺 AppID 被拒绝', !$r['ok'], $r['msg']);

$r = Accounts::upsert(['id' => $id, 'protocol' => 'qqbot', 'app_id' => '102030405', 'name' => '缺 secret']);
$check('缺 AppSecret 被拒绝', !$r['ok'], $r['msg']);

$r = Accounts::upsert([
    'id' => $id, 'protocol' => 'qqbot', 'name' => '官方号',
    'app_id' => '102030405', 'client_secret' => 'super-secret',
]);
$check('AppID + AppSecret 创建成功', (bool)$r['ok'], $r['msg']);
$row = Accounts::get($id);
$check('self_id 自动用 AppID', ($row['self_id'] ?? '') === '102030405');
$check('AppID 已保存', ($row['app_id'] ?? '') === '102030405');

$pub = Accounts::publicRow($row);
$check('publicRow 不回显 AppSecret', !array_key_exists('client_secret', $pub));
$check('publicRow 标记 secret 已配置', ($pub['client_secret_set'] ?? false) === true);
$check('默认使用生产 API', ($row['api'] ?? '') === Accounts::QQ_PROD_API);
$check('默认不是沙箱', ($row['sandbox'] ?? true) === false);
$check('Token 默认生产 API', \Protocol\QqBot\Token::apiBase() === Accounts::QQ_PROD_API);

// 沙箱开关：固定切到沙箱域名；自定义 api 一律忽略
$r = Accounts::upsert([
    'id' => $id, 'protocol' => 'qqbot', 'app_id' => '102030405',
    'sandbox' => true, 'api' => 'https://evil.example.com',
]);
$row = Accounts::get($id);
$check('沙箱开关已保存', ($row['sandbox'] ?? false) === true);
$check('沙箱 API 固定', ($row['api'] ?? '') === Accounts::QQ_SANDBOX_API);
$check('Token 跟随沙箱', \Protocol\QqBot\Token::apiBase() === Accounts::QQ_SANDBOX_API);
Accounts::upsert([
    'id' => $id, 'protocol' => 'qqbot', 'app_id' => '102030405', 'sandbox' => false,
]);
$row = Accounts::get($id);
$check('关闭沙箱回到生产', ($row['api'] ?? '') === Accounts::QQ_PROD_API);

// 编辑时 secret 留空 = 沿用
$r = Accounts::upsert([
    'id' => $id, 'protocol' => 'qqbot', 'name' => '改名',
    'app_id' => '102030405',
]);
$row = Accounts::get($id);
$check('编辑留空保留 AppSecret', ($row['client_secret'] ?? '') === 'super-secret');
$check('其他字段已更新', ($row['name'] ?? '') === '改名');

// 显式换 secret
Accounts::upsert([
    'id' => $id, 'protocol' => 'qqbot', 'app_id' => '102030405', 'client_secret' => 'new-secret',
]);
$row = Accounts::get($id);
$check('显式提交新 AppSecret', ($row['client_secret'] ?? '') === 'new-secret');

// 普通协议不保存官方字段
$r = Accounts::upsert([
    'id' => 'onebot-test', 'protocol' => 'onebot', 'self_id' => '10001',
    'app_id' => '999', 'client_secret' => 'x',
]);
$row = Accounts::get('onebot-test');
$check('普通协议忽略 AppID/AppSecret', ($row['app_id'] ?? '') === '' && ($row['client_secret'] ?? '') === '');

// AccountContext 按账号映射
AccountContext::use($row);
[$hit, $val] = AccountContext::get('qqbot.app_id');
$check('普通账号没有 qqbot.app_id', !$hit);
AccountContext::use(Accounts::get($id));
[$hit, $val] = AccountContext::get('qqbot.app_id');
$check('AccountContext 映射 AppID', $hit && (string)$val === '102030405');
[$hit, $val] = AccountContext::get('qqbot.client_secret');
$check('AccountContext 映射 AppSecret', $hit && (string)$val === 'new-secret');
$check('QqBot Token 用账号 AppID', \Protocol\QqBot\Token::appId() === '102030405');
$check('QqBot Token 用账号 AppSecret', \Protocol\QqBot\Token::secret() === 'new-secret');
$check('QqBot Webhook 验签用账号 AppSecret', \Protocol\QqBot\Webhook::secret() === 'new-secret');
AccountContext::reset();
// Gateway / WebHook 首次启动没有事件上下文：应该用第一个启用的官方账号
$check('无上下文时 Token 用启用账号 AppID', \Protocol\QqBot\Token::appId() === '102030405');
$check('无上下文时 Token 用启用账号 AppSecret', \Protocol\QqBot\Token::secret() === 'new-secret');
$check('无上下文时 Webhook 用启用账号 AppSecret', \Protocol\QqBot\Webhook::secret() === 'new-secret');

// 官方消息事件的 self_id 必须是账号标识（AppID），不能拿 READY 的 openid 当 int
AccountContext::use(Accounts::get($id));
$converted = \Protocol\QqBot\Converter::toOneBot([
    't' => 'GROUP_AT_MESSAGE_CREATE',
    'd' => [
        'group_openid' => '4982995E31B3D19A798977E3C2633AF1',
        'author' => ['member_openid' => 'ABCDEF'],
        'content' => 'hello',
        'id' => 'msg-1',
        'timestamp' => time(),
    ],
], null);
$check('官方消息 self_id 用账号 AppID', (string)($converted['self_id'] ?? '') === '102030405');
$check('官方群 group_id 保留 openid', ($converted['group_id'] ?? '') === '4982995E31B3D19A798977E3C2633AF1');
AccountContext::reset();

@unlink($tmp);
echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
