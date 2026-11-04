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
        ];
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
