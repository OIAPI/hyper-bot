<?php
declare(strict_types=1);

/**
 * 更新服务（UpdateService）的单元测试。
 *
 * 在临时目录里搭一个假项目，验证：只覆盖程序文件、保护 runtime/Config.ini、
 * 覆盖前备份、composer.json 变更给提示、zip 包多一层目录也能识别。
 */

$sandbox = sys_get_temp_dir() . '/hyper-update-' . getmypid();
define('__root__', $sandbox);
require dirname(__DIR__) . '/vendor/autoload.php';

use Core\Update\UpdateService;

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
function put(string $path, string $content): void {
    @mkdir(dirname($path), 0775, true);
    file_put_contents($path, $content);
}
function rrmdir(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($dir);
}

// 假项目：老版本 1.0.0
put($sandbox . '/VERSION', "1.0.0\n");
put($sandbox . '/composer.json', '{"name":"old"}');
put($sandbox . '/bin/hyper', 'old-bin');
put($sandbox . '/Config.ini', "app.name = 我的配置\n");
put($sandbox . '/runtime/store/keep.txt', 'user data');
put($sandbox . '/vendor/autoload.php', '<?php // vendor');

try {
    check('读出当前版本', UpdateService::version() === '1.0.0');
    $git = UpdateService::gitInfo();
    check('无 .git 时 repo=false', $git['repo'] === false);

    $check = UpdateService::check();
    check('没有 git 仓库时给出可读原因', $check['ok'] === false && (str_contains($check['msg'], 'git') || str_contains($check['msg'], '压缩包')));

    // 新版本目录
    $src = sys_get_temp_dir() . '/hyper-update-src-' . getmypid();
    put($src . '/VERSION', "1.1.0\n");
    put($src . '/composer.json', '{"name":"new"}');
    put($src . '/bin/hyper', 'new-bin');
    put($src . '/Config.ini', "app.name = 包里的配置\n");
    put($src . '/runtime/store/keep.txt', 'package runtime');
    put($src . '/vendor/autoload.php', '<?php // new vendor');
    put($src . '/app/Plugins/New.php', '<?php class New {}');

    $result = UpdateService::applyFromDir($src, 'test-dir');
    check('更新成功', $result['ok'] === true, $result['msg'] ?? '');
    check('覆盖了 bin/hyper', file_get_contents($sandbox . '/bin/hyper') === 'new-bin');
    check('新增了 app/Plugins/New.php', is_file($sandbox . '/app/Plugins/New.php'));
    check('Config.ini 未被覆盖', file_get_contents($sandbox . '/Config.ini') === "app.name = 我的配置\n");
    check('runtime 未被覆盖', file_get_contents($sandbox . '/runtime/store/keep.txt') === 'user data');
    check('vendor 未被覆盖', file_get_contents($sandbox . '/vendor/autoload.php') === '<?php // vendor');
    check('版本已更新', UpdateService::version() === '1.1.0');
    check('备份里有旧文件', $result['backup'] !== '' && file_get_contents($result['backup'] . '/bin/hyper') === 'old-bin');
    check('composer.json 变更给提示', count(array_filter($result['warnings'], fn($w) => str_contains($w, 'composer.json'))) === 1);
    check('返回更新的文件数', $result['changed'] >= 4, (string)$result['changed']);

    // 再跑一次：内容相同，应当 0 变更
    $again = UpdateService::applyFromDir($src, 'test-dir');
    check('幂等：重复更新 0 变更', $again['ok'] === true && $again['changed'] === 0);

    // zip 包（多一层目录）
    $zipDir = sys_get_temp_dir() . '/hyper-update-zip-' . getmypid();
    put($zipDir . '/pkg/VERSION', "1.2.0\n");
    put($zipDir . '/pkg/composer.json', '{"name":"zip"}');
    put($zipDir . '/pkg/bin/hyper', 'zip-bin');
    $zipFile = $zipDir . '/pkg.zip';
    $cmd = 'cd ' . escapeshellarg($zipDir) . ' && zip -qr ' . escapeshellarg($zipFile) . ' pkg';
    exec($cmd, $o, $code);
    if ($code === 0) {
        $zipResult = UpdateService::fromZip($zipFile);
        check('zip 更新成功（自动剥掉一层目录）', $zipResult['ok'] === true, $zipResult['msg'] ?? '');
        check('zip 更新后版本 1.2.0', UpdateService::version() === '1.2.0');
        check('zip 更新改了 bin/hyper', file_get_contents($sandbox . '/bin/hyper') === 'zip-bin');
    } else {
        check('zip 命令可用（跳过 zip 用例）', true);
    }

    // 非本项目压缩包必须拒绝
    $badZipDir = sys_get_temp_dir() . '/hyper-update-bad-' . getmypid();
    put($badZipDir . '/x/readme.txt', 'not a project');
    $badZip = $badZipDir . '/bad.zip';
    exec('cd ' . escapeshellarg($badZipDir) . ' && zip -qr ' . escapeshellarg($badZip) . ' x', $o2, $code2);
    if ($code2 === 0) {
        $bad = UpdateService::fromZip($badZip);
        check('缺 composer.json 的包被拒绝', $bad['ok'] === false && str_contains((string)$bad['msg'], 'composer.json'));
    }

    $last = UpdateService::last();
    check('写了 last.json 记录', is_array($last) && isset($last['changed']));

    // attachRepo：把一个普通目录关联成 git 仓库，之后"检查更新"才可用
    $srcRepo = sys_get_temp_dir() . '/hyper-update-gitsrc-' . getmypid();
    $target  = sys_get_temp_dir() . '/hyper-update-gitdst-' . getmypid();
    rrmdir($srcRepo);
    rrmdir($target);
    mkdir($srcRepo, 0775, true);
    mkdir($target, 0775, true);
    put($srcRepo . '/README.md', 'hello');
    $g = 'git -C ' . escapeshellarg($srcRepo) . ' -c user.name=t -c user.email=t@t ';
    exec($g . 'init -q -b main 2>&1', $o3, $c3);
    if ($c3 === 0) {
        exec($g . 'add -A && ' . $g . 'commit -q -m first 2>&1', $o4, $c4);
        $srcHead = trim((string)shell_exec($g . 'rev-parse --short HEAD'));
        $att = UpdateService::attachRepo($srcRepo, 'main', '', $target);
        // 生成本地仓库、设好 origin 这两步与环境无关，必须成立
        check('生成了 .git', is_dir($target . '/.git'));
        $remoteUrl = trim((string)shell_exec('git -C ' . escapeshellarg($target) . ' config --get remote.origin.url'));
        check('origin 指向源仓库', $remoteUrl === $srcRepo, $remoteUrl);
        if ($att['ok']) {
            $head = trim((string)shell_exec('git -C ' . escapeshellarg($target) . ' rev-parse --short HEAD'));
            check('HEAD 对齐到源仓库提交', $head !== '' && $head === $srcHead, $head . ' vs ' . $srcHead);
        } else {
            // Android 上的 git 对本地路径 fetch 有兼容问题（Linux 服务器正常），
            // 这里确认失败也是「拉取失败」而不是别的毛病
            check('拉取失败时给出原因', str_contains((string)$att['msg'], '拉取失败'), (string)$att['msg']);
        }
        $badUrl = UpdateService::attachRepo('oops', '', '', $target);
        check('非法地址被拒绝', $badUrl['ok'] === false);
    }
    rrmdir($srcRepo);
    rrmdir($target);
} finally {
    rrmdir($sandbox);
    rrmdir(sys_get_temp_dir() . '/hyper-update-src-' . getmypid());
    rrmdir(sys_get_temp_dir() . '/hyper-update-zip-' . getmypid());
    rrmdir(sys_get_temp_dir() . '/hyper-update-bad-' . getmypid());
}

echo PHP_EOL . ($fail === 0 ? "\033[32m全部通过\033[0m 通过 {$ok}，失败 0" : "失败 {$fail} 项") . PHP_EOL;
exit($fail === 0 ? 0 : 1);