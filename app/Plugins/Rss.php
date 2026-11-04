<?php
namespace App\Plugins;

use App\BaseApp;
use Core\Rss\FeedStore;
use Core\Rss\Poller;

/**
 * RSS / Atom 订阅（后台功能插件）。
 *
 * 后台页面：订阅源增删改、立即抓取、看最近条目、改轮询间隔。
 * 事件出口：抓到新条目后，若订阅源填了群号，就推到那个群。
 */
final class Rss extends BaseApp
{
    public static function adminMenu(): array
    {
        return [
            'title' => 'RSS 订阅',
            'icon'  => 'rss',
            'path'  => '/ext/rss',
        ];
    }

    public static function adminPage(): array
    {
        return [
            'title' => 'RSS 订阅',
            'blocks' => [
                ['type' => 'text', 'text' => '支持 RSS 2.0 与 Atom。抓取由后台心跳驱动（与定时任务同一套），每轮最多抓 5 个源，抓到的条目按链接去重；填了群号的源会把新条目推到群里。'],
                [
                    'type' => 'table',
                    'title' => '订阅源',
                    'endpoint' => '/feeds',
                    'rowKey' => 'id',
                    'columns' => [
                        ['key' => 'id', 'title' => 'ID', 'width' => 130],
                        ['key' => 'title', 'title' => '名称', 'minWidth' => 160],
                        ['key' => 'url', 'title' => '地址', 'minWidth' => 220],
                        ['key' => 'group', 'title' => '推送群', 'width' => 130],
                        ['key' => 'last_text', 'title' => '最近抓取', 'width' => 170],
                        ['key' => 'enabled', 'title' => '启用', 'width' => 80],
                    ],
                ],
                [
                    'type' => 'form',
                    'title' => '新增 / 修改订阅源',
                    'endpoint' => '/feeds',
                    'method' => 'POST',
                    'submitLabel' => '保存',
                    'fields' => [
                        ['key' => 'url', 'label' => '订阅地址', 'placeholder' => 'https://example.com/feed.xml'],
                        ['key' => 'title', 'label' => '名称', 'placeholder' => '留空自动用源标题'],
                        ['key' => 'group', 'label' => '推送到群', 'placeholder' => '群号，留空只存不推'],
                        ['key' => 'id', 'label' => 'ID（修改已有源时填）', 'placeholder' => '留空新建'],
                    ],
                ],
                [
                    'type' => 'actions',
                    'actions' => [
                        ['label' => '立即抓取全部', 'endpoint' => '/poll', 'method' => 'POST'],
                        ['label' => '删除订阅源', 'endpoint' => '/feeds', 'method' => 'DELETE', 'params' => ['id' => ''], 'prompt' => '要删除的订阅源 ID'],
                    ],
                ],
                [
                    'type' => 'table',
                    'title' => '最近条目',
                    'endpoint' => '/items',
                    'rowKey' => 'uid',
                    'columns' => [
                        ['key' => 'time_text', 'title' => '时间', 'width' => 170],
                        ['key' => 'feed_title', 'title' => '来源', 'width' => 150],
                        ['key' => 'title', 'title' => '标题', 'minWidth' => 240],
                        ['key' => 'link', 'title' => '链接', 'minWidth' => 220],
                    ],
                ],
            ],
        ];
    }

    /** 注入到「概览」页：最新几条订阅内容 */
    public static function adminWidgets(): array
    {
        return [[
            'slot'  => 'overview',
            'title' => 'RSS 最新',
            'blocks' => [[
                'type'     => 'table',
                'endpoint' => '/items',
                'params'   => ['limit' => 5],
                'columns'  => [
                    ['key' => 'time_text', 'title' => '时间', 'width' => 140],
                    ['key' => 'feed_title', 'title' => '来源', 'width' => 140],
                    ['key' => 'title', 'title' => '标题', 'minWidth' => 240],
                ],
            ]],
        ]];
    }

