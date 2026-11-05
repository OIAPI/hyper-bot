<?php
namespace Webui\Controller;

use Core\Bot\MemberPermissions;
use Core\Http\Request;
use Core\Http\Response;

/**
 * QQ 成员权限：控制「谁能用机器人的管理命令」。
 *
 * 与「账号设置」里的面板账号完全分开：面板账号决定谁能登录后台，
 * 这里是机器人在群里认人的权限（v1 的 memberPermission.<QQ>）。
 */
final class PermissionsController extends Base
{
    /**
     * 统一的响应体：所有写操作也返回完整结构。
     *
     * 以前 save/destroy 只回 items+stats，前端直接把它赋给 data，模板里的
     * data.levels[role] 就变成 undefined[role]（报 member 读不到）——所以
     * 写接口也必须回 levels/model/diagnose。
     */
    private function payload(): array
    {
        return [
            'items'    => MemberPermissions::all(),
            // levels 保留给前端下拉，roles/capabilities/matrix 是新的 RBAC 模型
            'levels'   => MemberPermissions::LEVELS,
            'model'    => MemberPermissions::model(),
            'stats'    => MemberPermissions::stats(),
            // 列表为空时，面板要能说清「是没配置，还是连不上机器人的 Redis」
            'diagnose' => MemberPermissions::diagnose(),
        ];
    }

    /** 选定账号并压入上下文；账号不存在返回 null */
    private function selectAccount(Request $request): ?string
    {
        $id = trim((string)$request->input('account', ''));
        if ($id !== '') {
            $account = \Core\Bot\Accounts::get($id);
            if ($account === null) {
                return null;
            }
        } else {
            $enabled = \Core\Bot\Accounts::enabled();
            $account = $enabled[0] ?? null;
        }
        if ($account !== null) {
            \Core\Bot\AccountContext::use($account);
            return (string)($account['id'] ?? '');
        }
        \Core\Bot\AccountContext::reset();
        return '';
    }

    private function decorate(array $data, string $accountId): array
    {
        $data['account'] = $accountId;
        $data['accounts'] = array_map(
            static fn(array $a): array => ['id' => $a['id'], 'name' => $a['name'], 'protocol' => $a['protocol']],
            \Core\Bot\Accounts::publicList()
        );
        return $data;
    }

    public function index(Request $request): Response
    {
        $accountId = $this->selectAccount($request);
        if ($accountId === null) {
            \Core\Bot\AccountContext::reset();
            return $this->fail('账号不存在', -1, 404);
        }
        $data = $this->decorate($this->payload(), $accountId);
        \Core\Bot\AccountContext::reset();
        return $this->ok($data);
    }

    public function save(Request $request): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $accountId = $this->selectAccount($request);
        if ($accountId === null) {
            \Core\Bot\AccountContext::reset();
            return $this->fail('账号不存在', -1, 404);
        }
        try {
            $result = MemberPermissions::set(
                (string)$request->input('qq', ''),
                (string)$request->input('level', '')
            );
            $data = $this->decorate($this->payload(), $accountId);
        } finally {
            \Core\Bot\AccountContext::reset();
        }
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok($data, $result['msg']);
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $accountId = $this->selectAccount($request);
        if ($accountId === null) {
            \Core\Bot\AccountContext::reset();
            return $this->fail('账号不存在', -1, 404);
        }
        try {
            $qq = (string)($params['qq'] ?? '');
            $ok = MemberPermissions::remove($qq);
            $data = $this->decorate($this->payload(), $accountId);
        } finally {
            \Core\Bot\AccountContext::reset();
        }
        if (!$ok) {
            return $this->fail('没有这个人的权限记录', -1, 404);
        }
        return $this->ok($data, '已移除，将回落到群角色');
    }
}