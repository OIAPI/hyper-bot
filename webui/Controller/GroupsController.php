<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;

/** 群管理：列表 / 详情 / 插件开关 / 启停 / 元数据 / 删除 */
final class GroupsController extends Base {
    /** 请求里指定的账号；未指定时用第一个启用账号（单账号/旧部署回退全局） */
    private function accountId(Request $request): string {
        $id = trim((string)$request->input('account', ''));
        if ($id !== '') {
            return $id;
        }
        $all = \Core\Bot\Accounts::enabled();
        return $all === [] ? '' : (string)($all[0]['id'] ?? '');
    }

    private function accountExists(string $id): bool {
        return $id === '' || \Core\Bot\Accounts::get($id) !== null;
    }

    public function index(Request $request): Response {
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $groups = $this->metrics->groups($accountId)['items'];
        $keyword = (string)$request->input('q', '');
        if ($keyword !== '') {
            $groups = array_values(array_filter($groups, fn($g) =>
                str_contains($g['group_id'], $keyword) || str_contains((string)$g['name'], $keyword)
            ));
        }
        [$page, $size] = $this->page($request);
        $data = $this->paginate($groups, $page, $size);
        $data['account'] = $accountId;
        $data['accounts'] = array_map(
            static fn(array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'protocol' => $a['protocol']],
            \Core\Bot\Accounts::publicList()
        );
        return $this->ok($data);
    }

    /**
     * 从协议端拉取一次群列表，把群名写进 webui:groups。
     *
     * 之前群名只能从事件里「顺便」学：框架支持 get_group_list，却一直没人调用，
     * 所以没发过言的群在面板里永远是空名字。这里主动拉一次并落盘。
     */
    public function sync(Request $request): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        // 拉群列表要用「账号自己的 api / token」，没有显式指定时，
        // 该协议下只有一个启用账号就用它，多个账号必须指定，避免拉错号的群。
        $accountId = trim((string)$request->input('account', ''));
        if ($accountId === '' && \Core\Bot\Accounts::countForProtocol('onebot') > 1) {
            return $this->fail('该协议下配置了多个账号，请指定 account（' . implode('、', \Core\Bot\Accounts::idsForProtocol('onebot')) . '）', -1, 400);
        }
        $account = $accountId !== ''
            ? \Core\Bot\Accounts::get($accountId)
            : \Core\Bot\Accounts::forProtocol('onebot');
        if ($accountId !== '' && $account === null) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $accountId = (string)($account['id'] ?? $accountId);
        $api = (string)(($account['api'] ?? '') ?: \Protocol\Core\Config::string('onebot.api', ''));
        $hasWs = \Protocol\OneBot\WsHub::hasForward() || \Protocol\OneBot\WsHub::hasReverse();
        if ($api === '' && !$hasWs) {
            return $this->fail($accountId !== ''
                ? '账号 ' . $accountId . ' 没有填 api 地址，也没有可用的 WS 连接'
                : '没有可用的 OneBot 接口：请在「账号」页填写 api，或开启正向 / 反向 WS', -1, 400);
        }
        \Core\Bot\AccountContext::use($account);
        try {
            $adapter = \Protocol\OneBot\Adapter::currentAdapter();
            $resp = $adapter->call('get_group_list');
        } catch (\Throwable $e) {
            \Core\Bot\AccountContext::reset();
            return $this->fail('调用 get_group_list 失败：' . $e->getMessage(), -2, 502);
        } finally {
            \Core\Bot\AccountContext::reset();
        }
        $status = (string)($resp->status ?? 'failed');
        if ($status !== 'ok' && $status !== 'async') {
            $detail = (string)($resp->message ?? '');
            return $this->fail('协议端拒绝了 get_group_list' . ($detail !== '' ? '：' . $detail : ''), -3, 502);
        }
        $list = $resp->data;
        if (!is_array($list)) {
            return $this->fail('协议端返回的群列表格式不对（data 不是数组）', -4, 502);
        }

