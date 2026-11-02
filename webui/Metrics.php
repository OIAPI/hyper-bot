<?php
namespace Webui;

use Core\Config;
use Core\Store\Store;
use Protocol\Core\Adapter;
use Protocol\Milky\EventClient as MilkyClient;
use Protocol\OneBot\WsHub;
use Protocol\QqBot\EventClient as QqBotClient;
use Utils\Logger;

/**
 * WebUI 的只读数据源。
 *
 * 所有页面数据都从这里来，控制器只负责「取数 → 包成 Response」，
 * 因此数据口径集中在一处，不会出现「概览页和详情页统计对不上」的情况。
 */
final class Metrics {
    public function __construct(private Store $store, private string $root) {}

    /** 系统 / 运行时概览 */
    public function overview(): array {
        return [
            'app'      => $this->appInfo(),
            'runtime'  => $this->runtimeInfo(),
            'counts'   => $this->counts(),
            'protocol' => $this->protocols(),
            'recent'   => $this->recentEvents(10),
            'php'      => $this->phpInfo(),
        ];
    }

    public function appInfo(): array {
        $composer = [];
        $file = $this->root . '/composer.json';
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                $composer = $decoded;
            }
        }
        return [
            'name'     => (string)($composer['name'] ?? 'oiapi/bot'),
            'php'      => PHP_VERSION,
            'swoole'   => extension_loaded('swoole') ? phpversion('swoole') : null,
            'sodium'   => extension_loaded('sodium'),
            'redis'    => class_exists('Redis'),
            'debug'    => Config::bool('app.debug', false),
            'store'    => $this->storeDriver(),
            'timezone' => date_default_timezone_get(),
        ];
    }

    public function storeDriver(): string {
        return match (true) {
            $this->store instanceof \Core\Store\RedisStore => 'redis',
            $this->store instanceof \Core\Store\FileStore  => 'file',
            default                                          => 'memory',
        };
    }

    public function runtimeInfo(): array {
        $startedAt = (float)($this->store->get('runtime:started_at') ?? 0);
        if ($startedAt <= 0) {
            $startedAt = (float)($_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true));
        }
        return [
            'started_at' => (int)$startedAt,
            'uptime'     => max(0, time() - (int)$startedAt),
            'pid'        => function_exists('getmypid') ? (int)getmypid() : 0,
            'memory'     => memory_get_usage(true),
            'peak'       => memory_get_peak_usage(true),
            'server'     => PHP_SAPI,
        ];
    }

    /** 各类业务对象数量 */
    public function counts(): array {
        $groups = $this->groups();
        $plugins = $this->plugins();
        return [
            'groups'      => count($groups['items']),
            'plugins'     => count($plugins['items']),
            'plugins_on'  => count(array_filter($plugins['items'], fn($p) => ($p['enabled'] ?? false) === true)),
            'events'      => $this->store->zcard('webui:events'),
            'messages'    => $this->store->zcard('webui:messages'),
            'protocols'   => count(array_filter($this->protocols(), fn($p) => ($p['enabled'] ?? false) === true)),
        ];
    }

    /** 协议运行状态（OneBot 正向/反向、Milky、QQ 官方） */
    public function protocols(): array {
        $out = [
            'onebot' => [
                'label'   => 'OneBot11',
                'enabled' => Config::bool('onebot.enable', false),
                'mode'    => Config::bool('onebot.forward.enable', false) ? 'forward+reverse' : (Config::bool('onebot.reverse.enable', false) ? 'reverse' : 'off'),
                'detail'  => [
                    'forward_running' => WsHub::hasForward(),
                    'forward_url'     => WsHub::forwardUrl(),
                    'reverse_sessions'=> WsHub::reverseCount(),
                    'reverse_path'    => Config::string('onebot.reverse.path', '/onebot/v11/ws'),
                ],
            ],
            'milky' => [
                'label'   => 'Milky',
                'enabled' => Config::bool('milky.enable', false),
                'mode'    => Config::string('milky.event.mode', 'webhook'),
                'detail'  => [
                    'api'     => Config::string('milky.api', ''),
                    'status'  => MilkyClient::status(),
                    'ws_in'   => WsHub::milkyCount(),
                ],
            ],
            'qqbot' => [
                'label'   => 'QQ 官方机器人',
                'enabled' => Config::bool('qqbot.enable', false),
                'mode'    => Config::string('qqbot.event.mode', 'ws'),
                'detail'  => [
                    'api'     => Config::string('qqbot.api', ''),
                    'app_id'  => Config::string('qqbot.app_id', ''),
                    'status'  => QqBotClient::status(),
                ],
            ],
        ];
        return $out;
    }

    /**
     * 群列表。
     *
     * 数据来源有两条：
     *  1. Redis 里已有的 `{group}.info`（历史格式：status + worker 开关）；
     *  2. `webui:groups` 索引（WebUI 自己维护的元数据）。
     * 合并后输出统一结构，避免 WebUI 直接依赖 Redis 键的命名细节。
     */
    public function groups(): array {
        $items = [];
        foreach ($this->store->keys('*.info') as $key) {
            $groupId = substr((string)$key, 0, -5);
            if ($groupId === '' || !preg_match('/^[0-9a-zA-Z_-]+$/', $groupId)) {
                continue;
            }
            $info = $this->store->get((string)$key);
            $items[] = $this->normalizeGroup($groupId, is_array($info) ? $info : []);
        }

        foreach ((array)($this->store->get('webui:groups') ?? []) as $groupId => $meta) {
            $found = false;
            foreach ($items as &$item) {
                if ($item['group_id'] === (string)$groupId) {
                    $item = array_merge($item, array_intersect_key((array)$meta, array_flip(['name', 'note', 'tags'])));
                    $found = true;
                    break;
                }
            }
            unset($item);
            if (!$found) {
                $items[] = $this->normalizeGroup((string)$groupId, [], (array)$meta);
            }
        }

        usort($items, fn($a, $b) => strcmp((string)$a['group_id'], (string)$b['group_id']));
        return ['items' => $items, 'total' => count($items)];
    }

    public function group(string $groupId): ?array {
        foreach ($this->groups()['items'] as $item) {
            if ($item['group_id'] === $groupId) {
                return $item;
            }
        }
        return null;
    }

    private function normalizeGroup(string $groupId, array $info, array $meta = []): array {
        $worker = is_array($info['worker'] ?? null) ? $info['worker'] : [];
        $plugins = $this->plugins()['items'];
        $enabled = 0;
        foreach ($plugins as $plugin) {
            $state = $worker[$plugin['class']] ?? null;
            if ($state !== false) {
                $enabled++;
            }
        }
        return [
            'group_id'      => $groupId,
            'name'          => (string)($meta['name'] ?? $info['name'] ?? ''),
            'note'          => (string)($meta['note'] ?? ''),
            'tags'          => (array)($meta['tags'] ?? []),
            'status'        => (int)($info['status'] ?? 0) === 1,
            'plugins_total' => count($plugins),
            'plugins_on'    => $enabled,
            'worker'        => $worker,
            'updated_at'    => (int)($meta['updated_at'] ?? 0),
        ];
    }

    /** 插件（来自 Router::scanAppClasses + 反射元信息） */
    public function plugins(): array {
        $items = [];
        try {
            $classes = \Controller\Router::scanAppClasses();
        } catch (\Throwable $e) {
            Logger::debug('[WebUI] 插件扫描失败：' . $e->getMessage());
            $classes = [];
        }
        // Router::scanAppClasses() 返回「类名 => 状态」的关联数组，也可能是纯索引数组
        foreach ($classes as $key => $value) {
            $class = is_string($key) ? $key : (string)$value;
            if ($class === '' || !class_exists($class)) {
                continue;
            }
            try {
                $ref = new \ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            $doc = $ref->getDocComment() ?: '';
            $items[] = [
                'class'       => $class,
                'short'       => $ref->getShortName(),
                'name'        => self::docTag($doc, 'name') ?: $ref->getShortName(),
                'description' => self::docTag($doc, 'description') ?: '',
                'author'      => self::docTag($doc, 'author') ?: '',
                'version'     => self::docTag($doc, 'version') ?: '',
                'enabled'     => true,
                'methods'     => count($ref->getMethods(\ReflectionMethod::IS_PUBLIC)),
                'hooks'       => $this->hooksOf($ref),
            ];
        }
        usort($items, fn($a, $b) => strcmp($a['short'], $b['short']));
        return ['items' => $items, 'total' => count($items)];
    }

    private function hooksOf(\ReflectionClass $ref): array {
        $hooks = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            foreach ($method->getAttributes() as $attr) {
                $short = substr(strrchr($attr->getName(), '\\') ?: $attr->getName(), 1);
                if (in_array($short, ['Rule', 'Notice', 'Request', 'Meta_event'], true)) {
                    $hooks[] = ['type' => $short, 'method' => $method->getName()];
                }
            }
        }
        return $hooks;
    }

    private static function docTag(string $doc, string $tag): string {
        if ($doc !== '' && preg_match('/@' . preg_quote($tag, '/') . '\s+(.*)/i', $doc, $m)) {
            return trim($m[1]);
        }
        return '';
    }

    /** 最近事件时间线 */
    public function recentEvents(int $limit = 20, ?string $type = null): array {
        $members = $this->store->zrange('webui:events', 0, max(0, $limit - 1), true);
        $out = [];
        foreach ($members as $member) {
            $decoded = json_decode((string)$member, true);
            if (is_array($decoded) && ($type === null || ($decoded['post_type'] ?? '') === $type)) {
                $out[] = $decoded;
            }
        }
        return $out;
    }

    /** 记录一条事件（供协议层回调） */
    public function recordEvent(array $event): void {
        $entry = [
            'post_type'    => (string)($event['post_type'] ?? ''),
            'message_type' => (string)($event['message_type'] ?? ''),
            'notice_type'  => (string)($event['notice_type'] ?? ''),
            'sub_type'     => (string)($event['sub_type'] ?? ''),
            'self_id'      => (string)($event['self_id'] ?? ''),
            'group_id'     => (string)($event['group_id'] ?? ''),
            'user_id'      => (string)($event['user_id'] ?? ''),
            'raw_message'  => mb_substr((string)($event['raw_message'] ?? ''), 0, 120),
            'time'         => (int)($event['time'] ?? time()),
            'protocol'     => (string)($event['protocol'] ?? 'onebot'),
            'received'     => time(),
        ];
        $score = microtime(true);
        $this->store->zadd('webui:events', $score, json_encode($entry, JSON_UNESCAPED_UNICODE));
        $this->store->ztrim('webui:events', 2000);
    }

    public function phpInfo(): array {
        return [
            'version'    => PHP_VERSION,
            'sapi'       => PHP_SAPI,
            'extensions' => array_values(array_filter([
                'swoole' => extension_loaded('swoole'),
                'redis'  => extension_loaded('redis'),
                'sodium' => extension_loaded('sodium'),
                'curl'   => extension_loaded('curl'),
                'openssl'=> extension_loaded('openssl'),
                'mbstring'=> extension_loaded('mbstring'),
                'json'   => extension_loaded('json'),
            ])),
            'ini' => [
                'memory_limit'        => ini_get('memory_limit'),
                'max_execution_time'  => ini_get('max_execution_time'),
                'date.timezone'       => ini_get('date.timezone'),
            ],
        ];
    }
}
