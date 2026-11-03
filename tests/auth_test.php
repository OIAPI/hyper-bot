<?php
declare(strict_types=1);

/**
 * 面板账号与密码的单元测试。
 *
 * 会临时改动 runtime/webui/users.json，跑完恢复原样（备份在 finally 里），
 * 所以可以放心在开发机上反复跑。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Webui\Auth;

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

$file = Auth::usersFile();
$backup = is_file($file) ? (string)file_get_contents($file) : null;

try {
    // 1. 创建账号（用户不存在时应当建出来）
    $err = Auth::setPassword('tester', 'Passw0rd!', 'admin');
    check('创建账号无错误', $err === null, (string)$err);
    $user = Auth::verify('tester', 'Passw0rd!');
    check('新密码可登录', $user !== null && $user['username'] === 'tester');
    check('角色写入正确', ($user['role'] ?? '') === 'admin');

    // 2. 错误密码必须拒绝
    check('错误密码被拒绝', Auth::verify('tester', 'nope') === null);

    // 3. 弱密码拦下
    $err = Auth::setPassword('tester', '123');
    check('弱密码被拒绝', is_string($err) && str_contains($err, '至少'));

    // 4. 非法用户名拦下
    $err = Auth::setPassword('../etc', 'Passw0rd!');
    check('非法用户名被拒绝', is_string($err));

    // 5. 改密码保留第三方绑定
    Auth::bindOauth('tester', 'github', '42', 'tester-gh');
    Auth::setPassword('tester', 'NewPassw0rd!');
    check('改密码后仍能登录', Auth::verify('tester', 'NewPassw0rd!') !== null);
    check('绑定关系保留', isset(Auth::bindings('tester')['github']));
    check('绑定可反查账号', Auth::userByOauth('github', '42') === 'tester');

    // 6. 重置密码不会把账号降级
    Auth::setPassword('tester', 'ThirdPassw0rd!');
    check('重置后角色不变', (Auth::users()['tester']['role'] ?? '') === 'admin');
} finally {
    if ($backup !== null) {
        file_put_contents($file, $backup);
        @chmod($file, 0600);
    } else {
        @unlink($file);
    }
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
