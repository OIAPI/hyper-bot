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