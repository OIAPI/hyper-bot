<?php
namespace Core\Http\Middleware;

use Core\Http\Middleware;
use Core\Http\Request;
use Core\Http\Response;
use Core\Store\Store;

/**
 * WebUI 会话鉴权。
 *
 * 设计要点（与历史 CQ 框架的「群管理后台」完全不同）：
 *  - 会话是**服务端**存储的，token 只存在 HttpOnly Cookie / Authorization 头里；
 *  - 登录态带过期时间且可主动吊销；
 *  - 所有写入操作必须经过同一个中间件，避免出现「某个接口忘了鉴权」。
 */
final class AuthMiddleware implements Middleware {
    public const COOKIE = 'wb_session';
    public const SESSION_TTL = 43200; // 12 小时

    public function __construct(private Store $store, private string $prefix = 'webui:session:') {}

    public function process(Request $request, callable $next): Response {
        $token = $this->token($request);
        if ($token === '') {
            return Response::fail('未登录或会话已过期', -401, 401);
        }
        $session = $this->store->get($this->prefix . $token);
        if (!is_array($session)) {
            return Response::fail('未登录或会话已过期', -401, 401);
        }
        if (($session['expires'] ?? 0) < time()) {
            $this->store->del($this->prefix . $token);
            return Response::fail('会话已过期，请重新登录', -401, 401);
        }
        // 滑动续期：一半有效期后自动延长
        if (($session['expires'] ?? 0) - time() < self::SESSION_TTL / 2) {
            $session['expires'] = time() + self::SESSION_TTL;
            $this->store->set($this->prefix . $token, $session, self::SESSION_TTL);
        }

        $request = $request->withSession($session);
        return $next($request);
    }

    public function token(Request $request): string {
        $bearer = $request->bearer();
        if ($bearer !== '') {
            return $bearer;
        }
        return (string)($request->cookies[self::COOKIE] ?? '');
    }

    public function createSession(array $user): string {
        $token = bin2hex(random_bytes(24));
        $this->store->set($this->prefix . $token, [
            'user'    => $user,
            'created' => time(),
            'expires' => time() + self::SESSION_TTL,
        ], self::SESSION_TTL);
        return $token;
    }

    public function destroySession(string $token): void {
        $this->store->del($this->prefix . $token);
    }
}