    /** 命令行：php bin/hyper ext rss.poll */
    public static function commands(): array
    {
        return [
            'rss.poll' => [
                'desc'    => '立刻抓取所有到期的订阅源',
                'handler' => static function (array $args): string {
                    $result = Poller::pollDue(true);
                    return sprintf(
                        '抓取 %d 个源，新增 %d 条%s',
                        (int)($result['feeds'] ?? 0),
                        (int)($result['items'] ?? 0),
                        isset($result['error']) && $result['error'] !== '' ? '（' . $result['error'] . '）' : ''
                    );
                },
            ],
            'rss.items' => [
                'desc'    => '列出最近抓到的 10 条',
                'handler' => static function (array $args): string {
                    $items = FeedStore::items('', 10);
                    if ($items === []) {
                        return '还没有抓取到任何条目。先在面板里加一个订阅源。';
                    }
                    $lines = [];
                    foreach ($items as $item) {
                        $lines[] = sprintf('[%s] %s', (string)($item['feed_title'] ?? $item['feed'] ?? '-'), (string)($item['title'] ?? ''));
                    }
                    return implode(PHP_EOL, $lines);
                },
            ],
        ];
    }

    public static function extRoutes(): array
    {
        return [
            'GET /feeds' => static function (array $in): array {
                $items = [];
                foreach (FeedStore::all() as $id => $feed) {
                    $items[] = [
                        'id'        => $id,
                        'title'     => (string)($feed['title'] ?? ''),
                        'url'       => (string)($feed['url'] ?? ''),
                        'group'     => (string)($feed['group'] ?? ''),
                        'enabled'   => !empty($feed['enabled']),
                        'last_text' => (int)($feed['last_poll'] ?? 0) > 0
                            ? date('m-d H:i', (int)$feed['last_poll']) . ' ' . (string)($feed['last_status'] ?? '') . '（' . (int)($feed['last_count'] ?? 0) . ' 条）'
                            : '从未',
                    ];
                }
                return ['items' => $items, 'interval' => FeedStore::settings()['interval']];
            },

            'POST /feeds' => static function (array $in): array {
                $result = FeedStore::save([
                    'id'    => (string)($in['id'] ?? ''),
                    'url'   => (string)($in['url'] ?? ''),
                    'title' => (string)($in['title'] ?? ''),
                    'group' => (string)($in['group'] ?? ''),
                ]);
                if (!$result['ok']) {
                    throw new \RuntimeException((string)$result['msg']);
                }
                $id = (string)($result['data']['id'] ?? '');
                return ['saved' => $id, 'fetched' => Poller::pollOne($id)];
            },

            'DELETE /feeds' => static function (array $in): array {
                $id = (string)($in['id'] ?? '');
                if ($id === '' || !FeedStore::remove($id)) {
                    throw new \RuntimeException('没有这个订阅源：' . $id);
                }
                return ['deleted' => $id];
            },

            'GET /items' => static function (array $in): array {
                $items = [];
                foreach (FeedStore::items('', (int)($in['limit'] ?? 60)) as $row) {
                    $items[] = $row + [
                        'time_text' => (int)($row['published'] ?? 0) > 0 ? date('Y-m-d H:i', (int)$row['published']) : '',
                    ];
                }
                return ['items' => $items];
            },

            'POST /poll' => static function (array $in): array {
                return Poller::pollDue(true);
            },
        ];
    }

    /** 抓到新条目：源里配了群号就推送 */
    public static function onExtension(string $topic, array $context): void
    {
        if ($topic !== 'rss.item') {
            return;
        }
        $feedId = (string)($context['feed'] ?? '');
        $feed = FeedStore::get($feedId);
        $group = (string)($feed['group'] ?? '');
        if ($group === '' || !ctype_digit($group)) {
            return;
        }
        $settings = FeedStore::settings();
        $limit = max(1, min(10, (int)($settings['max_items'] ?? 5)));
        $lines = [];
        foreach (array_slice((array)($context['items'] ?? []), 0, $limit) as $item) {
            $lines[] = '· ' . (string)($item['title'] ?? '') . "\n" . (string)($item['link'] ?? '');
        }
        if ($lines === []) {
            return;
        }
        $title = (string)($feed['title'] ?? $feedId);
        $message = '【RSS】' . $title . "\n" . implode("\n", $lines);
        try {
            \Protocol\OneBot\Adapter::currentAdapter()->call('send_group_msg', [
                'group_id' => (int)$group,
                'message'  => $message,
            ]);
        } catch (\Throwable $e) {
            \Utils\Logger::warning('[rss] 推送到群失败：' . $e->getMessage());
        }
    }
}