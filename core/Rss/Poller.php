<?php
namespace Core\Rss;

use Core\Bot\Registry;
use Core\Extension\ExtensionPoint;
use Utils\Logger;

/**
 * RSS 轮询。
 *
 * 由 Runner::tick 周期调用（和定时任务共用同一条心跳），
 * 每个订阅源按自己的 last_poll 判断是否到期，浏览器打开面板不会额外触发抓取。
 */
final class Poller
{
    /** 单轮最多抓几个源，避免一次 tick 卡太久 */
    private const MAX_PER_TICK = 5;

    /** @return array{checked:int, added:int, errors:int, feeds:array} */
    public static function pollDue(bool $force = false): array
    {
        $settings = FeedStore::settings();
        $interval = max(120, (int)$settings['interval']);
        $now = time();
        $stats = ['checked' => 0, 'added' => 0, 'errors' => 0, 'feeds' => []];

        foreach (FeedStore::all() as $id => $feed) {
            if ($stats['checked'] >= self::MAX_PER_TICK) {
                break;
            }
            if (empty($feed['enabled'])) {
                continue;
            }
            if (!$force && (int)($feed['last_poll'] ?? 0) + $interval > $now) {
                continue;
            }
            $result = self::poll((string)$id, $force);
            $stats['checked']++;
            $stats['added'] += (int)($result['added'] ?? 0);
            if (empty($result['ok'])) {
                $stats['errors']++;
            }
            $stats['feeds'][$id] = $result;
        }
        return $stats;
    }

    /** @return array{ok:bool, added:int, items:int, error?:string} */
    public static function poll(string $id, bool $force = false): array
    {
        $feed = FeedStore::get($id);
        if ($feed === null) {
            return ['ok' => false, 'added' => 0, 'items' => 0, 'error' => '订阅源不存在'];
        }
        $result = Reader::fetch((string)$feed['url'], (string)($feed['etag'] ?? ''), (string)($feed['modified'] ?? ''));
        if (empty($result['ok'])) {
            FeedStore::touch($id, 'failed', 0, (string)($result['error'] ?? '抓取失败'), (array)($result['headers'] ?? []));
            Logger::warning('[rss] 抓取失败 | ' . $id . ' | ' . ($result['error'] ?? ''));
            return ['ok' => false, 'added' => 0, 'items' => 0, 'error' => (string)($result['error'] ?? '抓取失败')];
        }
        if (!empty($result['not_modified'])) {
            FeedStore::touch($id, 'not-modified', 0, '', (array)($result['headers'] ?? []));
            return ['ok' => true, 'added' => 0, 'items' => 0];
        }

        $items = (array)($result['items'] ?? []);
        $seen = array_flip((array)($feed['seen'] ?? []));
        $fresh = [];
        foreach ($items as $item) {
            if (!isset($seen[(string)$item['uid']])) {
                $fresh[] = $item;
            }
        }

        $maxItems = max(1, (int)FeedStore::settings()['max_items']);
        $fresh = array_slice($fresh, 0, $maxItems);
        $rows = [];
        foreach ($fresh as $item) {
            $rows[] = $item + [
                'feed'       => $id,
                'feed_title' => (string)($feed['title'] ?? ''),
                'fetched_at' => time(),
            ];
        }
        FeedStore::addItems($rows);
        FeedStore::markSeen($id, array_map(static fn(array $row): string => (string)$row['uid'], $fresh));

        if ($rows !== []) {
            self::emitNew((string)$id, $feed, $rows);
        }

        FeedStore::touch($id, $rows === [] ? 'ok-no-new' : 'ok', count($rows), '', (array)($result['headers'] ?? []));
        if ((string)($feed['title'] ?? '') === '' && (string)($result['title'] ?? '') !== '') {
            FeedStore::save(['id' => $id, 'title' => (string)$result['title'], 'url' => (string)$feed['url']] + $feed);
        }

        return ['ok' => true, 'added' => count($rows), 'items' => count($items)];
    }

    private static function emitNew(string $id, array $feed, array $rows): void
    {
        $registry = \Core\App::boot()->registry;
        $context = [
            'feed'       => $id,
            'feed_title' => (string)($feed['title'] ?? ''),
            'group'      => (string)($feed['group'] ?? ''),
            'items'      => $rows,
            'received_at' => time(),
        ];
        ExtensionPoint::emit($registry, 'rss.item', $context);
        ExtensionPoint::emit($registry, 'rss.batch', $context);
    }

    /** 供面板「立即抓取」用 */
    public static function pollOne(string $id): array
    {
        return self::poll($id, true);
    }
}
