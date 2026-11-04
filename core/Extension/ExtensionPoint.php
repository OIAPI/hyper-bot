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
    /** 进程启动时调一次：可以注册服务、挂钩子、装中间件 */
    public const BOOT = 'boot';
    /** hooks(): [ 'http.response' => fn($value, ...$args) => $value, ... ] */
    public const HOOKS = 'hooks';
    /** middleware(): [ fn(Request $r, Response $res): Response, ... ] */
    public const MIDDLEWARE = 'middleware';
    /** adminWidgets(): [{slot, title, blocks}] 注入到已有页面 */
    public const WIDGETS = 'adminWidgets';
    /** commands(): [name => ['desc' => '', 'handler' => fn(array $args)] ] */
    public const COMMANDS = 'commands';
    /** requestMiddleware(): [ fn(Request $r, callable $next): Response, ... ] —— 进来就拦截 */
    public const REQUEST_MIDDLEWARE = 'requestMiddleware';
    /** adminScripts(): ['app.js', 'style.css'] —— 前端 ES module 注入 */
    public const SCRIPTS = 'adminScripts';

    /** 已执行过 boot 的插件类 */
    private static array $booted = [];
    private static bool $hooksRegistered = false;

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

    /**
     * 进程内初始化：boot() 一次 + 注册 hooks。
     * 每个请求都会走到这里，用 static 标记避免重复注册。
     */
    public static function boot(Registry $registry): void
    {
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled'])) {
                continue;
            }
            if (isset(self::$booted[$class])) {
                continue;
            }
            self::$booted[$class] = true;
            if (self::has($class, self::BOOT)) {
                try {
                    $class::boot();
                } catch (\Throwable $e) {
                    \Utils\Logger::warning('[extension] 插件 boot 失败 | ' . $class . ' | ' . $e->getMessage());
                }
            }
        }
        if (self::$hooksRegistered) {
            return;
        }
        self::$hooksRegistered = true;
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::HOOKS)) {
                continue;
            }
            try {
                $hooks = $class::hooks();
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[extension] 读取 hooks 失败 | ' . $class . ' | ' . $e->getMessage());
                continue;
            }
            foreach ((array)$hooks as $name => $handler) {
                $priority = 10;
                if (is_array($handler) && isset($handler['handler'])) {
                    $priority = (int)($handler['priority'] ?? 10);
                    $handler = $handler['handler'];
                }
                if (!is_callable($handler)) {
                    continue;
                }
                Hooks::on((string)$name, $handler, $priority, $class);
            }
        }
    }

    /**
     * 插件提供的响应过滤器（在 Kernel 出结果之后依次执行）。
     * @return array<int, callable>
     */
    public static function middlewares(Registry $registry): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::MIDDLEWARE)) {
                continue;
            }
            try {
                foreach ((array)$class::middleware() as $fn) {
                    if (is_callable($fn)) {
                        $out[] = $fn;
                    }
                }
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[extension] 读取 middleware 失败 | ' . $class . ' | ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * 插件提供的「请求中间件」：ThinkPHP 风格，fn(Request $req, callable $next): Response。
     *
     * 和 middleware() 的区别：
     *   middleware()         拿到的是已经生成的 Response，只能改结果（响应过滤器）；
     *   requestMiddleware()  拿到的是进来的 Request，可以鉴权、限流、短路、改请求，
     *                        也可以调用 $next($req) 再包一层结果 —— 这才是中间件。
     * @return array<int, callable>
     */
    public static function requestMiddlewares(Registry $registry): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::REQUEST_MIDDLEWARE)) {
                continue;
            }
            try {
                foreach ((array)$class::requestMiddleware() as $fn) {
                    if (is_callable($fn)) {
                        $out[] = $fn;
                    }
                }
            } catch (\Throwable $e) {
                \Utils\Logger::warning('[extension] 读取 requestMiddleware 失败 | ' . $class . ' | ' . $e->getMessage());
            }
        }
        return $out;
    }

    /**
     * 插件自带的前端脚本（ES module / CSS），由 /webui/plugin-assets/<插件>/<文件> 提供。
     * 返回 [{plugin, file, type, url}]，前端按顺序 import，调用模块导出的 setup(ctx)。
     * @return array<int, array{plugin:string, file:string, type:string, url:string}>
     */
    public static function scripts(Registry $registry): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::SCRIPTS)) {
                continue;
            }
            try {
                $items = (array)$class::adminScripts();
            } catch (\Throwable) {
                continue;
            }
            // 允许 ['js' => [...], 'css' => [...]] 与 ['app.js', 'style.css'] 两种写法
            $files = [];
            if (isset($items['js']) || isset($items['css'])) {
                foreach ((array)($items['js'] ?? []) as $file) {
                    $files[] = [(string)$file, 'js'];
                }
                foreach ((array)($items['css'] ?? []) as $file) {
                    $files[] = [(string)$file, 'css'];
                }
            } else {
                foreach ($items as $file) {
                    $file = (string)$file;
                    $files[] = [$file, str_ends_with(strtolower($file), '.css') ? 'css' : 'js'];
                }
            }
            $short = self::shortClass($class);
            foreach ($files as [$file, $type]) {
                $file = ltrim(str_replace('..', '', $file), '/');
                if ($file === '') {
                    continue;
                }
                $out[] = [
                    'plugin' => $class,
                    'file'   => $file,
                    'type'   => $type,
                    'url'    => '/webui/plugin-assets/' . rawurlencode($short) . '/' . implode('/', array_map('rawurlencode', explode('/', $file))),
                ];
            }
        }
        return $out;
    }

    /** 插件短名（app/Plugins/assets 下的目录名） */
    public static function shortClass(string $class): string
    {
        $pos = strrpos($class, '\\');
        return $pos === false ? $class : substr($class, $pos + 1);
    }

    /**
     * 注入到已有页面的小块（按 slot 取）。
     * @return array<int, array{plugin:string, slot:string, title:string, blocks:array}>
     */
    public static function widgets(Registry $registry, string $slot = ''): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::WIDGETS)) {
                continue;
            }
            try {
                $items = $class::adminWidgets();
            } catch (\Throwable) {
                continue;
            }
            if (is_array($items) && isset($items['title'])) {
                $items = [$items];
            }
            foreach ((array)$items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemSlot = (string)($item['slot'] ?? 'overview');
                if ($slot !== '' && $itemSlot !== $slot) {
                    continue;
                }
                $out[] = [
                    'plugin' => $class,
                    'slot'   => $itemSlot,
                    'title'  => (string)($item['title'] ?? ''),
                    'blocks' => (array)($item['blocks'] ?? []),
                ];
            }
        }
        return $out;
    }

    /**
     * 插件注册的命令行命令（bin/hyper ext 里可执行）。
     * @return array<string, array{plugin:string, desc:string, handler:callable}>
     */
    public static function commands(Registry $registry): array
    {
        $out = [];
        foreach ($registry->plugins() as $plugin) {
            $class = (string)($plugin['class'] ?? '');
            if ($class === '' || empty($plugin['enabled']) || !self::has($class, self::COMMANDS)) {
                continue;
            }
            try {
                $items = $class::commands();
            } catch (\Throwable) {
                continue;
            }
            foreach ((array)$items as $name => $meta) {
                $handler = is_array($meta) ? ($meta['handler'] ?? null) : $meta;
                if (!is_callable($handler)) {
                    continue;
                }
                $out[(string)$name] = [
                    'plugin'  => $class,
                    'desc'    => (string)(is_array($meta) ? ($meta['desc'] ?? '') : ''),
                    'handler' => $handler,
                ];
            }
        }
        return $out;
    }

    /** 测试用：清掉「已 boot / 已注册钩子」的标记，让 boot() 重新跑一遍 */
    public static function resetRegistration(): void
    {
        self::$booted = [];
        self::$hooksRegistered = false;
    }

    /** 诊断：钩子、中间件、注入块、命令的概况 */
    public static function diag(Registry $registry): array
    {
        return [
            'plugins'     => count(array_filter($registry->plugins(), static fn(array $p): bool => !empty($p['enabled']))),
            'menus'       => count(self::adminMenus($registry)),
            'hooks'       => Hooks::summary(),
            'middlewares' => count(self::middlewares($registry)),
            'request_middlewares' => count(self::requestMiddlewares($registry)),
            'scripts'     => count(self::scripts($registry)),
            'widgets'     => count(self::widgets($registry)),
            'commands'    => array_keys(self::commands($registry)),
        ];
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