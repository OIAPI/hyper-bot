<?php
namespace App\Plugins;

use App\BaseApp;
use Core\Extension\ExtensionPoint;
use Core\Extension\Hooks;
use Core\Http\Response;

/**
 * 扩展点示例插件（把「万物皆插件」的四种能力都演示一遍）。
 *
 * @name demo
 * @description 插件扩展点示例：钩子 / 注入块 / 命令
 * @author hyper
 * @version 1.0
 */
class Demo extends BaseApp
{
    /**
     * 钩子：直接嵌进框架执行链。
     *
     * http.response 拿到的是已经生成好的 Response，可以改状态码、加响应头；
     * 这里给所有响应加一个标记头，用来确认钩子真的挂上了。
     *
     * @return array<string, callable>
     */
    public static function hooks(): array
    {
        return [
            'http.response' => static function (Response $response): Response {
                return $response->withHeader('X-Plugins', 'demo');
            },
            // 发消息之前改参数：这里只是示范，真用时可做统一前缀、替换敏感词
            'onebot.call' => static function (array $params, string $action): array {
                if ($action === 'send_group_msg' && !isset($params['auto_escape'])) {
                    $params['auto_escape'] = false;
                }
                return $params;
            },
            /**
             * 收到消息过滤（v3.2）：返回 null / false 这条事件就被丢弃。
             *
             * 这里演示「关键词拦截」：消息里带 [demo:drop] 的文本直接不落库、不分发，
             * 但仍然回 200 —— 否则协议端会一直重投。真实用途：去重、屏蔽刷屏、审计。
             */
            'message.received' => static function (array $event): ?array {
                $text = (string)($event['raw_message'] ?? '');
                if ($text !== '' && str_contains($text, '[demo:drop]')) {
                    return null;
                }
                return $event;
            },
            /**
             * 发送消息拦截（v3.2）：在真正交给协议端之前改参数或整条拦下。
             * 这里演示「敏感词拦截」：文本里带 [demo:block] 就不发，并给调用方一个明确回执。
             */
            'message.before_send' => static function (array $params, string $action): ?array {
                if (!str_starts_with($action, 'send_')) {
                    return $params;
                }
                $text = (string)($params['message'] ?? '');
                if (str_contains($text, '[demo:block]')) {
                    return null;
                }
                return $params;
            },
            // 消息真正发出去之后（成功才会触发）
            'message.sent' => static function (string $action, array $params, mixed $result): void {
                \Utils\Logger::debug('[demo] 已发送 ' . $action . ' → ' . json_encode($result, JSON_UNESCAPED_UNICODE));
            },
        ];
    }

    /**
     * ThinkPHP 风格请求中间件（v3.2）：进入路由之前执行，能改请求也能短路。
     *
     * 这里只做两件无害的事：记耗时、给所有面板响应加一个标记头。
     * 中间件抛错会被内核捕获成 500，不会把进程带崩。
     *
     * @return array<int, callable>
     */
    public static function requestMiddleware(): array
    {
        return [static function (\Core\Http\Request $request, callable $next): \Core\Http\Response {
            $started = microtime(true);
            $response = $next($request);
            $ms = (string)round((microtime(true) - $started) * 1000, 2);
            return $response
                ->withHeader('X-Kernel-Middleware', 'demo')
                ->withHeader('X-Kernel-Ms', $ms);
        }];
    }

    /**
     * 插件自带前端资源（v3.2）：文件放在 app/Plugins/assets/Demo/ 下，
     * 由 /webui/plugin-assets/Demo/panel.js 公开提供，面板启动时动态 import。
     *
     * @return array<int, string>
     */
    public static function adminScripts(): array
    {
        return ['panel.js'];
    }

    /** 注入到「概览」页：把当前挂载的钩子列出来 */
    public static function adminWidgets(): array
    {
        $rows = [];
        foreach (Hooks::summary() as $hook) {
            $rows[] = [
                'name'     => $hook['name'],
                'handlers' => (string)$hook['handlers'],
                'owners'   => implode('、', array_map(
                    static fn(string $class): string => (string)substr(strrchr($class, '\\') ?: $class, 1),
                    $hook['owners']
                )),
            ];
        }
        return [[
            'slot'  => 'overview',
            'title' => '插件钩子',
            'blocks' => [[
                'type'     => 'table',
                'endpoint' => '/hooks',
                'columns'  => [
                    ['key' => 'name', 'title' => '钩子', 'width' => 180],
                    ['key' => 'handlers', 'title' => '处理器', 'width' => 90],
                    ['key' => 'owners', 'title' => '来自', 'minWidth' => 160],
                ],
            ]],
        ]];
    }

    /** 命令行：php bin/hyper ext plugins.diag */
    public static function commands(): array
    {
        return [
            'plugins.diag' => [
                'desc'    => '打印扩展点概况（菜单/钩子/注入块/命令）',
                'handler' => static function (array $args): string {
                    $diag = ExtensionPoint::diag(\Core\App::boot()->registry);
                    return (string)json_encode($diag, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                },
            ],
        ];
    }

    /** 给注入块表格用的接口 */
    public static function extRoutes(): array
    {
        return [
            'GET /hooks' => static function (array $in): array {
                $rows = [];
                foreach (Hooks::summary() as $hook) {
                    $rows[] = [
                        'name'     => $hook['name'],
                        'handlers' => (string)$hook['handlers'],
                        'owners'   => implode('、', $hook['owners']),
                    ];
                }
                return ['items' => $rows];
            },
        ];
    }
}
