<?php
namespace Core\Rss;

/**
 * RSS/Atom 订阅源与缓存条目。
 *
 * feeds  → runtime/rss.json       订阅源、轮询间隔、上次结果
 * items  → runtime/rss-items.json 最近抓到的条目（按 feed 保留，最多 500 条）
 */
final class FeedStore
{
    private const MAX_ITEMS = 500;

    private static ?array $feeds = null;

    public static function path(): string
    {
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__, 2) . '/runtime';
        return $root . '/rss.json';
    }

    public static function itemsPath(): string
    {
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__, 2) . '/runtime';
        return $root . '/rss-items.json';
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$feeds !== null) {
            return self::$feeds;
        }
        $file = self::path();
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded) && is_array($decoded['feeds'] ?? null)) {
                return self::$feeds = $decoded['feeds'];
            }
        }
        return self::$feeds = [];
    }

    public static function settings(): array
    {
        $file = self::path();
        $decoded = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        return [
            'interval' => (int)($decoded['interval'] ?? 900),
            'max_items' => (int)($decoded['max_items'] ?? 20),
        ];
    }

    public static function setInterval(int $seconds): void
    {
        $seconds = max(120, min(86400, $seconds));
        $file = self::path();
        $decoded = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded['interval'] = $seconds;
        $decoded['feeds'] = self::all();
        \Core\Fs::write($file, (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    /** @param array<string, mixed> $row */
    public static function save(array $row): array
    {
        $id = trim((string)($row['id'] ?? ''));
        $url = trim((string)($row['url'] ?? ''));
        if (!preg_match('~^https?://~i', $url)) {
            return ['ok' => false, 'msg' => '订阅地址必须以 http(s):// 开头'];
        }
        if ($id === '') {
            $id = 'feed-' . substr(sha1($url . microtime(true)), 0, 8);
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{3,64}$/', $id)) {
            return ['ok' => false, 'msg' => 'id 只能用字母、数字、下划线和横线，长度 3-64'];
        }
        $feeds = self::all();
        $current = $feeds[$id] ?? [];
        $feeds[$id] = [
            'id'          => $id,
            'title'       => (string)($row['title'] ?? ($current['title'] ?? '')),
            'url'         => $url,
            'group'       => (string)($row['group'] ?? ($current['group'] ?? '')),
            'enabled'     => array_key_exists('enabled', $row) ? (bool)$row['enabled'] : (bool)($current['enabled'] ?? true),
            'created_at'  => (int)($current['created_at'] ?? time()),
            'updated_at'  => time(),
            'last_poll'   => (int)($current['last_poll'] ?? 0),
            'last_status' => (string)($current['last_status'] ?? ''),
            'last_count'  => (int)($current['last_count'] ?? 0),
            'last_error'  => (string)($current['last_error'] ?? ''),
            'etag'        => (string)($current['etag'] ?? ''),
            'modified'    => (string)($current['modified'] ?? ''),
            'seen'        => is_array($current['seen'] ?? null) ? $current['seen'] : [],
        ];
        self::$feeds = $feeds;
        self::flush();
        return ['ok' => true, 'msg' => '已保存', 'data' => $feeds[$id]];
    }

    public static function remove(string $id): bool
    {
        $feeds = self::all();
        if (!isset($feeds[$id])) {
            return false;
        }
        unset($feeds[$id]);
        self::$feeds = $feeds;
        self::flush();
        return true;
    }

    /** 记录一次轮询结果 */
    public static function touch(string $id, string $status, int $count, string $error = '', array $headers = []): void
    {
        $feeds = self::all();
        if (!isset($feeds[$id])) {
            return;
        }
        $feeds[$id]['last_poll'] = time();
        $feeds[$id]['last_status'] = $status;
        $feeds[$id]['last_count'] = $count;
        $feeds[$id]['last_error'] = $error;
        if (isset($headers['etag'])) {
            $feeds[$id]['etag'] = (string)$headers['etag'];
        }
        if (isset($headers['last-modified'])) {
            $feeds[$id]['modified'] = (string)$headers['last-modified'];
        }
        self::$feeds = $feeds;
        self::flush();
    }

    /** 记住已推送过的条目 id（每个 feed 保留 300 个） */
    public static function markSeen(string $id, array $ids): void
    {
        $feeds = self::all();
        if (!isset($feeds[$id])) {
            return;
        }
        $seen = array_values(array_unique(array_merge($ids, (array)($feeds[$id]['seen'] ?? []))));
        $feeds[$id]['seen'] = array_slice($seen, 0, 300);
        self::$feeds = $feeds;
        self::flush();
    }

    /** 写入抓到的条目（去重，保留最近 N 条） */
    public static function addItems(array $items): void
    {
        if ($items === []) {
            return;
        }
        $file = self::itemsPath();
        $decoded = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $current = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        $seen = [];
        foreach ($current as $row) {
            $seen[(string)($row['uid'] ?? '')] = true;
        }
        foreach ($items as $item) {
            $uid = (string)($item['uid'] ?? '');
            if ($uid === '' || isset($seen[$uid])) {
                continue;
            }
            $seen[$uid] = true;
            $current[] = $item;
        }
        usort($current, static fn(array $a, array $b): int => ((int)($b['published'] ?? 0)) <=> ((int)($a['published'] ?? 0)));
        $current = array_slice($current, 0, self::MAX_ITEMS);
        \Core\Fs::write($file, (string)json_encode(['items' => $current], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, array> */
    public static function items(string $feedId = '', int $limit = 50): array
    {
        $file = self::itemsPath();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        if ($feedId !== '') {
            $items = array_values(array_filter($items, static fn(array $row): bool => ($row['feed'] ?? '') === $feedId));
        }
        return array_slice($items, 0, $limit);
    }

    public static function flush(): bool
    {
        $file = self::path();
        $decoded = is_file($file) ? json_decode((string)@file_get_contents($file), true) : [];
        if (!is_array($decoded)) {
            $decoded = [];
        }
        $decoded['feeds'] = self::all();
        $decoded['interval'] = $decoded['interval'] ?? 900;
        return \Core\Fs::write($file, (string)json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    public static function reset(?array $feeds = null): void
    {
        self::$feeds = $feeds;
    }
}
