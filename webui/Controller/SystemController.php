<?php
namespace Webui\Controller;

use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Core\Store\FileStore;
use Utils\Operate;

/** 系统：健康检查 / 存储维护 / 目录体积 / 路由清单 */
final class SystemController extends Base {
    public function health(Request $request): Response {
        $stores = [
            'store_driver' => $this->metrics->storeDriver(),
            'redis_ok'     => $this->redisPing(),
            'runtime_writable' => is_writable($this->root . '/runtime'),
            'config_writable'  => is_writable(Config::path()),
        ];
        $ok = $stores['runtime_writable'] && $stores['config_writable'];
        return $this->ok($stores, $ok ? 'ok' : 'degraded');
    }

    private function redisPing(): ?bool {
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $client = new \Redis();
            return (bool)@$client->connect(Config::string('Redis.host', '127.0.0.1'), Config::int('Redis.port', 6379), 1.0);
        } catch (\Throwable) {
            return false;
        }
    }

    /** runtime 目录体积统计 */
    public function disk(Request $request): Response {
        $dirs = ['runtime/log', 'runtime/message', 'runtime/store', 'runtime/webui', 'runtime/github'];
        $out = [];
        foreach ($dirs as $dir) {
            $path = $this->root . '/' . $dir;
            $size = 0;
            $files = 0;
            if (is_dir($path)) {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)) as $file) {
                    if ($file->isFile()) {
                        $size += $file->getSize();
                        $files++;
                    }
                }
            }
            $out[] = ['path' => $dir, 'bytes' => $size, 'files' => $files, 'exists' => is_dir($path)];
        }
        return $this->ok(['items' => $out, 'total_bytes' => array_sum(array_column($out, 'bytes'))]);
    }

    /** 清理过期缓存（破坏性，需要显式 confirm） */
    public function cacheClear(Request $request): Response {
        if ($request->input('confirm') !== true) {
            return $this->fail('请传 confirm=true 以确认清理', -1, 400);
        }
        $this->store->del('webui:events');
        $this->store->del('webui:messages');
        // 插件注册表一并失效（下次访问会重新扫描）
        \Core\App::boot()->container->get(\Core\Bot\Registry::class)->invalidate();
        return $this->ok(null, '缓存已清理');
    }

    /** 路由清单（开发/排查用） */
    public function routes(Request $request): Response {
        $router = \Core\App::boot()->container->get(\Core\Http\Router::class);
        // Router::all() 是「方法 => 路由列表」的二维表。
        $out = [];
        foreach ($router->all() as $method => $routes) {
            foreach ($routes as $route) {
                $out[] = [
                    'method'      => (string)$method,
                    'pattern'     => $route['pattern'] ?? '',
                    'name'        => $route['name'] ?? null,
                    'middlewares' => $route['middlewares'] ?? [],
                ];
            }
        }
        usort($out, fn($a, $b) => [$a['pattern'], $a['method']] <=> [$b['pattern'], $b['method']]);
        return $this->ok(['items' => $out, 'total' => count($out)]);
    }
}
