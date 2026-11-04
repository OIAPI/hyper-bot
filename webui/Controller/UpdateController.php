<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Update\UpdateService;

/**
 * 程序更新：git / 压缩包。
 *
 * 为什么只有主人能用：更新会覆盖整个项目（包括写接口与鉴权代码），
 * 管理员级别不该有这种能力。
 */
final class UpdateController extends Base
{
    private const MAX_BYTES = 64 * 1024 * 1024;

    /** 分片大小默认 512 KB：低于 nginx 默认 client_max_body_size 1m，单请求一定过得去。 */
    private const CHUNK = 512 * 1024;

    /**
     * 允许用 Config.ini 调小分片：有些面板前面挂着限制更严的网关（比如 client_max_body_size 100k），
     * 512KB 也会被 413 挡掉。范围 64KB–4MB，防止配出离谱的值。
     */
    private function chunkSize(): int
    {
        $size = \Core\Config::int('webui.update_chunk', self::CHUNK);
        return max(64 * 1024, min(4 * 1024 * 1024, $size));
    }

    public function index(Request $request): Response
    {
        return $this->ok([
            'version' => UpdateService::version(),
            'git'     => UpdateService::gitInfo(),
            'last'    => UpdateService::last(),
            'path'    => UpdateService::path(),
            'limits'  => [
                'zip_mb' => 64,
                'keep'   => ['runtime/', 'vendor/', '.git/', 'Config.ini'],
            ],
            'proxies' => [
                ['label' => '直连', 'prefix' => ''],
                ['label' => 'gh-proxy.com', 'prefix' => 'https://gh-proxy.com/'],
                ['label' => 'ghproxy.net', 'prefix' => 'https://ghproxy.net/'],
            ],
        ]);
    }

    public function check(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $result = UpdateService::check();
        return $result['ok'] ? $this->ok($result, (string)$result['msg']) : $this->fail((string)$result['msg'], -1, 400);
    }

    /** 从 git 仓库更新（可带加速前缀） */
    public function fromGit(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $result = UpdateService::fromGit(
            (string)$request->input('url', ''),
            trim((string)$request->input('branch', '')),
            trim((string)$request->input('proxy', ''))
        );
        return $result['ok'] ? $this->ok($result, (string)$result['msg']) : $this->fail((string)$result['msg'], -1, 400);
    }

    /** 上传 zip 更新（整包当 raw body 走，和插件导入一致） */

    /** 把当前目录关联成 git 仓库（只写 .git，不覆盖文件） */
    public function attach(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $result = UpdateService::attachRepo(
            (string)$request->input('url', ''),
            (string)$request->input('branch', ''),
            (string)$request->input('proxy', '')
        );
        return $result['ok']
            ? $this->ok($result, $result['msg'])
            : $this->fail($result['msg'], -1, 400);
    }

    public function fromZip(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        [$bytes, $filename, $error] = $this->zipPayload($request);
        if ($error !== '') {
            return $this->fail($error, -1, 400);
        }
        $file = UpdateService::path('upload-' . date('YmdHis') . '.zip');
        if (!\Core\Fs::write($file, $bytes)) {
            return $this->fail('压缩包写入 runtime/update 失败（目录不可写？）', -1, 500);
        }
        // v3.2：不在请求里解压（大包会撑爆反代超时），丢给后台任务
        $started = UpdateService::startJob($file, 'zip');
        if (!$started['ok']) {
            @unlink($file);
            return $this->fail($started['msg'], -1, 400);
        }
        return $this->ok(['job' => $started['job']], $started['msg']);
    }

    /** 更新任务状态（前端轮询） */
    public function job(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        return $this->ok(['job' => UpdateService::job()]);
    }

    /** 从链接更新：服务器自己下载，绕开浏览器上传与反代请求体限制 */
    public function fromUrl(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        if (UpdateService::jobRunning()) {
            return $this->fail('已经有一个更新任务在跑，等它结束再试', -1, 400);
        }
        $dl = UpdateService::downloadZip(
            (string)$request->input('url', ''),
            trim((string)$request->input('proxy', ''))
        );
        if (!$dl['ok']) {
            return $this->fail($dl['msg'], -1, 400);
        }
        $started = UpdateService::startJob((string)$dl['file'], 'url');
        if (!$started['ok']) {
            @unlink((string)$dl['file']);
            return $this->fail($started['msg'], -1, 400);
        }
        return $this->ok(['job' => $started['job']], $dl['msg'] . '，' . $started['msg']);
    }

