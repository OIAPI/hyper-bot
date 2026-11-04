<?php
namespace Webui\Controller;

use Core\App;
use Core\Http\Request;
use Core\Http\Response;
use Core\Bot\Registry;
use Webui\PluginInstaller;

/** 插件：列表 / 详情 / 重载扫描 / 群内批量开关 */
final class PluginsController extends Base {
    public function index(Request $request): Response {
        $items = $this->metrics->plugins()['items'];
        $keyword = (string)$request->input('q', '');
        if ($keyword !== '') {
            $items = array_values(array_filter($items, fn($p) =>
                stripos($p['short'], $keyword) !== false
                || stripos((string)$p['name'], $keyword) !== false
                || stripos((string)$p['description'], $keyword) !== false
            ));
        }
        return $this->ok(['items' => $items, 'total' => count($items)]);
    }

    public function show(Request $request, array $params): Response {
        $class = (string)($params['plugin'] ?? '');
        foreach ($this->metrics->plugins()['items'] as $plugin) {
            if ($plugin['class'] === $class || $plugin['short'] === $class) {
                return $this->ok($plugin);
            }
        }
        return $this->fail('插件不存在', -404, 404);
    }

    /** 重新扫描插件（清空注册表缓存后重建） */
    public function reload(Request $request): Response {
        if ($deny = $this->guard($request, 'plugin.reload')) {
            return $deny;
        }
        // reload 要同时丢掉两类缓存，否则列表可能还是旧的：
        //   1) PluginState 的进程内 disabled 缓存（多 worker 时尤其明显）；
        //   2) v1 遗留的 Redis 注册表键 bot:registry:v2（老版本写过，留着会盖住新扫描结果）。
        \Core\Bot\PluginState::flush();
        $this->store->del('bot:registry:v2');
        \Core\App::boot()->container->get(Registry::class)->invalidate();
        $items = $this->metrics->plugins()['items'];

        // 群级开关不再需要往 Redis worker 表里补键：
        // Help/Sys 已改为以注册表（文件扫描）列表为准，worker 只保存「本群关掉了哪些」。
        return $this->ok(['items' => $items, 'total' => count($items)], '已重新扫描');
    }

