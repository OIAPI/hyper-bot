<?php
namespace App\Plugins;

use App\BaseApp;
use Core\Extension\ExtensionPoint;
use Core\Webhook\WebhookStore;

/**
 * GitHub 订阅（后台功能插件）。
 *
 * 两种能力：
 *   1. 后台页面：管理 WebHook 订阅、看最近投递（schema 驱动，前端无需为它改代码）
 *   2. 事件出口：收到 github.* 事件时，把摘要发到配置的 QQ 群
 */
final class Github extends BaseApp
{
    /** 后台菜单 */
    public static function adminMenu(): array
    {
        return [
            'title' => 'GitHub 订阅',
            'icon'  => 'git',
            'path'  => '/ext/github',
        ];
    }

    public static function adminPage(): array
    {
        return [
            'title' => 'GitHub 订阅',
            'blocks' => [
                [
                    'type' => 'text',
                    'text' => '在 GitHub 仓库 Settings → Webhooks 里添加：Payload URL = 本站地址 + /webhook/github/<订阅 id>，Content type 选 application/json，Secret 与下表一致。支持 push / issues / pull_request / release / star 等事件。',
                ],
                [
                    'type' => 'table',
                    'title' => '订阅列表',
                    'endpoint' => '/hooks',
                    'rowKey' => 'id',
                    'columns' => [
                        ['key' => 'id', 'title' => 'ID', 'width' => 140],
                        ['key' => 'note', 'title' => '备注', 'minWidth' => 160],
                        ['key' => 'events', 'title' => '事件', 'minWidth' => 150],
                        ['key' => 'group', 'title' => '通知群', 'width' => 130],
                        ['key' => 'last_text', 'title' => '最近投递', 'width' => 170],
                        ['key' => 'enabled', 'title' => '启用', 'width' => 80],
                    ],
                ],
                [
                    'type' => 'form',
                    'title' => '新增 / 修改订阅',
                    'endpoint' => '/hooks',
                    'method' => 'POST',
                    'submitLabel' => '保存',
                    'fields' => [
                        ['key' => 'id', 'label' => '订阅 ID', 'placeholder' => '留空自动生成，如 hyper-bot'],
                        ['key' => 'secret', 'label' => 'Secret', 'type' => 'password', 'placeholder' => 'GitHub 里填同一个值'],
                        ['key' => 'note', 'label' => '备注', 'placeholder' => 'OIAPI/hyper-bot'],
                        ['key' => 'events', 'label' => '事件', 'placeholder' => '逗号分隔，留空为全部，如 push,release'],
                        ['key' => 'group', 'label' => '通知到群', 'placeholder' => '群号，留空只记日志'],
                    ],
                ],
                [
                    'type' => 'actions',
                    'actions' => [
                        ['label' => '删除订阅（ID 见上表）', 'endpoint' => '/hooks', 'method' => 'DELETE', 'params' => ['id' => ''], 'prompt' => '要删除的订阅 ID'],
                    ],
                ],
                [
                    'type' => 'table',
                    'title' => '最近投递',
                    'endpoint' => '/deliveries',
                    'rowKey' => 'at',
                    'columns' => [
                        ['key' => 'time_text', 'title' => '时间', 'width' => 170],
                        ['key' => 'hook', 'title' => '订阅', 'width' => 130],
                        ['key' => 'event', 'title' => '事件', 'width' => 150],
                        ['key' => 'summary', 'title' => '内容', 'minWidth' => 260],
                        ['key' => 'ok', 'title' => '结果', 'width' => 80],
                    ],
                ],
            ],
        ];
    }

    /** 插件自己的接口，ExtController 会转发到这里 */
    public static function extRoutes(): array
    {
        return [
            'GET /hooks' => static function (array $in): array {
                $items = [];
                foreach (WebhookStore::all() as $id => $hook) {
                    $items[] = [
                        'id'        => $id,
                        'note'      => (string)($hook['note'] ?? ''),
                        'events'    => implode(',', (array)($hook['events'] ?? ['*'])),
                        'group'     => (string)($hook['group'] ?? ''),
                        'enabled'   => !empty($hook['enabled']),
                        'secret_set' => (string)($hook['secret'] ?? '') !== '',
                        'last_text' => (int)($hook['last_at'] ?? 0) > 0
                            ? date('m-d H:i', (int)$hook['last_at']) . ' ' . (string)($hook['last_event'] ?? '')
                            : '从未',
                    ];
                }
                return ['items' => $items];
            },

            'POST /hooks' => static function (array $in): array {
                $result = WebhookStore::save([
                    'id'      => (string)($in['id'] ?? ''),
                    'secret'  => (string)($in['secret'] ?? ''),
                    'note'    => (string)($in['note'] ?? ''),
                    'events'  => (string)($in['events'] ?? ''),
                    'group'   => (string)($in['group'] ?? ''),
                ]);
                if (!$result['ok']) {
                    throw new \RuntimeException((string)$result['msg']);
                }
                return ['saved' => $result['data']['id'] ?? ''];
            },

            'DELETE /hooks' => static function (array $in): array {
                $id = (string)($in['id'] ?? '');
                if ($id === '' || !WebhookStore::remove($id)) {
                    throw new \RuntimeException('没有这个订阅：' . $id);
                }
                return ['deleted' => $id];
            },

            'GET /deliveries' => static function (array $in): array {
                $items = [];
                foreach (WebhookStore::deliveries('', 50) as $row) {
                    $items[] = $row + [
                        'time_text' => date('Y-m-d H:i:s', (int)($row['at'] ?? 0)),
                        'ok'        => !empty($row['ok']) ? '成功' : '失败',
                    ];
                }
                return ['items' => $items];
            },
        ];
    }

    /** 收到 github.* 事件：有配置群就推一条摘要 */
    public static function onExtension(string $topic, array $context): void
    {
        if (!str_starts_with($topic, 'github')) {
            return;
        }
        $payload = (array)($context['payload'] ?? []);
        $hook = WebhookStore::get((string)($context['hook'] ?? ''));
        $group = (string)($hook['group'] ?? '');
        if ($group === '' || !ctype_digit($group)) {
            return;
        }
        $summary = (string)($context['summary'] ?? '');
        if ($summary === '') {
            return;
        }
        try {
            $adapter = \Protocol\OneBot\Adapter::currentAdapter();
            $adapter->call('send_group_msg', [
                'group_id' => (int)$group,
                'message'  => '[GitHub] ' . $summary,
            ]);
        } catch (\Throwable $e) {
            \Utils\Logger::warning('[github] 推送到群失败：' . $e->getMessage());
        }
    }
}
