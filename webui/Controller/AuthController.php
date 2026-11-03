<?php
namespace Webui\Controller;

use Core\Http\Middleware\AuthMiddleware;
use Core\Http\Request;
use Core\Http\Response;
use Webui\Auth;

final class AuthController extends Base {
    public function __construct(
        private AuthMiddleware $auth,
        ?\Core\Store\Store $store = null,
        ?\Webui\Metrics $metrics = null,
    ) {
        parent::__construct($store, $metrics);
    }

    /**
     * 登录状态（前端用来决定是否跳登录页）。
     *
     * 这个端点是**公开**的（否则前端在未登录时无法判断该显示登录页还是控制台），
     * 因此不能依赖 AuthMiddleware 注入的 session，必须自己按 token 查一次。
     */
    public function status(Request $request): Response {
        $token = $this->auth->token($request);
        // 会话由 AuthMiddleware 统一持有（默认是独立文件存储，不走 Redis）
        $session = $this->auth->session($token);
        return $this->ok([
            'authenticated' => $session !== null,
            'user'          => $session['user'] ?? [],
            'needs_setup'   => Auth::needsSetup(),
        ]);
    }

    /** 登录 */
    public function login(Request $request): Response {
        $username = (string)$request->input('username', '');
        $password = (string)$request->input('password', '');
        if ($username === '' || $password === '') {
            return $this->fail('请输入用户名和密码', -1, 400);
        }

        // 简单限流：同一 IP 5 分钟内最多 10 次失败
        $key = 'webui:login:fail:' . sha1($request->remoteIp);
        $fails = (int)($this->store->get($key) ?? 0);
        if ($fails >= 10) {
            return $this->fail('登录尝试过于频繁，请 5 分钟后再试', -2, 429);
        }

        // 账号库为空或读不出来时，"用户名或密码错误" 会把人带到错误方向
        $storageError = Auth::storageError();
        if ($storageError !== null) {
            return $this->fail('账号文件不可用：' . $storageError . '。修复后可用 php bin/hyper passwd admin <密码> 重建', -4, 500);
        }
        if (Auth::users() === []) {
            return $this->fail('还没有任何账号：在服务器执行 php bin/hyper passwd admin <密码> 创建', -4, 409);
        }

        $user = Auth::verify($username, $password);
        if ($user === null) {
            $this->store->set($key, $fails + 1, 300);
            return $this->fail('用户名或密码错误。忘记密码可在服务器执行：php bin/hyper passwd admin', -3, 401);
        }

        $this->store->del($key);
        $token = $this->auth->createSession($user);
        $role = Auth::normalizeRole((string)($user['role'] ?? 'viewer'));
        return Response::ok([
            'token'       => $token,
            'user'        => ['username' => $user['username'], 'role' => $role],
            'role'        => $role,
            'is_owner'    => $role === 'owner',
            'permissions' => Auth::permissions($role),
        ], '登录成功')
            ->withCookie(AuthMiddleware::COOKIE, $token, AuthMiddleware::SESSION_TTL);
    }

    /** 退出登录 */
    public function logout(Request $request): Response {
        $token = $this->auth->token($request);
        if ($token !== '') {
            $this->auth->destroySession($token);
        }
        return Response::ok(null, '已退出')->withCookie(AuthMiddleware::COOKIE, '', 0);
    }

    /** 修改密码 */
    public function changePassword(Request $request): Response {
        $old = (string)$request->input('old_password', '');
        $new = (string)$request->input('new_password', '');
        if (strlen($new) < 8) {
            return $this->fail('新密码至少 8 位', -1, 400);
        }
        $username = (string)($request->user()['username'] ?? '');
        if (Auth::verify($username, $old) === null) {
            return $this->fail('原密码不正确', -2, 400);
        }
        return Auth::changePassword($username, $new)
            ? $this->ok(null, '密码已更新')
            : $this->fail('密码保存失败（runtime/webui 不可写）', -3, 500);
    }

    /**
     * 当前登录用户。
     *
     * 角色与能力一起下发，前端据此隐藏入口；真正的拦截仍在服务端 guard()，
     * 这里只是少让用户点到「点了才 403」的按钮。
     */
    public function me(Request $request): Response {
        $user = $request->user();
        $role = Auth::normalizeRole((string)($user['role'] ?? 'viewer'));
        return $this->ok([
            'user'        => $user,
            'role'        => $role,
            'is_owner'    => $role === 'owner',
            'permissions' => Auth::permissions($role),
        ]);
    }
}