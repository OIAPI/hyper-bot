<?php
namespace Core\Webhook;

/**
 * 外部 WebHook 订阅（目前支持 GitHub）。
 *
 * 存在 runtime/webhooks.json，每条含 secret；投递记录单独存 runtime/webhook-deliveries.json，
 * 只保留最近 200 条，避免面板越用越慢。
 */
final class WebhookStore
{
    private const MAX_DELIVERIES = 200;

    private static ?array $cache = null;

    public static function path(): string
    {
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__, 2) . '/runtime';
        return $root . '/webhooks.json';
    }

    public static function deliveriesPath(): string
    {
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__, 2) . '/runtime';
        return $root . '/webhook-deliveries.json';
    }

    /** @return array<string, array> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $file = self::path();
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded) && is_array($decoded['hooks'] ?? null)) {
                return self::$cache = $decoded['hooks'];
            }
        }
        return self::$cache = [];
    }

    public static function get(string $id): ?array
    {
        $hooks = self::all();
        return $hooks[$id] ?? null;
    }

    /** @param array<string, mixed> $row */
    public static function save(array $row): array
    {
        $id = trim((string)($row['id'] ?? ''));
        if ($id === '') {
            $id = 'gh-' . bin2hex(random_bytes(4));
        }
        if (!preg_match('/^[a-zA-Z0-9_-]{3,64}$/', $id)) {
            return ['ok' => false, 'msg' => 'id 只能用字母、数字、下划线和横线，长度 3-64'];
        }
        $hooks = self::all();
        $current = $hooks[$id] ?? [];
        // 修改已有订阅时 secret 可以留空：沿用原值，避免只改模板还要重新填一次
        $secret = (string)($row['secret'] ?? '');
        if ($secret === '' && (string)($current['secret'] ?? '') !== '') {
            $secret = (string)$current['secret'];
        }
        if ($secret === '') {
            return ['ok' => false, 'msg' => 'secret 不能为空（GitHub 用它签名，必须填）'];
        }
        $events = $row['events'] ?? ['*'];
        if (is_string($events)) {
            $events = array_values(array_filter(array_map('trim', explode(',', $events)), static fn($v) => $v !== ''));
        }
        if (!is_array($events) || $events === []) {
            $events = ['*'];
        }
        // 多群通知：groups 是数组；老配置只有单数 group，这里做一次兼容迁移
        $groups = self::groups($row['groups'] ?? null);
        if ($groups === []) {
            $legacy = trim((string)($row['group'] ?? ''));
            if ($legacy !== '' && ctype_digit($legacy)) {
                $groups = [$legacy];
            }
        }
        if ($groups === [] && !array_key_exists('groups', $row)) {
            $groups = self::groups($current['groups'] ?? ($current['group'] ?? ''));
        }
        $template = array_key_exists('template', $row)
            ? (string)$row['template']
            : (string)($current['template'] ?? '');

        $hooks[$id] = [
            'id'          => $id,
            'kind'        => 'github',
            'note'        => (string)($row['note'] ?? ($current['note'] ?? '')),
            'secret'      => $secret,
            'groups'      => $groups,
            'group'       => (string)($groups[0] ?? ''),
            'template'    => $template,
            'events'      => $events,
            'enabled'     => array_key_exists('enabled', $row) ? (bool)$row['enabled'] : (bool)($current['enabled'] ?? true),
            'created_at'  => (int)($current['created_at'] ?? time()),
            'updated_at'  => time(),
            'last_at'     => (int)($current['last_at'] ?? 0),
            'last_event'  => (string)($current['last_event'] ?? ''),
            'last_ok'     => (bool)($current['last_ok'] ?? false),
        ];
        self::$cache = $hooks;
        self::flush();
        return ['ok' => true, 'msg' => '已保存', 'data' => $hooks[$id]];
    }

    /** 把 "123,456 789" / ["123","456"] 统一成数字群号数组 */
    public static function groups(mixed $raw): array
    {
        $list = is_array($raw) ? $raw : preg_split('/[\s,，;；]+/u', (string)$raw, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ((array)$list as $value) {
            $value = trim((string)$value);
            if ($value !== '' && ctype_digit($value) && !in_array($value, $out, true)) {
                $out[] = $value;
            }
        }
        return $out;
    }

    public static function remove(string $id): bool
    {
        $hooks = self::all();
        if (!isset($hooks[$id])) {
            return false;
        }
        unset($hooks[$id]);
        self::$cache = $hooks;
        self::flush();
        return true;
    }

    /** 记录一次投递（成功/失败都记，便于在面板里看 GitHub 到底发了什么） */
    public static function record(string $id, string $event, bool $ok, string $summary, array $extra = []): void
    {
        $hooks = self::all();
        if (isset($hooks[$id])) {
            $hooks[$id]['last_at'] = time();
            $hooks[$id]['last_event'] = $event;
            $hooks[$id]['last_ok'] = $ok;
            self::$cache = $hooks;
            self::flush();
        }
        $file = self::deliveriesPath();
        $decoded = is_file($file) ? json_decode((string)@file_get_contents($file), true) : null;
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        array_unshift($items, [
            'at'      => time(),
            'hook'    => $id,
            'event'   => $event,
            'ok'      => $ok,
            'summary' => $summary,
        ] + $extra);
        $items = array_slice($items, 0, self::MAX_DELIVERIES);
        \Core\Fs::write($file, (string)json_encode(['items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<int, array> */
    public static function deliveries(string $id = '', int $limit = 50): array
    {
        $file = self::deliveriesPath();
        if (!is_file($file)) {
            return [];
        }
        $decoded = json_decode((string)@file_get_contents($file), true);
        $items = is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        if ($id !== '') {
            $items = array_values(array_filter($items, static fn(array $row): bool => ($row['hook'] ?? '') === $id));
        }
        return array_slice($items, 0, $limit);
    }

    public static function flush(): bool
    {
        return \Core\Fs::write(
            self::path(),
            (string)json_encode(['hooks' => self::all()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
        );
    }

    public static function reset(?array $hooks = null): void
    {
        self::$cache = $hooks;
    }
}