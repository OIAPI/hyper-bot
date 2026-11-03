<?php
namespace Core\Http\Middleware;

use Core\Config;
use Core\Http\Middleware;
use Core\Http\Request;
use Core\Http\Response;
use Core\Store\FileStore;
use Core\Store\ResilientStore;
use Core\Store\Store;

/**
 * WebUI 会话鉴权。
 *
 * 设计要点（与历史 CQ 框架的「群管理后台」完全不同）：
 *  - 会话是**服务端**存储的，token 只存在 HttpOnly Cookie / Authorization 头里；
 *  - 登录态带过期时间且可主动吊销；
 *  - 所有写入操作必须经过同一个中间件，避免出现「某个接口忘了鉴权」。
 *
 * 会话默认**不放在 Redis**（webui.session_store=file）：Redis 一重启、一被 LRU 淘汰、
 * 一降级到文件存储，之前写进去的 token 就全查不到，用户表现为「莫名其妙被踢下线」。
 * 会话数据很小（就一条 user + 过期时间），放本地文件既不会丢也跨进程可见；
 * 需要多机共享会话时再改回 store。
 */
final class AuthMiddleware implements Middleware {
    public const COOKIE = 'wb_session';
    public const SESSION_TTL = 43200; // 12 小时

    private Store $store;
    private string $prefix;

    public function __construct(private Store $cacheStore, string $prefix = 'webui:session:') {
        $this->prefix = $prefix;
        $this->store = $this->resolveSessionStore($cacheStore);
    }

    /** 会话存储：默认独立文件目录，可配置 webui.session_store = file | store */
    private function resolveSessionStore(Store $cacheStore): Store {
        $mode = strtolower(trim(Config::string('webui.session_store', 'file')));
        if ($mode === 'store') {
            return $cacheStore;
        }
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__, 3) . '/runtime';
        return new FileStore($root . '/store/session');
    }

    /** 给 status 这类「不走中间件但要看会话」的地方用 */
    public function session(string $token): ?array {
        if ($token === '') {
            return null;
        }
        $session = $this->store->get($this->prefix . $token);
        if (!is_array($session) || ($session['expires'] ?? 0) < time()) {
            return null;
        }
        return $session;
    }

    public function process(Request $request, callable $next): Response {
        $token = $this->token($request);
        if ($token === '') {
            return Response::fail('未登录或会话已过期', -401, 401);
        }
        $session = $this->store->get($this->prefix . $token);
        if (!is_array($session)) {
            // 存储后端出问题时不能当成「会话失效」把用户踢到登录页，
            // 否则一次 Redis 抖动就会让所有人重新登录
            if ($this->store instanceof ResilientStore && $this->store->degraded()) {
                return Response::fail('存储后端异常（已临时降级），会话暂时无法校验，请稍后重试', -503, 503);
            }
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
