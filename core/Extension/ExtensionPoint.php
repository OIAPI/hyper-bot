<?php
namespace Core\Extension;

use Core\Bot\Registry;

/**
 * 插件扩展点：让插件不止处理 QQ 事件，还能挂后台页面、对外 WebHook、定时任务。
 *
 * 约定（全部可选，插件类里有这个方法就会被发现）：
 *   adminMenu(): array          后台菜单项 [{title, icon, path}] 或单个
 *   adminPage(): array          后台页面描述 {title, blocks: [...]}（schema 驱动，前端通用渲染）
 *   extRoutes(): array          ['GET /items' => fn(array $in): array, ...]
 *   onExtension(string, array)  收到 WebHook / RSS 等外部事件
 *
 * 与 v1 注解不冲突：老插件只有 #[Rule]/#[Notice]/#[Request]，这些方法都没有，
 * 扫描时直接跳过。
 */
final class ExtensionPoint
{
    public const ADMIN_MENU = 'adminMenu';
    public const ADMIN_PAGE = 'adminPage';
    public const EXT_ROUTES = 'extRoutes';
    public const ON_EXTENSION = 'onExtension';

    /** 插件类是否实现了某个扩展点 */
    public static function has(string $class, string $method): bool
    {
        if (!class_exists($class)) {
            return false;
        }
        try {
            $ref = new \ReflectionClass($class);
            if (!$ref->hasMethod($method)) {
                return false;
            }
            $m = $ref->getMethod($method);
            return $m->isStatic() && $m->isPublic();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * 收集所有插件的后台菜单。
     * @return array<int, array{plugin:string, title:string, icon:string, path:string}>
     */
    public static function adminMenus(Registry $registry): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled'])) {
                continue;
            }
            if (!self::has($class, self::ADMIN_MENU)) {
                continue;
            }
            try {
                $items = $class::adminMenu();
            } catch (\Throwable) {
                continue;
            }
            // 插件可以返回单个菜单项，也可以返回多个
            if (is_array($items) && isset($items['title'])) {
                $items = [$items];
            }
            foreach ((array)$items as $item) {
                if (!is_array($item) || (string)($item['title'] ?? '') === '') {
                    continue;
                }
                $out[] = [
                    'plugin' => $class,
                    'title'  => (string)$item['title'],
                    'icon'   => (string)($item['icon'] ?? 'plugin'),
                    'path'   => (string)($item['path'] ?? ('/ext/' . strtolower($plugin['name'] ?? $class))),
                ];
            }
        }
        return $out;
    }

    /** 取某个插件的后台页面描述 */
    public static function adminPage(string $class): ?array
    {
        if (!self::has($class, self::ADMIN_PAGE)) {
            return null;
        }
        try {
            $page = $class::adminPage();
        } catch (\Throwable) {
            return null;
        }
        return is_array($page) ? $page : null;
    }

    /**
     * 调用插件的扩展路由。
     * @return array{ok:bool, data?:mixed, msg?:string, status?:int}
     */
    public static function callRoute(string $class, string $method, string $path, array $input): array
    {
        if (!self::has($class, self::EXT_ROUTES)) {
            return ['ok' => false, 'msg' => '插件没有提供扩展路由', 'status' => 404];
        }
        try {
            $routes = $class::extRoutes();
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '读取扩展路由失败：' . $e->getMessage(), 'status' => 500];
        }
        $key = strtoupper($method) . ' /' . ltrim($path, '/');
        $handler = $routes[$key] ?? null;
        if ($handler === null) {
            // 允许只写路径、方法不限
            $handler = $routes['/' . ltrim($path, '/')] ?? null;
        }
        if (!is_callable($handler)) {
            return ['ok' => false, 'msg' => '没有这个接口：' . $key, 'status' => 404];
        }
        try {
            $data = $handler($input);
        } catch (\Throwable $e) {
            return ['ok' => false, 'msg' => '插件接口异常：' . $e->getMessage(), 'status' => 500];
        }
        $write = is_array($handler) ? false : false;
        return ['ok' => true, 'data' => $data, 'status' => 200, 'write' => $write];
    }

    /** 把外部事件（github/rss…）投递给所有实现了 onExtension 的插件 */
    public static function emit(Registry $registry, string $topic, array $payload): int
    {
        $count = 0;
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::ON_EXTENSION)) {
                continue;
            }
            try {
                $class::onExtension($topic, $payload);
                $count++;
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[extension] 插件处理外部事件失败 | ' . $class . ' | ' . $topic . ' | ' . $e->getMessage());
            }
        }
        return $count;
    }
}