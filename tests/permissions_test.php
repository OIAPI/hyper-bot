<?php
declare(strict_types=1);

/**
 * QQ 成员权限（memberPermission.<QQ>）的单元测试。
 *
 * 会临时写 store 里的键，跑完恢复原值（finally）。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Bot\MemberPermissions;
use Core\App;

$ok = 0;
$fail = 0;
function check(string $name, bool $pass, string $detail = ''): void {
    global $ok, $fail;
    if ($pass) {
        $ok++;
        echo "  OK   {$name}" . PHP_EOL;
    } else {
        $fail++;
        echo "  FAIL {$name}" . ($detail !== '' ? "（{$detail}）" : '') . PHP_EOL;
    }
}

$store = App::boot()->store;
$keys = ['memberPermission.10001', 'memberPermission.10002'];
$backup = [];
foreach ($keys as $key) {
    $backup[$key] = $store->get($key);
}
$added = [];

try {
    $r = MemberPermissions::set('10001', 'admin');
    check('设置管理员', $r['ok'] === true, $r['msg']);
    $added[] = '10001';
    check('读取到 admin', MemberPermissions::get('10001') === 'admin');

    $r = MemberPermissions::set('10001', 'super');
    check('升级为主人', $r['ok'] === true && MemberPermissions::get('10001') === 'super');

    $r = MemberPermissions::set('10002', 'editor');
    check('设置编辑', $r['ok'] === true);
    $added[] = '10002';

    $all = MemberPermissions::all();
    $qqs = array_column($all, 'qq');
    check('列表包含两人', in_array('10001', $qqs, true) && in_array('10002', $qqs, true));
    $row = $all[array_search('10002', $qqs, true)];
    check('列表带中文角色', ($row['label'] ?? '') === '编辑');
    check('列表带能力清单', is_array($row['capabilities'] ?? null) && in_array('command.basic', $row['capabilities'], true));
    check('角色推导等级', ($row['level'] ?? 0) === 2);

    $stats = MemberPermissions::stats();
    check('统计 total 正确', ($stats['total'] ?? 0) >= 2);
    check('统计按角色分组', ($stats['by_role']['editor'] ?? 0) >= 1);

    // 角色能力：editor 能管群命令但不能改群配置
    $r = MemberPermissions::set('10002', 'editor');
    $model = MemberPermissions::model();
    check('能力矩阵有角色', isset($model['matrix']['editor']));
    check('能力名称有中文', isset($model['capabilities']['group.config']));
    check('角色能力映射存在', in_array('command.manage', $model['matrix']['editor']['capabilities'], true));

    check('非数字 QQ 被拒', MemberPermissions::set('abc', 'admin')['ok'] === false);
    check('QQ 太短被拒', MemberPermissions::set('1234', 'admin')['ok'] === false);
    check('QQ 太长被拒', MemberPermissions::set('1234567890123', 'admin')['ok'] === false);
    check('未知角色被拒', MemberPermissions::set('10001', 'god')['ok'] === false);
    // v1 的旧等级名仍然接受，并映射成新角色
    $r = MemberPermissions::set('10001', 'manager');
    check('旧等级 manager 映射为 admin', $r['ok'] === true && MemberPermissions::get('10001') === 'admin');
    $r = MemberPermissions::set('10001', 'supervisor');
    check('旧等级 supervisor 映射为 owner', $r['ok'] === true && MemberPermissions::get('10001') === 'owner');
    // 有些 v1 部署存的是等级数字
    $r = MemberPermissions::set('10001', '6');
    check('数字等级 6 映射为 owner', $r['ok'] === true && MemberPermissions::get('10001') === 'owner');
    $r = MemberPermissions::set('10001', '2');
    check('数字等级 2 映射为 editor', $r['ok'] === true && MemberPermissions::get('10001') === 'editor');
    $r = MemberPermissions::set('10001', '7');
    check('数字等级 7（supervisor）映射为 owner', MemberPermissions::get('10001') === 'owner');

    check('删除返回 true', MemberPermissions::remove('10002') === true);
    check('删除后读不到', MemberPermissions::get('10002') === null);
    check('重复删除返回 false', MemberPermissions::remove('10002') === false);
} finally {
    foreach ($added as $qq) {
        MemberPermissions::remove($qq);
    }
    foreach ($backup as $key => $value) {
        if ($value === null) {
            $store->del($key);
        } else {
            $store->set($key, $value);
        }
    }
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);