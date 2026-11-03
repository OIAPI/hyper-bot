<?php
declare(strict_types=1);

/**
 * 会话存储的单元测试。
 *
 * 重点验证「换掉缓存后端后会话还在」——这正是面板莫名其妙把人踢下线的根因：
 * 会话原来和业务缓存共用一个 Redis，Redis 一重启/一降级，token 就查不到了。
 */

define('__root__', dirname(__DIR__));
define('__runtime__', sys_get_temp_dir() . '/hyper-session-test-' . getmypid());
require __root__ . '/vendor/autoload.php';

use Core\Http\Middleware\AuthMiddleware;
use Core\Store\FileStore;

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

try {
    $cacheA = new FileStore(__runtime__ . '/cache-a');
    $cacheB = new FileStore(__runtime__ . '/cache-b');

    $a = new AuthMiddleware($cacheA);
    $token = $a->createSession(['username' => 'admin', 'role' => 'owner']);
    check('会话创建返回 token', strlen($token) === 48, (string)strlen($token));
    $session = $a->session($token);
    check('刚建的会话可读出', is_array($session) && ($session['user']['username'] ?? '') === 'admin');
    check('会话写过期时间', ($session['expires'] ?? 0) > time());

    // 模拟「缓存后端换了」（Redis 重启 / 降级）：会话仍然有效
    $b = new AuthMiddleware($cacheB);
    check('换后端后会话仍在', is_array($b->session($token)));

    // 过期会话必须判无效
    $raw = new FileStore(__runtime__ . '/store/session');
    $raw->set('webui:session:expired-token', ['user' => ['username' => 'x'], 'expires' => time() - 10], 60);
    check('过期会话判为无效', $b->session('expired-token') === null);
    check('空 token 判为无效', $b->session('') === null);

    // 登出必须让 token 立即失效
    $b->destroySession($token);
    check('登出后会话失效', $b->session($token) === null);

    // 会话落在独立目录，不和业务缓存混在一起
    check('会话文件独立存放', is_dir(__runtime__ . '/store/session'));
} finally {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__runtime__, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir(__runtime__);
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);
