<?php
namespace Core\Update;

/**
 * 程序更新：从 git 仓库或 zip 包覆盖项目文件。
 *
 * 设计取舍：
 *  - 不做「差量补丁」，直接以发布包/仓库内容覆盖；被覆盖的旧文件先备份到
 *    runtime/update/backup-<时间>/，出问题可以手工拷回。
 *  - 只覆盖程序文件，永远不碰 runtime/、vendor/、.git/、Config.ini —— 这些是
 *    用户数据与依赖，更新不该重置它们。
 *  - composer.json 变了不会自动装依赖（线上环境可能没有 composer），只给提示，
 *    由人决定什么时候跑 php bin/hyper install --force。
 */
final class UpdateService
{
    /** 这些前缀/文件永远不覆盖 */
    private const PROTECTED_DIRS = ['runtime/', 'vendor/', '.git/', 'node_modules/', 'ui/node_modules/'];
    private const PROTECTED_FILES = ['Config.ini', '.env'];

    private static function root(): string
    {
        return defined('__root__') ? __root__ : dirname(__DIR__, 2);
    }

    public static function path(string $name = ''): string
    {
        $dir = self::root() . '/runtime/update';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $name === '' ? $dir : $dir . '/' . ltrim($name, '/');
    }

    public static function version(): string
    {
        $file = self::root() . '/VERSION';
        return is_file($file) ? trim((string)@file_get_contents($file)) : 'unknown';
    }

    /** git 是否可用 + 当前仓库信息 */
    public static function gitInfo(): array
    {
        $available = self::git('--version') !== null;
        $repo = is_dir(self::root() . '/.git');
        $info = [
            'available' => $available,
            'repo'      => $repo,
            'remote'    => '',
            'branch'    => '',
            'commit'    => '',
            'subject'   => '',
        ];
        if (!$available || !$repo) {
            return $info;
        }
        $info['remote'] = trim((string)self::git('config --get remote.origin.url'));
        $info['branch'] = trim((string)self::git('rev-parse --abbrev-ref HEAD'));
        $info['commit'] = trim((string)self::git('rev-parse --short HEAD'));
        $info['subject'] = trim((string)self::git('log -1 --pretty=%s'));
        return $info;
    }

    /** 检查远端是否有更新（需要 .git 与 remote） */
    public static function check(): array
    {
        $info = self::gitInfo();
        if (!$info['available']) {
            return ['ok' => false, 'msg' => '没有 git 命令，无法检查更新；可以改用压缩包更新'];
        }
        if (!$info['repo']) {
            return ['ok' => false, 'msg' => '当前目录不是 git 仓库（没有 .git），无法检查更新；可以改用压缩包更新'];
        }
        if ($info['remote'] === '') {
            return ['ok' => false, 'msg' => '没有配置远端（git remote add origin <url>），无法检查更新'];
        }
        $fetch = self::run('fetch --quiet --prune origin', 60);
        if ($fetch['code'] !== 0) {
            return ['ok' => false, 'msg' => '拉取远端失败：' . self::shortError($fetch)];
        }
        $behind = trim((string)self::git('rev-list --count HEAD..@{u}'));
        $ahead = trim((string)self::git('rev-list --count @{u}..HEAD'));
        $latest = trim((string)self::git('log -1 --pretty=%h %s @{u}'));
        return [
            'ok'      => true,
            'behind'  => (int)$behind,
            'ahead'   => (int)$ahead,
            'latest'  => $latest,
            'current' => $info['commit'] . ' ' . $info['subject'],
            'msg'     => (int)$behind > 0 ? '有 ' . (int)$behind . ' 个新提交' : '已是最新',
        ];
    }

