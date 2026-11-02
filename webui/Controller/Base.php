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
