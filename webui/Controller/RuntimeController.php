<?php
namespace Webui\Controller;

use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Core\Runtime;

/**
 * 运行架构配置（v2）：WebUI 上切换 swoole / php / webui。
 *
 * 为什么要在 WebUI 上可配：
 *   生产机装 Swoole、开发机不装，是同一个项目的常见状态。
 *   如果在 WebUI 里能切，运维就不需要 SSH 上去改 Config.ini 再重启。
 *
 * 注意：切换架构**不会立即生效**（架构决定进程模型，需要重启）。
 * 所以这里如实返回 needs_restart，而不是假装成功。
 */
final class RuntimeController extends Base
{
    /** 三种架构 + 当前生效值 */
    public function index(Request $request): Response
    {
        $app = \Core\App::boot();
        $catalog = Runtime::catalog();

        return $this->ok([
            'current'   => [
                'mode'      => $app->runtime->mode,
                'label'     => $app->runtime->label(),
                'degraded'  => $app->runtime->degraded,
                'reason'    => $app->runtime->reason,
                'requested' => $app->runtime->requested,
                'limitations' => $app->runtime->limitations(),
            ],
            'configured' => Config::string('runtime.arch', 'swoole'),
            'modes'      => array_map(static fn(string $id, array $meta): array => $meta + ['id' => $id], array_keys($catalog), $catalog),
            'php'        => [
                'version' => PHP_VERSION,
                'extensions' => [
                    'swoole'  => extension_loaded('swoole'),
                    'redis'   => extension_loaded('redis'),
                    'sodium'  => extension_loaded('sodium'),
                    'curl'    => extension_loaded('curl'),
                ],
            ],
        ]);
    }

    /** 切换架构（写入 Config.ini，需重启生效） */
    public function update(Request $request): Response
    {
        if ($deny = $this->guard($request, 'runtime.write')) {
            return $deny;
        }
        $arch = strtolower(trim((string)$request->input('arch', '')));
        $valid = array_keys(Runtime::catalog());
        if (!in_array($arch, $valid, true) && $arch !== 'auto') {
            return $this->fail('架构不合法，可选：' . implode('/', [...$valid, 'auto']), -1, 400);
        }

        if ($arch === Runtime::SWOOLE && !extension_loaded('swoole')) {
            return $this->fail('未安装 ext-swoole，无法选择 swoole 架构', -2, 400);
        }

        if (!Config::set('runtime.arch', $arch)) {
            return $this->fail('写入 Config.ini 失败（文件不可写）', -3, 500);
        }

        return $this->ok([
            'arch'           => $arch,
            'needs_restart'  => true,
        ], '架构已保存，重启后生效');
    }
}