    /** 全局启用 / 停用某个插件（停用后重载注册表，钩子不再参与分发） */
    public function enable(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'plugin.reload')) {
            return $deny;
        }
        $class = (string)($params['plugin'] ?? '');
        $enabled = (bool)$request->input('enabled', true);
        // 类名大小写不敏感（PHP 本身如此），用短名或全名都能定位
        $found = null;
        foreach ($this->metrics->plugins()['items'] as $plugin) {
            if (strcasecmp((string)$plugin['class'], $class) === 0 || strcasecmp((string)$plugin['short'], $class) === 0) {
                $found = $plugin['class'];
                break;
            }
        }
        if ($found === null) {
            return $this->fail('插件不存在：' . $class, -404, 404);
        }
        if (!\Core\Bot\PluginState::setEnabled($found, $enabled)) {
            return $this->fail('状态保存失败（runtime/ 不可写）', -2, 500);
        }
        \Core\App::boot()->container->get(Registry::class)->invalidate();
        $items = $this->metrics->plugins()['items'];
        return $this->ok(
            ['items' => $items, 'total' => count($items), 'class' => $found, 'enabled' => $enabled],
            $enabled ? '插件已启用' : '插件已停用'
        );
    }

    /** 在某个群里批量开关插件 */
    public function bulkToggle(Request $request, array $params): Response {
        $groupId = (string)($params['group_id'] ?? '');
        $plugins = (array)$request->input('plugins', []);
        $enabled = (bool)$request->input('enabled', true);
        if ($groupId === '' || $plugins === []) {
            return $this->fail('参数缺失', -1, 400);
        }
        $key = $groupId . '.info';
        $info = $this->store->get($key);
        $info = is_array($info) ? $info : ['status' => 0, 'worker' => []];
        $info['worker'] = is_array($info['worker'] ?? null) ? $info['worker'] : [];
        $changed = [];
        foreach ($plugins as $plugin) {
            $plugin = (string)$plugin;
            if ($plugin === '' || !class_exists('\\' . ltrim($plugin, '\\'))) {
                continue;
            }
            $info['worker'][$plugin] = $enabled;
            $changed[] = $plugin;
        }
        $this->store->set($key, $info);
        return $this->ok(['group_id' => $groupId, 'changed' => $changed, 'enabled' => $enabled]);
    }

    /**
     * 上传 zip 包安装插件。
     *
     * 走 raw body 而不是 multipart：Swoole 默认 package_max_length 只有 2 MB，
     * 且容器里 /tmp 未必可写，multipart 解析会先落到临时文件——直接收字节最省事。
     * 仍然保留 multipart 兜底，因为有些反代会强行改写 content-type。
     */
    public function importZip(Request $request): Response {
        if ($deny = $this->guard($request, 'plugin.install')) {
            return $deny;
        }
        [$bytes, $filename, $error] = $this->zipPayload($request);
        if ($error !== '') {
            return $this->fail($error, -1, 400);
        }

        $name = trim((string)$request->input('name', ''));
        $result = $this->installer()->installZip($bytes, $filename, $name);
        if (!$result['success']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->imported('zip', $result);
    }

    /** 从 git 仓库克隆安装插件 */
    public function importGit(Request $request): Response {
        if ($deny = $this->guard($request, 'plugin.install')) {
            return $deny;
        }
        $url = trim((string)$request->input('url', ''));
        $branch = trim((string)$request->input('branch', ''));
        $name = trim((string)$request->input('name', ''));
        if ($url === '') {
            return $this->fail('请填写仓库地址', -1, 400);
        }

        $result = $this->installer()->installGit($url, $branch, $name);
        if (!$result['success']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->imported('git', $result);
    }

    // ---------------------------------------------------------------- 导入辅助

    private function installer(): PluginInstaller {
        return new PluginInstaller($this->root);
    }

    /**
     * 取 zip 字节与文件名。返回 [字节, 文件名, 错误]。
     *
     * @return array{0:string,1:string,2:string}
     */
    private function zipPayload(Request $request): array {
        $filename = trim((string)$request->input('filename', ''));
        $bytes = (string)$request->rawBody;

        if ($bytes === '') {
            // multipart 兜底：Swoole 会把上传文件落到临时目录
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
        if (strlen($bytes) > PluginInstaller::MAX_BYTES) {
            return ['', '', '压缩包超过 32 MB'];
        }
        if ($filename === '') {
            $filename = 'plugin-' . date('YmdHis') . '.zip';
        }
        return [$bytes, $filename, ''];
    }

    /**
     * 装完后重扫注册表并回读插件信息，让前端能立刻看到「装上了」还是「没被识别」。
     * 回读失败不算安装失败——文件确实落盘了，缺 composer.json 这类问题靠 warnings 提示。
     */
    private function imported(string $source, array $result): Response {
        $plugin = null;
        $warnings = (array)$result['warnings'];
        try {
            // 注意：没有 App::reloadPlugins() 这个方法，重扫要经注册表自己失效再加载
            $registry = App::boot()->container->get(Registry::class);
            $registry->invalidate();
            $registry->load(true);
            $plugin = $this->findPluginByPath((string)$result['path']);
        } catch (\Throwable $e) {
            $warnings[] = '重扫插件失败：' . $e->getMessage();
        }
        if ($plugin === null && $warnings === []) {
            $warnings[] = '包已落盘，但注册表没扫到入口类（检查 composer.json 的 autoload.psr-4 与入口类文件名）';
        }

        $payload = [
            'source'   => $source,
            'name'     => (string)$result['name'],
            'path'     => $this->relativePath((string)$result['path']),
            'files'    => (int)$result['files'],
            'plugin'   => $plugin,
            'warnings' => array_values($warnings),
        ];
        return $this->ok($payload, $source === 'zip' ? '已安装 ' . $payload['name'] : '已拉取 ' . $payload['name']);
    }

    /** 用类文件真实路径匹配，比猜类名可靠（插件的 @name 和目录名经常不一致） */
    private function findPluginByPath(string $path): ?array {
        $prefix = rtrim($path, '/') . '/';
        foreach ($this->metrics->plugins()['items'] as $item) {
            try {
                $file = (new \ReflectionClass((string)$item['class']))->getFileName();
            } catch (\Throwable) {
                continue;
            }
            if (is_string($file) && str_starts_with($file, $prefix)) {
                return $item;
            }
        }
        return null;
    }

    private function relativePath(string $path): string {
        $root = rtrim($this->root, '/') . '/';
        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }
}