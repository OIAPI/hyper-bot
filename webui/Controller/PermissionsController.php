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

    public function index(Request $request): Response
    {
        return $this->ok($this->payload());
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
        return $this->ok($this->payload(), $result['msg']);
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
        return $this->ok($this->payload(), '已移除，将回落到群角色');
    }
}