    /** 分片上传：初始化，返回 upload id 与分片大小 */
    public function chunkInit(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        if (UpdateService::jobRunning()) {
            return $this->fail('已经有一个更新任务在跑，等它结束再试', -1, 400);
        }
        $size = (int)$request->input('size', 0);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return $this->fail('压缩包大小需要在 1 字节 ~ 64 MB 之间', -1, 400);
        }
        $id = 'u' . date('YmdHis') . bin2hex(random_bytes(4));
        $dir = UpdateService::path('chunks/' . $id);
        if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return $this->fail('创建上传目录失败（runtime/update 不可写？）', -1, 500);
        }
        @file_put_contents($dir . '/meta.json', json_encode([
            'filename' => basename((string)$request->input('filename', 'package.zip')),
            'size'     => $size,
        ], JSON_UNESCAPED_UNICODE));
        return $this->ok([
            'upload'     => $id,
            'chunk_size' => $this->chunkSize(),
            'total'      => (int)ceil($size / $this->chunkSize()),
        ]);
    }

    /** 分片上传：写入一片（整片当 raw body，和插件导入一致） */
    public function chunk(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $id = (string)$request->input('upload', '');
        $index = (int)$request->input('index', -1);
        if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id) || $index < 0) {
            return $this->fail('上传参数不合法', -1, 400);
        }
        $dir = UpdateService::path('chunks/' . $id);
        if (!is_file($dir . '/meta.json')) {
            return $this->fail('这个上传已失效，请重新选择文件', -1, 400);
        }
        $body = (string)$request->rawBody;
        if ($body === '') {
            return $this->fail('这一片是空的（请求体没读到）', -1, 400);
        }
        if (strlen($body) > 4 * 1024 * 1024) {
            return $this->fail('单片超过 4 MB', -1, 400);
        }
        if (@file_put_contents($dir . '/' . sprintf('%05d', $index) . '.part', $body) === false) {
            return $this->fail('写入分片失败（磁盘满或目录不可写）', -1, 500);
        }
        return $this->ok(['upload' => $id, 'index' => $index, 'bytes' => strlen($body)]);
    }

    /** 分片上传：合并所有分片 → 后台更新 */
    public function chunkFinish(Request $request): Response
    {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $id = (string)$request->input('upload', '');
        if (!preg_match('/^[A-Za-z0-9_-]{4,40}$/', $id)) {
            return $this->fail('上传参数不合法', -1, 400);
        }
        $dir = UpdateService::path('chunks/' . $id);
        $meta = json_decode((string)@file_get_contents($dir . '/meta.json'), true);
        if (!is_array($meta)) {
            return $this->fail('这个上传已失效，请重新选择文件', -1, 400);
        }
        $parts = glob($dir . '/*.part') ?: [];
        sort($parts);
        if ($parts === []) {
            return $this->fail('没有收到任何分片', -1, 400);
        }
        $got = 0;
        foreach ($parts as $part) {
            $got += (int)@filesize($part);
        }
        $want = (int)($meta['size'] ?? 0);
        if ($got !== $want) {
            return $this->fail("分片不完整：已收到 {$got} 字节，期望 {$want} 字节（请重试）", -1, 400);
        }

        $file = UpdateService::path('upload-' . date('YmdHis') . '.zip');
        $out = @fopen($file, 'wb');
        if ($out === false) {
            return $this->fail('无法写入合并后的压缩包', -1, 500);
        }
        foreach ($parts as $part) {
            $in = @fopen($part, 'rb');
            if ($in === false) {
                fclose($out);
                @unlink($file);
                return $this->fail('读取分片失败：' . basename($part), -1, 500);
            }
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        $head = (string)@file_get_contents($file, false, null, 0, 2);
        if ($head !== 'PK') {
            @unlink($file);
            $this->cleanupChunks($dir); // 坏包分片没有保留价值，别让 chunks 目录越攒越大
            return $this->fail('合并出来的不是 zip（文件可能损坏，请重新上传）', -1, 400);
        }
        $this->cleanupChunks($dir);

        $started = UpdateService::startJob($file, 'zip');
        if (!$started['ok']) {
            @unlink($file);
            return $this->fail($started['msg'], -1, 400);
        }
        return $this->ok(['job' => $started['job']], $started['msg']);
    }

    private function cleanupChunks(string $dir): void
    {
        foreach (glob($dir . '/*') ?: [] as $item) {
            @unlink($item);
        }
        @rmdir($dir);
    }

    /** @return array{0:string,1:string,2:string} */
    private function zipPayload(Request $request): array
    {
        $filename = trim((string)$request->input('filename', ''));
        $bytes = (string)$request->rawBody;
        if ($bytes === '') {
            foreach ((array)$request->files as $key => $file) {
                $tmp = is_array($file) ? (string)($file['tmp_name'] ?? '') : '';
                if ($tmp === '' || !is_file($tmp)) {
                    continue;
                }
                $bytes = (string)@file_get_contents($tmp);
                if ($filename === '') {
                    $filename = (string)($file['name'] ?? (string)$key);
                }
                break;
            }
        }
        if ($bytes === '') {
            return ['', '', '没收到压缩包内容（请求体为空）'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['', '', '压缩包超过 64 MB'];
        }
        if ($filename === '') {
            $filename = 'update-' . date('YmdHis') . '.zip';
        }
        return [$bytes, $filename, ''];
    }
}