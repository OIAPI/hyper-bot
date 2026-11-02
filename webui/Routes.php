<?php
namespace Webui;

use Core\App;
use Core\Config;
use Core\Http\Middleware\AuthMiddleware;
use Core\Http\Kernel;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Webui\Controller\AuthController;
use Webui\Controller\GroupsController;
use Webui\Controller\LogsController;
use Webui\Controller\OverviewController;
use Webui\Controller\PluginsController;
use Webui\Controller\ProtocolsController;
use Webui\Controller\SettingsController;
use Webui\Controller\SystemController;

/**
 * WebUI 路由装配。
 *
 * 全部挂在 `/webui/api` 下，静态资源在 `/webui/`（由 public/webui 提供）。
 * 公开端点只有 login / status，其余一律过 AuthMiddleware——
 * 这是「默认拒绝」而不是「默认允许」，新增接口忘了加鉴权也不会裸奔。
 */
final class Routes {
    public static function register(Router $router, AuthMiddleware $auth): void
    {
        $router->group('/webui/api', function (Router $r) use ($auth): void {
            $authCtl = new AuthController($auth);
            $overview = new OverviewController();
            $groups   = new GroupsController();
            $plugins  = new PluginsController();
            $protocols= new ProtocolsController();
            $logs     = new LogsController();
            $settings = new SettingsController();
            $system   = new SystemController();

            // ---- 公开（无需登录） ----
            $r->post('/auth/login', [$authCtl, 'login'], 'auth.login');
            $r->get('/auth/status', [$authCtl, 'status'], 'auth.status');
            $r->get('/health', [$system, 'health'], 'system.health');

            // ---- 需要登录 ----
            $r->group('', function (Router $r) use ($authCtl, $overview, $groups, $plugins, $protocols, $logs, $settings, $system): void {
                // 账号
                $r->post('/auth/logout', [$authCtl, 'logout'], 'auth.logout');
                $r->get('/auth/me', [$authCtl, 'me'], 'auth.me');
                $r->post('/auth/password', [$authCtl, 'changePassword'], 'auth.password');

                // 概览
                $r->get('/overview', [$overview, 'index'], 'overview.index');
                $r->get('/overview/counts', [$overview, 'counts'], 'overview.counts');
                $r->get('/overview/protocols', [$overview, 'protocols'], 'overview.protocols');
                $r->get('/overview/events', [$overview, 'events'], 'overview.events');

                // 群
                $r->get('/groups', [$groups, 'index'], 'groups.index');
                $r->post('/groups', [$groups, 'create'], 'groups.create');
                $r->get('/groups/{group_id}', [$groups, 'show'], 'groups.show');
                $r->patch('/groups/{group_id}/meta', [$groups, 'updateMeta'], 'groups.meta');
                $r->post('/groups/{group_id}/status', [$groups, 'toggleStatus'], 'groups.status');
                $r->post('/groups/{group_id}/plugins/{plugin}', [$groups, 'togglePlugin'], 'groups.plugin');
                $r->delete('/groups/{group_id}', [$groups, 'destroy'], 'groups.destroy');

                // 插件
                $r->get('/plugins', [$plugins, 'index'], 'plugins.index');
                $r->post('/plugins/reload', [$plugins, 'reload'], 'plugins.reload');
                $r->get('/plugins/{plugin}', [$plugins, 'show'], 'plugins.show');
                $r->post('/groups/{group_id}/plugins', [$plugins, 'bulkToggle'], 'plugins.bulk');

                // 协议
                $r->get('/protocols', [$protocols, 'index'], 'protocols.index');
                $r->get('/protocols/{protocol}', [$protocols, 'show'], 'protocols.show');
                $r->put('/protocols/{protocol}', [$protocols, 'update'], 'protocols.update');
                $r->get('/protocols/{protocol}/actions', [$protocols, 'actions'], 'protocols.actions');
                $r->get('/protocols/{protocol}/endpoints', [$protocols, 'endpoints'], 'protocols.endpoints');

                // 日志
                $r->get('/logs/tail', [$logs, 'tail'], 'logs.tail');
                $r->get('/logs/dates', [$logs, 'dates'], 'logs.dates');
                $r->get('/logs/{date}', [$logs, 'day'], 'logs.day');
                $r->get('/messages', [$logs, 'messages'], 'logs.messages');

                // 配置
                $r->get('/settings', [$settings, 'index'], 'settings.index');
                $r->get('/settings/raw', [$settings, 'raw'], 'settings.raw');
                $r->post('/settings/reload', [$settings, 'reload'], 'settings.reload');
                $r->put('/settings', [$settings, 'update'], 'settings.update');

                // 系统
                $r->get('/system/disk', [$system, 'disk'], 'system.disk');
                $r->get('/system/routes', [$system, 'routes'], 'system.routes');
                $r->post('/system/cache', [$system, 'cacheClear'], 'system.cache');
            }, ['webui.auth']);
        });
    }

    /**
     * 构建一个可直接处理请求的内核（Swoole 与 CLI 共用）。
     * @param \Closure|null $fallback 未匹配时不走 404，而是交给旧逻辑
     */
    public static function kernel(?\Closure $fallback = null): Kernel {
        $app = App::boot();
        $auth = new AuthMiddleware($app->store);
        $app->router->middleware('webui.auth', fn($req, $next) => $auth->process($req, $next));
        self::register($app->router, $auth);

        return new Kernel(
            $app->router,
            $app->logger,
            $fallback,
            fn(\Throwable $e, Request $req): Response => Response::fail(
                Config::bool('app.debug', false) ? $e->getMessage() : '服务器内部错误',
                -1,
                500
            )
        );
    }
}
