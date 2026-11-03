<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;

/** 群管理：列表 / 详情 / 插件开关 / 启停 / 元数据 / 删除 */
final class GroupsController extends Base {
    public function index(Request $request): Response {
        $groups = $this->metrics->groups()['items'];
        $keyword = (string)$request->input('q', '');
        if ($keyword !== '') {
            $groups = array_values(array_filter($groups, fn($g) =>
                str_contains($g['group_id'], $keyword) || str_contains((string)$g['name'], $keyword)
            ));
        }
        [$page, $size] = $this->page($request);
        return $this->ok($this->paginate($groups, $page, $size));
    }

    public function show(Request $request, array $params): Response {
        $group = $this->metrics->group((string)($params['group_id'] ?? ''));
        return $group === null ? $this->fail('群不存在', -404, 404) : $this->ok($group);
    }

    /** 开关单个插件 */
    public function togglePlugin(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        $plugin = (string)($params['plugin'] ?? '');
        $enabled = (bool)$request->input('enabled', false);
        if ($groupId === '' || $plugin === '') {
            return $this->fail('参数缺失', -1, 400);
        }
        if (!class_exists('\\' . ltrim($plugin, '\\'))) {
            return $this->fail('插件不存在：' . $plugin, -2, 404);
        }
        if (str_contains(strtolower($plugin), 'help') || str_contains(strtolower($plugin), 'sys')) {
            return $this->fail('该插件不可关闭', -3, 400);
        }

        $key = $groupId . '.info';
        $info = $this->store->get($key);
        $info = is_array($info) ? $info : ['status' => 0, 'worker' => []];
        $info['worker'] = is_array($info['worker'] ?? null) ? $info['worker'] : [];
        $info['worker'][$plugin] = $enabled;
        $this->store->set($key, $info);
        // 返回完整群对象：前端原来拿这个局部对象覆盖整行，结果 name / updated_at 被抹掉
        return $this->ok($this->metrics->group($groupId) ?? ['group_id' => $groupId, 'plugin' => $plugin, 'enabled' => $enabled]);
    }

    /** 群总开关 */
    public function toggleStatus(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        $key = $groupId . '.info';
        $info = $this->store->get($key);
        $info = is_array($info) ? $info : ['status' => 0, 'worker' => []];
        $info['status'] = (int)($info['status'] ?? 0) === 1 ? 0 : 1;
        $info['updated_at'] = time();
        $this->store->set($key, $info);
        return $this->ok($this->metrics->group($groupId) ?? ['group_id' => $groupId, 'status' => $info['status'] === 1]);
    }

    /** 更新 WebUI 维护的元数据（备注 / 标签 / 名称） */
    public function updateMeta(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        if ($groupId === '') {
            return $this->fail('缺少群号', -1, 400);
        }
        $meta = (array)($this->store->get('webui:groups') ?? []);
        $meta[$groupId] = array_merge((array)($meta[$groupId] ?? []), [
            'name'       => (string)$request->input('name', (string)($meta[$groupId]['name'] ?? '')),
            'note'       => (string)$request->input('note', (string)($meta[$groupId]['note'] ?? '')),
            'tags'       => (array)$request->input('tags', (array)($meta[$groupId]['tags'] ?? [])),
            'updated_at' => time(),
        ]);
        $this->store->set('webui:groups', $meta);
        return $this->ok($this->metrics->group($groupId));
    }

    /** 新建/登记一个群 */
    public function create(Request $request): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = trim((string)$request->input('group_id', ''));
        if ($groupId === '' || !preg_match('/^[0-9a-zA-Z_-]+$/', $groupId)) {
            return $this->fail('群号格式不正确', -1, 400);
        }
        $key = $groupId . '.info';
        if ($this->store->get($key) === null) {
            $this->store->set($key, ['status' => 0, 'worker' => [], 'updated_at' => time()]);
        }
        // 手动添加时填的群名要落库，否则列表里永远显示不出来
        $name = trim((string)$request->input('name', ''));
        $meta = (array)($this->store->get('webui:groups') ?? []);
        $row = is_array($meta[$groupId] ?? null) ? $meta[$groupId] : [];
        if ($name !== '') {
            $row['name'] = $name;
        }
        $row['updated_at'] = time();
        $meta[$groupId] = $row;
        $this->store->set('webui:groups', $meta);
        return $this->ok($this->metrics->group($groupId), '已登记');
    }

    /** 移除群的配置（不影响 QQ 侧） */
    public function destroy(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        $this->store->del($groupId . '.info');
        $meta = (array)($this->store->get('webui:groups') ?? []);
        unset($meta[$groupId]);
        $this->store->set('webui:groups', $meta);
        return $this->ok(null, '已移除');
    }
}