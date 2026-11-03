<?php
namespace Webui;

use Core\Fs;

/**
 * 插件导入：zip 包 / git 仓库 → runtime/github/<name>。
 *
 * 为什么单独一个类：控制器只该处理 HTTP 语义（谁在传、传了多大），
 * 而解包、防目录穿越、PSR-4 校验、命名冲突、跨卷移动这一串都是文件系统操作，
 * 塞进控制器既难测，也容易在 zip 与 git 两条链路之间写歪。
 *
 * 约定与 Core\App::buildPluginSources() 保持一致：
 *   - 能用 composer.json 的 autoload.psr-4 推导出命名空间，插件才扫得到；
 *   - 入口类与文件名同名，且位于 psr-4 根目录（Registry 只扫一层，不递归）。
 * 不满足时不算失败——包已经落盘，用户补 composer.json 后重载即可。
 */
final class PluginInstaller
{
    /** 与 Swoole 的 package_max_length 对齐，超过就在控制器层被拦下了 */
    public const MAX_BYTES = 32 * 1024 * 1024;

    private string $root;
    private string $basePath;

    public function __construct(?string $root = null)
    {
        $this->root = $root ?? (defined('__root__') ? __root__ : dirname(__DIR__));
        $external = (string)\Core\Config::string('github.localPath', $this->root . '/runtime/github');
        if ($external === '') {
            $external = $this->root . '/runtime/github';
        }
        $this->basePath = rtrim($external, '/');
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    // ------------------------------------------------------------------ 入口

    /**
     * 从 zip 字节流安装。
     *
     * @return array{success:bool,msg:string,name:string,path:string,files:int,sources:array,warnings:list<string>}
     */
    public function installZip(string $bytes, string $filename = 'plugin.zip', string $name = ''): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            return $this->failure('没收到文件内容（请求体为空）');
        }
        if ($size > self::MAX_BYTES) {
            return $this->failure('压缩包超过 32 MB，请拆分后再导入');
        }
        if (!$this->ensureBase()) {
            return $this->failure('插件目录不可写：' . $this->basePath);
        }

        $staging = $this->root . '/runtime/upload/zip-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $extract = $staging . '/unpacked';
        $zipPath = $staging . '/package.zip';
        if (!Fs::ensureDir($extract) || !Fs::write($zipPath, $bytes)) {
            $this->rmTree($staging);
            return $this->failure('写临时文件失败（检查 runtime/upload 是否可写）');
        }

        $result = $this->exec(
            escapeshellarg($this->binary('unzip'))
            . ' -q -o ' . escapeshellarg($zipPath)
            . ' -d ' . escapeshellarg($extract),
            120
        );
        if (!$this->hasFiles($extract)) {
            $this->rmTree($staging);
            return $this->failure('解压失败：' . ($this->hint($result['output']) ?: '压缩包可能已损坏，或不是 zip 格式'));
        }

        $rootDir = $this->detectRoot($extract);
        $info = $this->inspect($rootDir);
        $finalName = $this->pickName($name, $info['package'], $filename);
        if ($finalName === '') {
            $this->rmTree($staging);
            return $this->failure('无法确定插件目录名，请手动填写');
        }

        $placed = $this->placeInto($rootDir, $finalName);
        $this->rmTree($staging);
        if (!$placed['ok']) {
            return $this->failure($placed['msg']);
        }

