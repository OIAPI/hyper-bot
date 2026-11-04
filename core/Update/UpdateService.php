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

        // 服务器连不上 GitHub 时，「检查更新」的 git fetch 也得走代理：
        // 把代理记进仓库的 url.<代理前缀>.insteadOf，origin 仍保持官方地址。
        if ($proxy !== '' && str_starts_with($url, 'http')) {
            $prefix = (string)preg_replace('#^(https?://[^/]+/).*#', '$1', $url);
            if ($prefix !== '' && $prefix !== $url) {
                $mapped = rtrim($proxy, '/') . '/' . $prefix;
                self::run('config ' . escapeshellarg('url.' . $mapped . '.insteadOf') . ' ' . escapeshellarg($prefix), 30, $root);
            }
        }

        $branchName = $branch !== '' ? $branch : 'main';
        // 显式 URL fetch 不会生成 remote-tracking ref；补上它，@{u} 才有意义
        $fetched = trim((string)(self::run('rev-parse FETCH_HEAD', 30, $root)['out'] ?? ''));
        if ($fetched !== '') {
            self::run('update-ref ' . escapeshellarg('refs/remotes/origin/' . $branchName) . ' ' . escapeshellarg($fetched), 30, $root);
        }
        self::run('branch -M ' . escapeshellarg($branchName), 30, $root);
        // 之前少了这两个配置：check() 里的 HEAD..@{u} 会报 no upstream，面板就永远显示「已是最新」
        self::run('config ' . escapeshellarg('branch.' . $branchName . '.remote') . ' origin', 30, $root);
        self::run('config ' . escapeshellarg('branch.' . $branchName . '.merge') . ' ' . escapeshellarg('refs/heads/' . $branchName), 30, $root);
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
            $linked = self::attachRepo($url, $branch, $proxy);
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

    // ------------------------------------------------------------------
    // 异步更新任务（v3.2）
    //
    // 为什么必须异步：更新一个 3MB 的发布包要解压上万个文件、再逐个覆盖，
    // 在 ARM 手机上能跑几十秒。同步做的话反代（nginx proxy_read_timeout 默认 60s）
    // 会先断掉连接，浏览器只看到 502/403；php -S 又是单线程，解压期间整个面板的
    // 其它请求都会卡住。所以「接收包」和「应用包」拆开：请求只负责把包放好，
    // 真正的解压替换交给后台进程（php bin/hyper update:apply），前端轮询任务状态。
    // ------------------------------------------------------------------

    public static function jobFile(): string
    {
        return self::path('job.json');
    }

    /** 当前/上一次更新任务（附带日志尾部，前端直接渲染） */
    public static function job(): ?array
    {
        $file = self::jobFile();
        if (!is_file($file)) {
            return null;
        }
        $job = json_decode((string)@file_get_contents($file), true);
        if (!is_array($job)) {
            return null;
        }
        $job['age'] = time() - (int)($job['updated_at'] ?? 0);
        $running = in_array($job['status'] ?? '', ['queued', 'running'], true);
        // 进程被杀（手机省电、OOM）时状态会永远停在 running，超过 15 分钟就标 stale
        $job['stale'] = $running && $job['age'] > 900;
        if ($job['stale']) {
            $job['status'] = 'stale';
        }
        $log = (string)($job['log_file'] ?? '');
        $lines = [];
        if ($log !== '' && is_file($log)) {
            $lines = @file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        }
        $job['log'] = array_slice($lines, -40);
        return $job;
    }

    /** 往任务日志追加一行：面板的「日志」区域直接读这个文件 */
    private static function jobLog(array $job, string $line): void
    {
        $file = (string)($job['log_file'] ?? '');
        if ($file === '') {
            return;
        }
        // 用「任务开始后第几秒」而不是本地时间：CLI 与 php-fpm 的时区未必一致，
        // 两者混着写会让人误判先后顺序。
        $elapsed = max(0, time() - (int)($job['started_at'] ?? time()));
        @file_put_contents($file, '[+' . $elapsed . 's] ' . $line . PHP_EOL, FILE_APPEND);
    }

    private static function writeJob(array $job): void
    {
        $job['updated_at'] = time();
        @file_put_contents(
            self::jobFile(),
            json_encode($job, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public static function jobRunning(): bool
    {
        $job = self::job();
        return $job !== null && in_array($job['status'] ?? '', ['queued', 'running'], true);
    }

    /**
     * 后台开一个更新任务，立即返回。
     * @return array{ok:bool,msg:string,job:?array}
     */
    public static function startJob(string $zipFile, string $source = 'zip'): array
    {
        if (self::jobRunning()) {
            return ['ok' => false, 'msg' => '已经有一个更新任务在跑，等它结束再试', 'job' => self::job()];
        }
        if (!is_file($zipFile)) {
            return ['ok' => false, 'msg' => '压缩包不存在（上传可能没落盘）', 'job' => null];
        }
        $size = (int)@filesize($zipFile);
        if ($size <= 0) {
            return ['ok' => false, 'msg' => '压缩包是空的', 'job' => null];
        }
        $id = date('YmdHis') . '-' . bin2hex(random_bytes(3));
        $log = self::path('job-' . $id . '.log');
        @file_put_contents($log, '[update] ' . date('Y-m-d H:i:s') . " 收到 " . $source . "：" . basename($zipFile) . PHP_EOL);
        $job = [
            'id'         => $id,
            'status'     => 'queued',
            'source'     => $source,
            'file'       => basename($zipFile),
            'size'       => $size,
            'started_at' => time(),
            'updated_at' => time(),
            'ended_at'   => null,
            'msg'        => '已排队，后台开始解压',
            'result'     => null,
            'log_file'   => $log,
        ];
        self::writeJob($job);

        // 后台执行：用 shell 把它放到后台并立刻返回，父进程（PHP-FPM/php -S）不等它
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::root() . '/bin/hyper')
            . ' update:apply ' . escapeshellarg($zipFile)
            . ' --id=' . escapeshellarg($id)
            . ' --source=' . escapeshellarg($source);
        @exec('cd ' . escapeshellarg(self::root()) . ' && ' . $cmd . ' >>' . escapeshellarg($log) . ' 2>&1 &');

        return ['ok' => true, 'msg' => '更新已在后台开始，页面会自动刷新状态', 'job' => self::job()];
    }

    /** CLI 入口：真正执行更新（bin/hyper update:apply） */
    public static function runJob(string $zipFile, string $id = '', string $source = 'zip'): int
    {
        $job = null;
        $current = self::job();
        if ($current !== null && ($id === '' || ($current['id'] ?? '') === $id)) {
            $job = $current;
        }
        if ($job === null) {
            $job = [
                'id'       => $id !== '' ? $id : date('YmdHis'),
                'source'   => $source,
                'file'     => basename($zipFile),
                'size'     => is_file($zipFile) ? (int)@filesize($zipFile) : 0,
                'log_file' => self::path('job-' . ($id !== '' ? $id : date('YmdHis')) . '.log'),
            ];
        }
        $job['status'] = 'running';
        $job['msg'] = '正在解压并覆盖文件…';
        $job['ended_at'] = null;
        self::writeJob($job);
        self::jobLog($job, '开始解压 ' . basename($zipFile) . '（' . self::humanSize((int)@filesize($zipFile)) . '）');

        $result = self::fromZip($zipFile);

        $job['status'] = !empty($result['ok']) ? 'done' : 'failed';
        $job['msg'] = (string)($result['msg'] ?? '');
        $job['result'] = $result;
        $job['ended_at'] = time();
        self::writeJob($job);
        self::jobLog($job, (!empty($result['ok']) ? '完成' : '失败') . '：' . (string)($result['msg'] ?? '')
            . '（更新 ' . (int)($result['changed'] ?? 0) . ' 个，跳过 ' . (int)($result['skipped'] ?? 0) . ' 个'
            . (!empty($result['backup']) ? '，备份 ' . basename((string)$result['backup']) : '') . '）');
        foreach ((array)($result['warnings'] ?? []) as $warning) {
            self::jobLog($job, '警告：' . (string)$warning);
        }

        // 包已经应用完，原文件没用了（备份在 runtime/update/backup-*）
        @unlink($zipFile);

        return !empty($result['ok']) ? 0 : 1;
    }

    /**
     * 从链接下载 zip（「服务器自己下载」的更新方式）。
     * 走服务器出口而不是浏览器上传，可以绕开反代对请求体大小的限制。
     * @return array{ok:bool,msg:string,file?:string}
     */
    public static function downloadZip(string $url, string $proxy = ''): array
    {
        $url = trim($url);
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'msg' => '请填写 http(s) 链接'];
        }
        // GitHub 仓库页 → 归档包地址，省得用户自己找 zip
        if (preg_match('#^https://github\.com/([^/]+)/([^/?#]+)/?$#i', $url, $m)) {
            $url = 'https://github.com/' . $m[1] . '/' . $m[2] . '/archive/HEAD.zip';
        }
        $target = $proxy !== '' ? rtrim($proxy, '/') . '/' . ltrim($url, '/') : $url;
        $file = self::path('download-' . date('YmdHis') . '.zip');
        $ok = false;
        $err = '';

        if (function_exists('curl_init')) {
            $fp = @fopen($file, 'wb');
            if ($fp === false) {
                return ['ok' => false, 'msg' => 'runtime/update 不可写'];
            }
            $ch = curl_init($target);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fp,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 5,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT        => 300,
                CURLOPT_USERAGENT      => 'hyper-bot-updater',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            $ok = curl_exec($ch);
            $err = (string)curl_error($ch);
            curl_close($ch);
            fclose($fp);
        } else {
            $ctx = stream_context_create(['http' => [
                'timeout'         => 300,
                'follow_location' => 1,
                'user_agent'      => 'hyper-bot-updater',
            ]]);
            $data = @file_get_contents($target, false, $ctx);
            if ($data !== false && $data !== '') {
                $ok = (bool)@file_put_contents($file, $data);
            } else {
                $err = '下载失败（file_get_contents 返回空）';
            }
        }

        if (!$ok) {
            @unlink($file);
            return ['ok' => false, 'msg' => '下载失败：' . ($err !== '' ? $err : '网络错误或链接不可达')];
        }
        $head = (string)@file_get_contents($file, false, null, 0, 2);
        if ($head !== 'PK') {
            $snippet = mb_substr((string)@file_get_contents($file, false, null, 0, 200), 0, 120);
            @unlink($file);
            return ['ok' => false, 'msg' => '下载到的不是 zip（可能被代理返回了网页）：' . $snippet];
        }
        return ['ok' => true, 'msg' => '已下载 ' . self::humanSize((int)@filesize($file)), 'file' => $file];
    }

    private static function humanSize(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