        $meta = \Core\Bot\GroupState::meta($this->store, $accountId);
        $synced = 0;
        foreach ($list as $group) {
            if (!is_array($group)) {
                continue;
            }
            $id = (string)($group['group_id'] ?? '');
            if ($id === '' || !preg_match('/^[0-9a-zA-Z_-]+$/', $id)) {
                continue;
            }
            $name = (string)($group['group_name'] ?? ($group['name'] ?? ''));
            $row = is_array($meta[$id] ?? null) ? $meta[$id] : [];
            if ($name !== '') {
                $row['name'] = $name;
            }
            $row['updated_at'] = time();
            $meta[$id] = $row;
            // 没有 {id}.info 的群在列表里根本不会出现，这里补一条空白开关
            if (\Core\Bot\GroupState::getInfo($this->store, $id, $accountId) === null) {
                \Core\Bot\GroupState::setInfo($this->store, $id, ['status' => 1, 'worker' => [], 'name' => $name], $accountId);
            }
            \Core\Bot\GroupState::clearRemoved($this->store, $id, $accountId);
            $synced++;
        }
        \Core\Bot\GroupState::setMeta($this->store, $meta, $accountId);
        return $this->ok([
            'synced' => $synced,
            'total'  => count($this->metrics->groups($accountId)['items']),
        ], '已同步 ' . $synced . ' 个群');
    }

    public function show(Request $request, array $params): Response {
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $group = $this->metrics->group((string)($params['group_id'] ?? ''), $accountId);
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

        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $info = \Core\Bot\GroupState::getInfo($this->store, $groupId, $accountId)
            ?? ['status' => 0, 'worker' => []];
        $info['worker'] = is_array($info['worker'] ?? null) ? $info['worker'] : [];
        $info['worker'][$plugin] = $enabled;
        \Core\Bot\GroupState::setInfo($this->store, $groupId, $info, $accountId);
        // 返回完整群对象：前端原来拿这个局部对象覆盖整行，结果 name / updated_at 被抹掉
        return $this->ok($this->metrics->group($groupId, $accountId) ?? ['group_id' => $groupId, 'plugin' => $plugin, 'enabled' => $enabled]);
    }

    /** 群总开关 */
    public function toggleStatus(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $info = \Core\Bot\GroupState::getInfo($this->store, $groupId, $accountId)
            ?? ['status' => 0, 'worker' => []];
        $info['status'] = (int)($info['status'] ?? 0) === 1 ? 0 : 1;
        $info['updated_at'] = time();
        \Core\Bot\GroupState::setInfo($this->store, $groupId, $info, $accountId);
        \Core\Bot\GroupState::clearRemoved($this->store, $groupId, $accountId);
        return $this->ok($this->metrics->group($groupId, $accountId) ?? ['group_id' => $groupId, 'status' => $info['status'] === 1]);
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
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        $meta = \Core\Bot\GroupState::meta($this->store, $accountId);
        $meta[$groupId] = array_merge((array)($meta[$groupId] ?? []), [
            'name'       => (string)$request->input('name', (string)($meta[$groupId]['name'] ?? '')),
            'note'       => (string)$request->input('note', (string)($meta[$groupId]['note'] ?? '')),
            'tags'       => (array)$request->input('tags', (array)($meta[$groupId]['tags'] ?? [])),
            'updated_at' => time(),
        ]);
        \Core\Bot\GroupState::setMeta($this->store, $meta, $accountId);
        return $this->ok($this->metrics->group($groupId, $accountId));
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
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        if (\Core\Bot\GroupState::getInfo($this->store, $groupId, $accountId) === null) {
            \Core\Bot\GroupState::setInfo($this->store, $groupId, ['status' => 0, 'worker' => [], 'updated_at' => time()], $accountId);
        }
        // 手动添加时填的群名要落库，否则列表里永远显示不出来
        $name = trim((string)$request->input('name', ''));
        $meta = \Core\Bot\GroupState::meta($this->store, $accountId);
        $row = is_array($meta[$groupId] ?? null) ? $meta[$groupId] : [];
        if ($name !== '') {
            $row['name'] = $name;
        }
        $row['updated_at'] = time();
        $meta[$groupId] = $row;
        \Core\Bot\GroupState::setMeta($this->store, $meta, $accountId);
        \Core\Bot\GroupState::clearRemoved($this->store, $groupId, $accountId);
        return $this->ok($this->metrics->group($groupId, $accountId), '已登记');
    }

    /** 移除群的配置（不影响 QQ 侧） */
    public function destroy(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'group.write')) {
            return $deny;
        }
        $groupId = (string)($params['group_id'] ?? '');
        $accountId = $this->accountId($request);
        if (!$this->accountExists($accountId)) {
            return $this->fail('账号不存在：' . $accountId, -1, 404);
        }
        \Core\Bot\GroupState::delInfo($this->store, $groupId, $accountId);
        $meta = \Core\Bot\GroupState::meta($this->store, $accountId);
        unset($meta[$groupId]);
        \Core\Bot\GroupState::setMeta($this->store, $meta, $accountId);
        // 旧事件里仍有这个群，记一个墓碑，防止刷新后又被事件拉回来
        \Core\Bot\GroupState::markRemoved($this->store, $groupId, time(), $accountId);
        return $this->ok(null, '已移除');
    }
}