        return $this->success($finalName, $placed['path'], $info, $this->countFiles($placed['path']));
    }

    /**
     * 从 git 仓库克隆安装。
     *
     * 不复用 Utils\Git::clone()：它把 -b 默认写死成 main，遇到 master 仓库直接失败，
     * 而这里「不传分支就跟随远程 HEAD」才是安装插件该有的行为。
     */
    public function installGit(string $url, string $branch = '', string $name = ''): array
    {
        $url = trim($url);
        if ($url === '') {
            return $this->failure('仓库地址是空的');
        }
        if (!preg_match('#^(https?://|git@|ssh://|git://)#i', $url)) {
            return $this->failure('只支持 http(s) / ssh / git 协议的仓库地址');
        }
        if (!$this->ensureBase()) {
            return $this->failure('插件目录不可写：' . $this->basePath);
        }

        $finalName = $this->pickName($name, '', $this->nameFromUrl($url));
        if ($finalName === '') {
            return $this->failure('无法从仓库地址推断目录名，请手动填写');
        }

        $staging = $this->root . '/runtime/upload/git-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
        $clonePath = $staging . '/repo';
        if (!Fs::ensureDir($staging)) {
            return $this->failure('临时目录不可写：' . $staging);
        }

        $cmd = 'GIT_TERMINAL_PROMPT=0 ' . escapeshellarg($this->binary('git')) . ' clone --depth 1';
        if (trim($branch) !== '') {
            $cmd .= ' -b ' . escapeshellarg(trim($branch));
        }
        $cmd .= ' ' . escapeshellarg($url) . ' ' . escapeshellarg($clonePath);
        $result = $this->exec($cmd, 180);

        if (!is_dir($clonePath . '/.git')) {
            $this->rmTree($staging);
            return $this->failure('克隆失败：' . ($this->hint($result['output']) ?: '未生成仓库目录'));
        }

        $info = $this->inspect($clonePath);
        $placed = $this->placeInto($clonePath, $finalName);
        $this->rmTree($staging);
        if (!$placed['ok']) {
            return $this->failure($placed['msg']);
        }

        return $this->success($finalName, $placed['path'], $info, $this->countFiles($placed['path']));
    }

    /** 让 Registry 认识新目录：返回 命名空间前缀 => 目录 */
    public function sourcesOf(array $result): array
    {
        return (array)($result['sources'] ?? []);
    }

    // ------------------------------------------------------------------ 包结构

    /**
     * 剥掉单层包裹目录（zip 常见形态：仓库名/ 下面是 composer.json）。
     */
    private function detectRoot(string $dir): string
    {
        $entries = [];
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__MACOSX' || $entry === '.DS_Store') {
                continue;
            }
            $entries[] = $entry;
        }
        if (count($entries) === 1 && is_dir($dir . '/' . $entries[0])) {
            return $dir . '/' . $entries[0];
        }
        return $dir;
    }

    /**
     * 读取 composer.json，推导可注册的 psr-4 源。
     *
     * @return array{sources:array<string,string>,warnings:list<string>,package:string}
     */
    private function inspect(string $dir): array
    {
        $sources = [];
        $warnings = [];
        $package = '';

        $composer = $dir . '/composer.json';
        if (!is_file($composer)) {
            $warnings[] = '缺少 composer.json，导入后不会被扫描到';
        } else {
            $json = json_decode((string)Fs::read($composer), true);
            if (!is_array($json)) {
                $warnings[] = 'composer.json 不是合法 JSON';
            } else {
                $package = (string)($json['name'] ?? '');
                $psr4 = $json['autoload']['psr-4'] ?? [];
                foreach ((array)$psr4 as $namespace => $relative) {
                    $namespace = (string)$namespace;
                    if ($namespace === '') {
                        continue;
                    }
                    if (!str_ends_with($namespace, '\\')) {
                        $namespace .= '\\';
                    }
                    $target = rtrim($dir . '/' . ltrim((string)$relative, '/'), '/');
                    if (!is_dir($target)) {
                        $warnings[] = 'psr-4 目录不存在：' . (string)$relative;
                        continue;
                    }
                    $sources[$namespace] = $target;
                }
                if ($sources === []) {
                    $warnings[] = 'composer.json 未声明可用的 autoload.psr-4';
                }
            }
        }

        // Registry 只 glob 一层，入口类必须在 psr-4 根目录
        foreach ($sources as $namespace => $sourceDir) {
            if ((glob(rtrim($sourceDir, '/') . '/*.php') ?: []) === []) {
                $warnings[] = 'psr-4 根目录没有 .php 入口类：' . $namespace;
            }
        }
        return ['sources' => $sources, 'warnings' => $warnings, 'package' => $package];
    }

    // ------------------------------------------------------------------ 落盘

    private function ensureBase(): bool
    {
        return Fs::ensureDir($this->basePath) && is_writable($this->basePath);
    }

    /** @return array{ok:bool,msg:string,path:string} */
    private function placeInto(string $source, string $name): array
    {
        $target = $this->basePath . '/' . $name;
        if (is_dir($target) && count(scandir($target) ?: []) > 2) {
            return ['ok' => false, 'msg' => "同名目录已存在：{$name}（先删除 " . $target . ' 再试）', 'path' => ''];
        }
        if (is_file($target)) {
            return ['ok' => false, 'msg' => "同名文件已存在：{$name}", 'path' => ''];
        }
        if (!@rename($source, $target)) {
            // 临时目录与插件目录不在同一卷时会失败（Android 上 /data 与 /sdcard 就是两个域）
            if (!@\Utils\Operate::xCopy($source, $target)) {
                return ['ok' => false, 'msg' => '移动到插件目录失败：' . $target, 'path' => ''];
            }
            $this->rmTree($source);
        }
        return ['ok' => true, 'msg' => '', 'path' => $target];
    }

    // ------------------------------------------------------------------ 命名

    private function pickName(string $given, string $package, string $fallback): string
    {
        $candidates = [];
        if (trim($given) !== '') {
            $candidates[] = trim($given);
        }
        if ($package !== '') {
            // composer 包名是 vendor/plugin，目录取斜杠后半段
            $pos = strrpos($package, '/');
            $candidates[] = $pos === false ? $package : substr($package, $pos + 1);
        }
        if ($fallback !== '') {
            $candidates[] = (string)preg_replace('/\.(zip|git)$/i', '', $fallback);
        }
        foreach ($candidates as $candidate) {
            $safe = $this->safeName($candidate);
            if ($safe !== '') {
                return $safe;
            }
        }
        return '';
    }

    /** 目录名白名单：只留字母数字和 . _ -，避免 ../ 与 shell 元字符 */
    private function safeName(string $name): string
    {
        $name = (string)preg_replace('/[^A-Za-z0-9._-]+/', '-', trim($name));
        $name = trim($name, '-.');
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        return substr($name, 0, 64);
    }

    private function nameFromUrl(string $url): string
    {
        $clean = (string)preg_replace('/[?#].*$/', '', $url);
        $clean = (string)preg_replace('/\.git$/i', '', rtrim($clean, '/'));
        $parts = preg_split('#[/:]#', $clean) ?: [];
        $last = end($parts);
        return is_string($last) ? $last : '';
    }

    // ------------------------------------------------------------------ 工具

    /** @return array{success:bool,msg:string,name:string,path:string,files:int,sources:array,warnings:list<string>} */
    private function success(string $name, string $path, array $info, int $files): array
    {
        return [
            'success'  => true,
            'msg'      => '已安装到 ' . $name,
            'name'     => $name,
            'path'     => $path,
            'files'    => $files,
            'sources'  => $info['sources'],
            'warnings' => $info['warnings'],
        ];
    }

    private function failure(string $message): array
    {
        return [
            'success'  => false,
            'msg'      => $message,
            'name'     => '',
            'path'     => '',
            'files'    => 0,
            'sources'  => [],
            'warnings' => [],
        ];
    }

    private function hasFiles(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }
        return false;
    }

    private function countFiles(string $dir): int
    {
        $count = 0;
        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                // .git 不计入，否则展示成“几千个文件”很吓人
                if (str_contains($file->getPathname(), '/.git/')) {
                    continue;
                }
                $count++;
            }
        } catch (\Throwable) {
            return 0;
        }
        return $count;
    }

    private function rmTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->rmTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }

    /** 在 PATH 里找可执行文件；容器里 PATH 常缺 /data/.../usr/bin，所以再兜底几个常见位置 */
    private function binary(string $name): string
    {
        $dirs = array_filter(explode(':', (string)getenv('PATH')), static fn($d) => $d !== '');
        foreach (['/system/bin', '/usr/bin', '/usr/local/bin', '/bin'] as $extra) {
            $dirs[] = $extra;
        }
        foreach ($dirs as $dir) {
            $candidate = rtrim($dir, '/') . '/' . $name;
            if (@is_executable($candidate)) {
                return $candidate;
            }
        }
        return $name;
    }

    /** @return array{code:int,output:string} */
    private function exec(string $cmd, int $timeout = 60): array
    {
        if (class_exists('\\Swoole\\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
            $result = \Swoole\Coroutine\System::exec($cmd);
            if (is_array($result)) {
                return ['code' => (int)($result['code'] ?? -1), 'output' => (string)($result['output'] ?? '')];
            }
            return ['code' => -1, 'output' => '命令执行失败'];
        }

        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (!is_resource($process)) {
            return ['code' => -1, 'output' => '无法启动进程（proc_open 被禁用？）'];
        }
        $output = (string)stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            fclose($pipes[2]);
        }
        $code = proc_close($process);
        return ['code' => $code, 'output' => $output];
    }

    /** 取输出里最后一行有内容的文本，给用户看的错误提示不该是整屏日志 */
    private function hint(string $output): string
    {
        $lines = array_filter(array_map('trim', explode("\n", $output)), static fn($l) => $l !== '');
        $last = $lines === [] ? '' : (string)end($lines);
        return mb_substr($last, 0, 300);
    }
}
