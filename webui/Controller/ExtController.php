<?php
namespace Webui\Controller;

use Core\Bot\Registry;
use Core\Extension\ExtensionPoint;
use Core\Http\Request;
use Core\Http\Response;

/**
 * 插件后台页面的统一入口。
 *
 * 插件只声明「菜单 + 页面 schema + 若干接口」，具体长什么样由前端通用渲染器画，
 * 所以加一个后台功能插件不需要改前端、不需要重新构建。
 */
final class ExtController extends Base
{
    private function registry(): Registry
    {
        return \Core\App::boot()->registry;
    }

    /** 所有插件后台菜单（侧边栏用） */
    public function pages(Request $request): Response
    {
        $menus = ExtensionPoint::adminMenus($this->registry());
        return $this->ok(['items' => $menus]);
    }

    /** 注入到已有页面的小块（概览、账号页等），前端按 slot 取 */
    public function widgets(Request $request): Response
    {
        $slot = (string)$request->input('slot', '');
        return $this->ok([
            'slot'  => $slot,
            'items' => ExtensionPoint::widgets($this->registry(), $slot),
        ]);
    }

    /** 插件自带的前端脚本清单：前端按顺序 import，再调用模块导出的 setup(ctx) */
    public function scripts(Request $request): Response
    {
        $items = [];
        foreach (ExtensionPoint::scripts($this->registry()) as $item) {
            $plugin = $this->registry()->plugin($item['plugin']) ?? [];
            $items[] = $item + [
                'name'  => (string)($plugin['name'] ?? ExtensionPoint::shortClass($item['plugin'])),
                'title' => (string)($plugin['displayName'] ?? ''),
            ];
        }
        return $this->ok(['items' => $items]);
    }

    /**
     * 插件静态资源：app/Plugins/assets/<插件>/<文件>。
     *
     * 为什么是公开的：前端用动态 import() 加载 ES module，浏览器不会带
     * Authorization 头，只能走同源公开 URL。这里只放行启用插件目录下的
     * 白名单后缀，且 realpath 必须落在该目录内 —— 不是通用的文件服务器。
     */
    public function asset(Request $request, array $params): Response
    {
        $plugin = (string)($params['plugin'] ?? '');
        $file = (string)($params['file'] ?? '');
        if ($plugin === '' || $file === '' || str_contains($file, '/') || str_contains($file, '\\')) {
            return Response::text('not found', 404);
        }
        $allowed = [
            'js'   => 'text/javascript; charset=utf-8',
            'mjs'  => 'text/javascript; charset=utf-8',
            'css'  => 'text/css; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'map'  => 'application/json; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            'gif'  => 'image/gif',
            'woff2' => 'font/woff2',
            'woff' => 'font/woff',
        ];
        $ext = strtolower((string)pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            return Response::text('forbidden file type', 403);
        }
        // 只允许启用插件：避免为停用插件继续提供可执行脚本
        $class = null;
        foreach ($this->registry()->plugins() as $candidate) {
            if (strcasecmp(ExtensionPoint::shortClass((string)($candidate['class'] ?? '')), $plugin) === 0) {
                if (!empty($candidate['enabled'])) {
                    $class = (string)$candidate['class'];
                }
                break;
            }
        }
        if ($class === null) {
            return Response::text('plugin not found', 404);
        }
        $root = defined('__root__') ? __root__ : dirname(__DIR__, 3);
        $baseDir = realpath($root . '/app/Plugins/assets/' . ExtensionPoint::shortClass($class));
        $real = $baseDir === false ? false : realpath($baseDir . '/' . $file);
        if ($real === false || !str_starts_with($real, $baseDir . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return Response::text('not found', 404);
        }
        return new Response(200, [
            'Content-Type'           => $allowed[$ext],
            'Cache-Control'          => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ], (string)@file_get_contents($real));
    }

    /** 扩展点自检：钩子、注入块、命令、中间件都有哪些 */
    public function diag(Request $request): Response
    {
        return $this->ok(ExtensionPoint::diag($this->registry()));
    }

    /** 某个插件的页面 schema */
    public function pageSchema(Request $request, array $params): Response
    {
        $class = $this->resolve((string)($params['plugin'] ?? ''));
        if ($class === null) {
            return $this->fail('没有这个插件后台页面', -1, 404);
        }
        $schema = ExtensionPoint::adminPage($class);
        if ($schema === null) {
            return $this->fail('这个插件没有提供后台页面', -1, 404);
        }
        $plugin = $this->registry()->plugin($class) ?? [];
        return $this->ok([
            'plugin' => $class,
            'name'   => $plugin['name'] ?? $class,
            'title'  => $schema['title'] ?? ($plugin['displayName'] ?? $class),
            'blocks' => $schema['blocks'] ?? [],
        ]);
    }

    /**
     * 调用插件暴露的接口。
     * 请求体：{ path: "/hooks", method: "GET|POST", params: {...} }
     */
    public function call(Request $request, array $params): Response
    {
        $class = $this->resolve((string)($params['plugin'] ?? ''));
        if ($class === null) {
            return $this->fail('没有这个插件页面', -1, 404);
        }
        $path = (string)$request->input('path', '');
        $method = strtoupper((string)$request->input('method', 'GET'));
        if ($method !== 'GET') {
            // 插件接口里可能有写操作，统一要求 settings.write
            if ($deny = $this->guard($request, 'settings.write')) {
                return $deny;
            }
        }
        $input = $request->input('params', []);
        $result = ExtensionPoint::callRoute($class, $method, $path, is_array($input) ? $input : []);
        if (!$result['ok']) {
            return $this->fail((string)$result['msg'], -1, (int)($result['status'] ?? 400));
        }
        return $this->ok($result['data']);
    }

    /** 插件名 / 短名 / 完整类名 三种写法都认 */
    private function resolve(string $name): ?string
    {
        if ($name === '') {
            return null;
        }
        foreach ($this->registry()->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '') {
                continue;
            }
            if (strcasecmp($class, $name) === 0 || strcasecmp((string)($plugin['name'] ?? ''), $name) === 0) {
                return $class;
            }
            if (strcasecmp(basename(str_replace('\\', '/', $class)), $name) === 0) {
                return $class;
            }
        }
        return null;
    }
}