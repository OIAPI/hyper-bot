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
    public function index(Request $request): Response
    {
        return $this->ok([
            'items'  => MemberPermissions::all(),
            // levels 保留给前端下拉，roles/capabilities/matrix 是新的 RBAC 模型
            'levels' => MemberPermissions::LEVELS,
            'model'  => MemberPermissions::model(),
            'stats'  => MemberPermissions::stats(),
        ]);
    }

    public function save(Request $request): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $result = MemberPermissions::set(
            (string)$request->input('qq', ''),
            (string)$request->input('level', '')
        );
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok(['items' => MemberPermissions::all(), 'stats' => MemberPermissions::stats()], $result['msg']);
    }

    public function destroy(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $qq = (string)($params['qq'] ?? '');
        if (!MemberPermissions::remove($qq)) {
            return $this->fail('没有这个人的权限记录', -1, 404);
        }
        return $this->ok(['items' => MemberPermissions::all(), 'stats' => MemberPermissions::stats()], '已移除，将回落到群角色');
    }
}