    /**
     * 从 git 仓库更新。
     *
     * @param string $url    仓库地址（可带代理前缀）
     * @param string $branch 分支，留空用远端默认
     * @param string $proxy  加速前缀，例如 https://gh-proxy.com/ （留空不用）
     */
    /**
     * 把当前目录关联成 git 仓库（不覆盖文件）：init + remote + fetch + reset --mixed。
     *
     * 场景：用压缩包部署、或者之前用「git 链接更新」但目录还不是仓库，
     * 关联之后「检查更新」才可用，后续也能直接 git pull。
     *
     * @param string|null $root 测试用；默认项目根目录
     */
    public static function attachRepo(string $url, string $branch = '', string $proxy = '', ?string $root = null): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'msg' => '请填写仓库地址'];
        }
        if (!preg_match('#^(https?://|git@|/|file://)#i', $url)) {
            return ['ok' => false, 'msg' => '仓库地址必须以 http(s)://、git@ 或 / 开头'];
        }
        if ($proxy !== '' && !preg_match('#^https?://#i', $proxy)) {
            return ['ok' => false, 'msg' => '加速前缀必须是 http(s):// 开头'];
        }
        if (self::git('--version') === null) {
            return ['ok' => false, 'msg' => '没有 git 命令，无法关联仓库'];
        }
        $root = $root ?? dirname(__DIR__, 2);
        $cloneUrl = $proxy !== '' && str_starts_with($url, 'http') ? rtrim($proxy, '/') . '/' . $url : $url;

        if (is_dir($root . '/.git')) {
            // 已经是仓库就别动它的 origin：曾经有测试/误操作把线上仓库的远端改掉
            $head = trim(self::run('rev-parse --short HEAD', 30, $root)['out'] ?? '');
            if ($head !== '') {
                return [
                    'ok'  => false,
                    'msg' => '当前目录已经是 git 仓库（HEAD ' . $head . '），无需关联；如需更换远端请用 git remote set-url origin <地址>',
                ];
            }
        } else {
            $init = self::run('init -q', 60, $root);
            if ($init['code'] !== 0) {
                return ['ok' => false, 'msg' => 'git init 失败：' . self::shortError($init)];
            }
        }
        self::run('remote remove origin', 30, $root);
        $add = self::run('remote add origin ' . escapeshellarg($url), 30, $root);
        if ($add['code'] !== 0) {
            self::run('remote set-url origin ' . escapeshellarg($url), 30, $root);
        }
        $fetch = self::run('fetch --depth 1 ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($branch !== '' ? $branch : 'HEAD'), 180, $root);
        if ($fetch['code'] !== 0) {
            // 显式 ref 拿不到时退回默认分支
            $fetch = self::run('fetch --depth 1 ' . escapeshellarg($cloneUrl), 180, $root);
        }
        if ($fetch['code'] !== 0) {
            return ['ok' => false, 'msg' => '拉取失败：' . self::shortError($fetch)];
        }
        // --mixed：只把索引/HEAD 对齐到远端，不碰工作区文件（runtime/、vendor/ 都不会动）
        $reset = self::run('reset --mixed FETCH_HEAD', 60, $root);
        if ($reset['code'] !== 0) {
            return ['ok' => false, 'msg' => '对齐失败：' . self::shortError($reset)];
        }
        self::run('branch -M ' . escapeshellarg($branch !== '' ? $branch : 'main'), 30, $root);
        self::run('config user.name hyper-bot', 30, $root);
        self::run('config user.email hyper-bot@localhost', 30, $root);
        $head = trim(self::run('rev-parse --short HEAD', 30, $root)['out'] ?? '');
        return [
            'ok' => true,
            'msg' => '已关联仓库 ' . $url . '（HEAD ' . $head . '），现在可以用「检查更新」',
            'commit' => $head,
            'branch' => $branch !== '' ? $branch : 'main',
        ];
    }

    public static function fromGit(string $url, string $branch = '', string $proxy = '', bool $attach = true): array
    {
        $url = trim($url);
        if ($url === '') {
            return ['ok' => false, 'msg' => '请填写仓库地址'];
        }
        if (!preg_match('#^(https?://|git@|/|file://)#i', $url)) {
            return ['ok' => false, 'msg' => '仓库地址必须以 http(s):// 或 git@ 开头'];
        }
        if ($proxy !== '' && !preg_match('#^https?://#i', $proxy)) {
            return ['ok' => false, 'msg' => '加速前缀必须是 http(s):// 开头'];
        }
        $cloneUrl = $proxy !== '' && str_starts_with($url, 'http') ? rtrim($proxy, '/') . '/' . $url : $url;

        $tmp = self::path('tmp-' . date('YmdHis'));
        @mkdir($tmp, 0775, true);
        $cmd = 'clone --depth 1' . ($branch !== '' ? ' --branch ' . escapeshellarg($branch) : '')
            . ' ' . escapeshellarg($cloneUrl) . ' ' . escapeshellarg($tmp . '/src');
        $result = self::run($cmd, 180);
        if ($result['code'] !== 0) {
            self::rrmdir($tmp);
            return ['ok' => false, 'msg' => '克隆失败：' . self::shortError($result)];
        }
        $applied = self::applyFromDir($tmp . '/src', 'git:' . $url);
        self::rrmdir($tmp);
        if ($applied['ok'] && $attach) {
            // 顺手把这个目录变成仓库，否则「检查更新」永远显示不可用
            $linked = self::attachRepo($url, $branch, '');
            $applied['attached'] = $linked['ok'];
            $applied['msg'] .= $linked['ok']
                ? '；' . $linked['msg']
                : '；但没能关联成 git 仓库：' . $linked['msg'];
        }
        return $applied;
    }

    /** 从上传的 zip 更新 */
    public static function fromZip(string $zipFile): array
    {
        if (!is_file($zipFile)) {
            return ['ok' => false, 'msg' => '没有收到压缩包'];
        }
        $tmp = self::path('tmp-' . date('YmdHis'));
        @mkdir($tmp, 0775, true);

        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($zipFile) !== true) {
                self::rrmdir($tmp);
                return ['ok' => false, 'msg' => '压缩包打不开（不是有效的 zip）'];
            }
            $zip->extractTo($tmp);
            $zip->close();
        } else {
            $result = self::run('unzip -q ' . escapeshellarg($zipFile) . ' -d ' . escapeshellarg($tmp), 120);
            if ($result['code'] !== 0) {
                self::rrmdir($tmp);
                return ['ok' => false, 'msg' => '解压失败：' . self::shortError($result)];
            }
        }

        $src = self::detectRoot($tmp);
        if ($src === null) {
            self::rrmdir($tmp);
            return ['ok' => false, 'msg' => '这个压缩包里没有找到 composer.json，不像是本项目的发布包'];
        }
        $applied = self::applyFromDir($src, 'zip:' . basename($zipFile));
        self::rrmdir($tmp);
        return $applied;
    }

    /** 解压后常常多一层目录，找真正含 composer.json 的那层 */
    private static function detectRoot(string $dir): ?string
    {
        if (is_file($dir . '/composer.json')) {
            return $dir;
        }
        $items = array_values(array_filter(scandir($dir) ?: [], static fn(string $n): bool => $n !== '.' && $n !== '..'));
        if (count($items) === 1 && is_dir($dir . '/' . $items[0]) && is_file($dir . '/' . $items[0] . '/composer.json')) {
            return $dir . '/' . $items[0];
        }
        return null;
    }

    /** 把 $src 的内容覆盖到项目根，返回执行结果 */
    public static function applyFromDir(string $src, string $source = 'dir'): array
    {
        $root = self::root();
        $backup = self::path('backup-' . date('YmdHis'));
        $changed = 0;
        $skipped = 0;
        $warnings = [];
        $composerChanged = false;
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            $rel = str_replace('\\', '/', substr($item->getPathname(), strlen($src) + 1));
            if ($rel === '' || self::isProtected($rel)) {
                $skipped++;
                continue;
            }
            if ($item->isDir()) {
                if (!is_dir($root . '/' . $rel)) {
                    @mkdir($root . '/' . $rel, 0775, true);
                }
                continue;
            }
            $target = $root . '/' . $rel;
            if (is_file($target) && md5_file($target) === md5_file($item->getPathname())) {
                continue;
            }
            // 覆盖前备份旧文件
            if (is_file($target)) {
                $bakFile = $backup . '/' . $rel;
                @mkdir(dirname($bakFile), 0775, true);
                @copy($target, $bakFile);
            }
            @mkdir(dirname($target), 0775, true);
            if (!@copy($item->getPathname(), $target)) {
                $warnings[] = '写入失败：' . $rel;
                continue;
            }
            $changed++;
            $files[] = $rel;
            if ($rel === 'composer.json') {
                $composerChanged = true;
            }
        }

        if ($composerChanged) {
            $warnings[] = 'composer.json 已更新：依赖可能有变化，建议执行 php bin/hyper install --force';
        }
        if (in_array('public/webui/index.html', $files, true)) {
            $warnings[] = '前端已更新：浏览器请强制刷新（Ctrl+Shift+R），避免旧 index.html 引用已删除的 chunk';
        }
        if ($changed === 0) {
            @rmdir($backup);
            return ['ok' => true, 'changed' => 0, 'skipped' => $skipped, 'backup' => '', 'warnings' => [], 'version' => self::version(), 'msg' => '已经是最新，没有文件需要更新'];
        }

        $record = [
            'at'       => date('c'),
            'source'   => $source,
            'changed'  => $changed,
            'backup'   => is_dir($backup) ? $backup : '',
            'version'  => self::version(),
            'files'    => array_slice($files, 0, 200),
        ];
        \Core\Fs::write(self::path('last.json'), (string)json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return [
            'ok'       => true,
            'changed'  => $changed,
            'skipped'  => $skipped,
            'backup'   => $record['backup'],
            'warnings' => $warnings,
            'version'  => self::version(),
            'msg'      => '已更新 ' . $changed . ' 个文件，版本 ' . self::version(),
        ];
    }

    public static function last(): ?array
    {
        $file = self::path('last.json');
        if (!is_file($file)) {
            return null;
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function isProtected(string $rel): bool
    {
        if (in_array($rel, self::PROTECTED_FILES, true)) {
            return true;
        }
        foreach (self::PROTECTED_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return true;
            }
        }
        return false;
    }

    /** 跑一条 git 只读命令（失败返回 null） */
    private static function git(string $args): ?string
    {
        $result = self::run($args, 20);
        return $result['code'] === 0 ? $result['out'] : null;
    }

    /** @return array{code:int, out:string, err:string} */
    private static function run(string $args, int $timeout = 60, ?string $cwd = null): array
    {
        $cmd = 'git ' . $args . ' 2>&1';
        $dir = $cwd ?? self::root();
        if (function_exists('proc_open')) {
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = @proc_open($cmd, $descriptor, $pipes, $dir);
            if (is_resource($proc)) {
                $out = (string)stream_get_contents($pipes[1]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                $code = proc_close($proc);
                // 个别环境（如 Android 上的 PHP）proc_open 找不到 PATH 里的 git，退出码 127，
                // 这时退回 exec 再试一次，两者都失败才算没有 git。
                if ($code !== 127 || trim($out) !== '') {
                    return ['code' => $code, 'out' => $out, 'err' => ''];
                }
            }
        }
        // exec 没有 cwd 参数，用 cd 前缀补上（proc_open 在个别环境不可用时走这里）
        $out = [];
        $code = 1;
        $execCmd = $cwd !== null ? 'cd ' . escapeshellarg($cwd) . ' && ' . $cmd : $cmd;
        @exec($execCmd, $out, $code);
        return ['code' => $code, 'out' => implode("\n", $out), 'err' => ''];
    }

    private static function shortError(array $result): string
    {
        $text = trim((string)preg_replace('/\s+/', ' ', $result['out'] ?? ''));
        if ($text === '') {
            $text = '退出码 ' . (int)($result['code'] ?? -1);
        }
        return mb_substr($text, 0, 240);
    }

    private static function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}