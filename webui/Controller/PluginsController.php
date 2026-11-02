<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Controller\Router;

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

    /** 重新扫描（清空 Router 缓存后重建） */
    public function reload(Request $request): Response {
        Router::clean();
        $items = $this->metrics->plugins()['items'];
        return $this->ok(['items' => $items, 'total' => count($items)], '已重新扫描');
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
}
