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
        $result = UpdateService::fromZip($file);
        @unlink($file);
        return $result['ok'] ? $this->ok($result, (string)$result['msg']) : $this->fail((string)$result['msg'], -1, 400);
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