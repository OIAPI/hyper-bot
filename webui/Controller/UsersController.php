<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Webui\Auth;

/**
 * 账号管理（仅主人可用）。
 *
 * 这里是「谁能进面板、谁是管理员」的唯一入口，所以三条硬约束写死在服务端：
 *  - 只有 owner 能调用；
 *  - 不能删除/降级最后一个 owner（否则面板永久失去管理能力）；
 *  - 改自己的角色不允许把自己从 owner 降下去（同上）。
 */
final class UsersController extends Base {
    public function index(Request $request): Response {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        return $this->ok([
            'items' => Auth::listUsers(),
            'roles' => Auth::ROLES,
            'capabilities' => Auth::CAPABILITIES,
        ]);
    }

    public function create(Request $request): Response {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $username = trim((string)$request->input('username', ''));
        $password = (string)$request->input('password', '');
        $role = (string)$request->input('role', 'viewer');
        $error = Auth::createUser($username, $password, $role);
        if ($error !== null) {
            return $this->fail($error, -1, 400);
        }
        return $this->ok(['items' => Auth::listUsers()], '账号已创建');
    }

    public function update(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $username = (string)($params['username'] ?? '');
        $role = (string)$request->input('role', '');
        if ($username === '' || $role === '') {
            return $this->fail('参数缺失', -1, 400);
        }
        if (Auth::isOnlyOwner($username) && Auth::normalizeRole($role) !== 'owner') {
            return $this->fail('这是唯一的主人账号，不能降级', -2, 400);
        }
        $error = Auth::setRole($username, $role);
        if ($error !== null) {
            return $this->fail($error, -3, 400);
        }
        return $this->ok(['items' => Auth::listUsers()], '角色已更新');
    }

    public function destroy(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $username = (string)($params['username'] ?? '');
        $self = (string)($request->user()['username'] ?? '');
        if ($username !== '' && $username === $self) {
            return $this->fail('不能删除当前登录的账号', -1, 400);
        }
        $error = Auth::deleteUser($username);
        if ($error !== null) {
            return $this->fail($error, -2, 400);
        }
        return $this->ok(['items' => Auth::listUsers()], '账号已删除');
    }

    /** 主人重置别人的密码（不需要知道旧密码） */
    public function resetPassword(Request $request, array $params): Response {
        if ($deny = $this->guard($request, 'user.manage')) {
            return $deny;
        }
        $username = (string)($params['username'] ?? '');
        $password = (string)$request->input('password', '');
        if (strlen($password) < 8) {
            return $this->fail('密码至少 8 位', -1, 400);
        }
        if (!Auth::changePassword($username, $password)) {
            return $this->fail('账号不存在或保存失败', -2, 400);
        }
        return $this->ok(null, '密码已重置');
    }
}
