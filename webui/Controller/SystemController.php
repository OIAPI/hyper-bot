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
        $storeDriver = $this->metrics->storeDriver();
        $redisOk = $this->redisPing();
        $runtimeOk = is_writable($this->root . '/runtime');
        $configOk = is_writable(Config::path());
        $vendorOk = is_file($this->root . '/vendor/autoload.php');

        // Redis 连得上但不是 Redis（端口被 HTTP 占用等）时，存储层会降级到文件存储，
        // 这里必须显式说明，否则用户只看到「Redis OK」却查不到数据
        $store = \Core\App::boot()->store;
        $degraded = $store instanceof \Core\Store\ResilientStore && $store->degraded();
        $storeDetail = $degraded ? $storeDriver . '（主存储故障，已降级文件存储）' : $storeDriver;

        $checks = [
            ['name' => '存储', 'ok' => true, 'detail' => $storeDetail],
            ['name' => 'Redis', 'ok' => $redisOk !== false, 'detail' => $redisOk === null ? '未安装扩展（用文件存储）' : ($redisOk ? 'PING 正常' : '连不上，已回落文件存储')],
            ['name' => 'runtime 目录', 'ok' => $runtimeOk, 'detail' => $runtimeOk ? $this->root . '/runtime' : '不可写，日志与缓存都会失败'],
            ['name' => 'Config.ini', 'ok' => $configOk, 'detail' => $configOk ? Config::path() : '不可写，面板无法保存配置'],
            ['name' => 'vendor', 'ok' => $vendorOk, 'detail' => $vendorOk ? '依赖已就绪' : '缺少 vendor/autoload.php'],
            ['name' => 'PHP', 'ok' => version_compare(PHP_VERSION, '8.1.0', '>='), 'detail' => PHP_VERSION],
            ['name' => 'swoole', 'ok' => true, 'detail' => extension_loaded('swoole') ? '已安装 ' . phpversion('swoole') : '未安装（php 内置服务器运行）'],
        ];
        $ok = $runtimeOk && $configOk && $vendorOk;
        return $this->ok([
            'version' => \Core\Version::current(),
            'ok'      => $ok,
            'status'  => $ok ? 'ok' : 'degraded',
            'checks'  => $checks,
            'failing' => array_values(array_map(fn($c) => $c['name'], array_filter($checks, fn($c) => !$c['ok']))),
            'store_driver' => $storeDriver,
            'redis_ok'     => $redisOk,
            'runtime_writable' => $runtimeOk,
            'config_writable'  => $configOk,
        ], $ok ? 'ok' : 'degraded');
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
        $totalBytes = array_sum(array_column($out, 'bytes'));
        $total = @disk_total_space($this->root) ?: 0;
        $free = @disk_free_space($this->root) ?: 0;
        $dirs = array_map(fn($d) => [
            'name'  => $d['path'],
            'size'  => $d['bytes'],
            'files' => $d['files'],
        ], $out);
        return $this->ok([
            'root'        => $this->root,
            'total'       => $total,
            'free'        => $free,
            'used'        => max(0, $total - $free),
            'runtime'     => $totalBytes,
            'dirs'        => $dirs,
            'items'       => $out,
            'total_bytes' => $totalBytes,
        ]);
    }

    /** 清理过期缓存（破坏性，需要显式 confirm） */
    public function cacheClear(Request $request): Response {
        if ($deny = $this->guard($request, 'system.cache')) {
            return $deny;
        }
        if ($request->input('confirm') !== true) {
            return $this->fail('请传 confirm=true 以确认清理', -1, 400);
        }
        $this->store->del(\Core\Bot\EventLog::key());
        if (\Core\Bot\AccountScope::legacyAllowed()) {
            $this->store->del(\Core\Bot\EventLog::KEY);
        }
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