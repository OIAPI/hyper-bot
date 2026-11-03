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
$pwFile = Auth::dir() . '/initial-password.txt';
$pwBackup = is_file($pwFile) ? (string)file_get_contents($pwFile) : null;

/** 清掉 Auth 的进程内缓存（测「文件被外部改动」时才需要） */
function auth_reset(): void {
    $ref = new \ReflectionClass(Auth::class);
    foreach (['users' => null, 'lastGeneratedPassword' => null, 'lastPasswordSaved' => false, 'storageError' => null] as $name => $value) {
        $ref->getProperty($name)->setValue(null, $value);
    }
}

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

    // 6. 全新部署：users.json 不存在时 bootstrap 自动建 admin 并写初始密码文件
    @unlink($file);
    @unlink($pwFile);
    auth_reset();
    $boot = Auth::bootstrap();
    check('首次启动创建账号', is_array($boot) && ($boot['username'] ?? '') === 'admin');
    check('首次启动写出密码文件', is_file($pwFile));
    $initial = is_file($pwFile) ? trim((string)file_get_contents($pwFile)) : '';
    check('初始密码可登录', $initial !== '' && Auth::verify('admin', $initial) !== null);
    check('首个账号是 owner', (Auth::users()['admin']['role'] ?? '') === 'owner');

    // 7. 账号文件损坏时不能「静默重建」把原账号覆盖掉
    file_put_contents($file, '{broken json');
    auth_reset();
    check('损坏文件被识别', Auth::storageError() !== null);
    check('损坏时不重建账号', Auth::bootstrap() === null);
    check('损坏时文件未被覆盖', trim((string)file_get_contents($file)) === '{broken json');

    // 8. 重置密码不会把账号降级
    file_put_contents($file, $backup ?? '{}');
    auth_reset();
    Auth::setPassword('tester', 'Passw0rd!', 'admin');
    Auth::setPassword('tester', 'ThirdPassw0rd!');
    check('重置后角色不变', (Auth::users()['tester']['role'] ?? '') === 'admin');
} finally {
    if ($backup !== null) {
        file_put_contents($file, $backup);
        @chmod($file, 0600);
    } else {
        @unlink($file);
    }
    if ($pwBackup !== null) {
        file_put_contents($pwFile, $pwBackup);
        @chmod($pwFile, 0600);
    } else {
        @unlink($pwFile);
    }
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);