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
use Webui\Controller\RuntimeController;
use Webui\Controller\SettingsController;
use Webui\Controller\SubscriptionController;
use Webui\Controller\UsersController;
use Webui\Controller\SystemController;

/**
 * WebUI 路由装配。
 *
 * 全部挂在 `/webui/api` 下，静态资源在 `/webui/`（由 public/webui 提供）。
 * 公开端点只有 login / status，其余一律过 AuthMiddleware——
 * 这是「默认拒绝」而不是「默认允许」，新增接口忘了加鉴权也不会裸奔。
 */
final class Routes {
    /** 已注册过的路由实例（WeakMap 语义用 spl_object_id 实现） */
    private static array $registered = [];

    public static function register(Router $router, AuthMiddleware $auth): void
    {
        // 每个请求都会调 kernel()，若重复注册会让路由表无限增长并拖慢匹配，
        // 这里按路由实例去重（同一 Router 只装配一次）。
        $id = spl_object_id($router);
        if (isset(self::$registered[$id])) {
            return;
        }
        self::$registered[$id] = true;

        $router->group('/webui/api', function (Router $r) use ($auth): void {
            $authCtl = new AuthController($auth);
            $overview = new OverviewController();
            $groups   = new GroupsController();
            $plugins  = new PluginsController();
            $protocols= new ProtocolsController();
            $logs     = new LogsController();
            $settings = new SettingsController();
            $system   = new SystemController();
            $runtime  = new RuntimeController();
            $subscription = new SubscriptionController();
            $users    = new UsersController();

            // ---- 公开（无需登录） ----
            $r->post('/auth/login', [$authCtl, 'login'], 'auth.login');
            $r->get('/auth/status', [$authCtl, 'status'], 'auth.status');
            $r->get('/health', [$system, 'health'], 'system.health');

            // ---- 需要登录 ----
            $r->group('', function (Router $r) use ($authCtl, $overview, $groups, $plugins, $protocols, $logs, $settings, $system, $runtime, $subscription, $users): void {
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
                // 导入：zip 走 raw body（见 PluginsController::zipPayload），git 走 JSON
                $r->post('/plugins/import/zip', [$plugins, 'importZip'], 'plugins.importZip');
                $r->post('/plugins/import/git', [$plugins, 'importGit'], 'plugins.importGit');

                // 账号管理（仅主人）
                $r->get('/users', [$users, 'index'], 'users.index');
                $r->post('/users', [$users, 'create'], 'users.create');
                $r->patch('/users/{username}', [$users, 'update'], 'users.update');
                $r->delete('/users/{username}', [$users, 'destroy'], 'users.destroy');
                $r->post('/users/{username}/password', [$users, 'resetPassword'], 'users.resetPassword');
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

                // 运行架构（v2）
                $r->get('/runtime', [$runtime, 'index'], 'runtime.index');
                $r->put('/runtime', [$runtime, 'update'], 'runtime.update');

                // 消息订阅（v2）
                $r->get('/subscription', [$subscription, 'index'], 'subscription.index');
                $r->put('/subscription', [$subscription, 'update'], 'subscription.update');
                $r->get('/subscription/explain', [$subscription, 'explain'], 'subscription.explain');
                $r->get('/subscription/catalog', [$subscription, 'catalog'], 'subscription.catalog');
                $r->post('/subscription/catalog', [$subscription, 'addType'], 'subscription.catalog.add');
                $r->delete('/subscription/catalog/{type}', [$subscription, 'removeType'], 'subscription.catalog.remove');

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
        // 老数据里首个账号可能是 admin：保证系统始终有一个 owner
        \Webui\Auth::ensureOwner();
        $auth = new AuthMiddleware($app->store);
        $app->router->middleware('webui.auth', fn($req, $next) => $auth->process($req, $next));
        self::register($app->router, $auth);

        // v2：协议接入端点也挂到同一套内核路由上（不再走旧的注解路由）
        if (!isset(self::$registered['app-routes'])) {
            self::$registered['app-routes'] = true;
            // webui-only 架构不注册协议路由：让请求落到下面的 503 兜底，
            // 而不是命中路由后返回「accepted」（协议端会误判为成功）。
            \Core\Http\AppRoutes::register($app->router, $app->runtime->handlesEvents());
        }

        // WebUI-only 架构：协议端点显式拒绝，避免协议端误以为投递成功
        if (!$app->runtime->handlesEvents()) {
            $fallback = static function (Request $req): Response {
                if (str_starts_with($req->path, '/api/protocol/')) {
                    return Response::fail('当前运行架构为 webui-only，不接受协议事件（请切换为 swoole 或 php）', -1, 503);
                }
                return Response::fail('not found: ' . $req->path, -404, 404);
            };
        }

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
