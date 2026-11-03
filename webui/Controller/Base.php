<?php
namespace Webui\Controller;

use Core\Http\Request;
use Core\Http\Response;
use Core\Store\Store;
use Webui\Metrics;

/** 控制器基类：注入 Store / Metrics，并提供统一的返回约定。 */
abstract class Base {
    protected Store $store;
    protected Metrics $metrics;
    protected string $root;

    public function __construct(?Store $store = null, ?Metrics $metrics = null)
    {
        $this->root = defined('__root__') ? __root__ : dirname(__DIR__, 2);
        $this->store = $store ?? \Core\App::boot()->container->get(Store::class);
        $this->metrics = $metrics ?? new Metrics($this->store, $this->root);
    }


    /** 当前登录角色（未登录时按最低权限处理，绝不默认成 admin） */
    /**
     * 当前请求的角色。
     *
     * 不直接读 session 里的 role：会话是 12 小时的，主人在后台把某人降级后，
     * 对方的旧会话还会继续拿着 admin 权限。这里按 users.json 现查一次（有内存缓存，
     * 成本可忽略），撤销权限立刻生效。
     */
    protected function role(Request $request): string
    {
        $username = (string)($request->user()['username'] ?? '');
        foreach (\Webui\Auth::listUsers() as $user) {
            if ($user['username'] === $username) {
                return \Webui\Auth::normalizeRole((string)$user['role']);
            }
        }
        return 'viewer';
    }

    /**
     * 能力校验。返回 null 表示通过，返回 Response 表示应当直接把它作为响应返回。
     *
     * 用法：if ($deny = $this->guard($request, 'plugin.install')) { return $deny; }
     */
    protected function guard(Request $request, string $capability): ?Response
    {
        if (\Webui\Auth::can($this->role($request), $capability)) {
            return null;
        }
        return $this->fail('权限不足：需要 ' . $capability . ' 能力', -403, 403);
    }

    protected function ok(mixed $data = null, string $message = 'ok'): Response
    {
        return Response::ok($data, $message);
    }

    protected function fail(string $message, int $code = -1, int $status = 200): Response
    {
        return Response::fail($message, $code, $status);
    }

    /** 分页参数 */
    protected function page(Request $request): array
    {
        $page = max(1, (int)($request->input('page', 1)));
        $size = min(200, max(1, (int)($request->input('size', 20))));
        return [$page, $size, ($page - 1) * $size];
    }

    protected function paginate(array $items, int $page, int $size): array
    {
        return [
            'items' => array_slice($items, ($page - 1) * $size, $size),
            'total' => count($items),
            'page'  => $page,
            'size'  => $size,
            'pages' => (int)ceil(count($items) / $size),
        ];
    }
}