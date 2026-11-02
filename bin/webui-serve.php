#!/usr/bin/env php
<?php
/**
 * WebUI 本地启动器：不依赖 Swoole / Redis 就能把整个 WebUI 跑起来。
 *
 *   php bin/webui-serve.php [--port=8090] [--host=127.0.0.1]
 *
 * 首次启动会自动创建 admin 账号；若 Config.ini 里没有 webui.password，
 * 会生成随机密码并写入 runtime/webui/initial-password.txt 后打印到终端。
 */
require dirname(__DIR__) . '/Include.php';

$options = getopt('', ['host::', 'port::']);
$host = (string)($options['host'] ?? '127.0.0.1');
$port = (string)($options['port'] ?? '8090');

\Core\App::boot();

$created = \Webui\Auth::bootstrap();
echo "\n";
echo "  Hyper Bot WebUI\n";
echo "  ─────────────────────────────────────\n";
echo "  地址   http://{$host}:{$port}/webui/\n";
echo "  存储   " . get_class(\Core\App::boot()->store) . "\n";
echo "  配置   " . \Core\Config::path() . "\n";
if ($created !== null) {
    echo "  账号   admin\n";
    if ($created['generated']) {
        $password = (string)($created['password'] ?? '');
        if (!empty($created['password_saved'])) {
            echo "  密码   {$password}（已写入 {$created['password_file']}）\n";
        } else {
            echo "  密码   {$password}（无法写入文件，请立即记下并登录后修改）\n";
        }
    } else {
        echo "  密码   使用 Config.ini 的 webui.password\n";
    }
} else {
    echo "  账号   已存在（runtime/webui/users.json）\n";
}
echo "  ─────────────────────────────────────\n\n";

// 部分环境里 opcache 的锁文件目录不可写（如 Termux 的 tmp 被清理），
// 内置服务器不需要 opcache，显式关闭以免启动即崩。
@mkdir(sys_get_temp_dir(), 0775, true);
putenv('TMPDIR=' . sys_get_temp_dir());

$cmd = sprintf(
    '%s -d opcache.enable=0 -d opcache.enable_cli=0 -S %s:%s -t %s %s',
    escapeshellarg(PHP_BINARY),
    escapeshellarg($host),
    escapeshellarg($port),
    escapeshellarg(__DIR__ . '/../public'),
    escapeshellarg(__DIR__ . '/../public/dev-server.php')
);
passthru($cmd, $code);
exit($code);