<?php
namespace Core\Bot;

use Core\App;
use Core\Store\Store;

/**
 * 事件流落库（WebUI「事件」页 / 概览「实时事件」的数据源）。
 *
 * v2 重构时把 v1 EventDispatcher 里写 webui:events 的那一步弄丢了：
 * Metrics 只读不写，于是事件页永远空白。这里补回唯一写入点，
 * 由协议层入口（Protocol\Core\EventDispatcher）在分发前调用，
 * 保证「被订阅过滤/被插件丢弃」的事件也能在面板里查到。
 */
final class EventLog
{
    public const KEY = 'webui:events';
    public const MAX = 2000;

    /**
     * 当前账号的事件流键。
     *
     * 单账号继续用旧全局键：这样升级后历史事件不会消失，也不会出现
     * 「scoped 和 legacy 各写一份、计数翻倍」。启用第二个账号后才切换成
     * acct:{id}:webui:events。
     */
    public static function key(?string $accountId = null): string {
        $id = \Core\Bot\AccountScope::id($accountId);
        if ($id !== '' && \Core\Bot\AccountScope::legacyAllowed($id)) {
            return self::KEY;
        }
        return \Core\Bot\AccountScope::key(self::KEY, $accountId);
    }

    /** 记录一条标准 OneBot11 事件（协议无关，字段缺失即空串） */
    public static function record(array $event, ?Store $store = null): void
    {
        $store ??= App::boot()->store;
        $entry = [
            'post_type'    => (string)($event['post_type'] ?? ''),
            'message_type' => (string)($event['message_type'] ?? ''),
            'notice_type'  => (string)($event['notice_type'] ?? ''),
            'sub_type'     => (string)($event['sub_type'] ?? ''),
            'self_id'      => (string)($event['self_id'] ?? ''),
            'group_id'     => (string)($event['group_id'] ?? ''),
            'user_id'      => (string)($event['user_id'] ?? ''),
            'raw_message'  => mb_substr(self::text($event['raw_message'] ?? ''), 0, 120),
            'time'         => (int)($event['time'] ?? time()),
            'protocol'     => (string)($event['protocol'] ?? 'onebot'),
            'account'      => \Core\Bot\AccountContext::id(),
            'received'     => time(),
        ];
        $key = self::key();
        $store->zadd($key, microtime(true), (string)json_encode($entry, JSON_UNESCAPED_UNICODE));
        $store->ztrim($key, self::MAX);
    }

    /** 某些协议把 raw_message 给成消息段数组，这里统一成文本，避免 Array to string */
    private static function text(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $seg) {
                if (is_array($seg)) {
                    $data = $seg['data'] ?? [];
                    $parts[] = is_array($data) ? (string)($data['text'] ?? '') : (string)$data;
                } else {
                    $parts[] = (string)$seg;
                }
            }
            return implode('', $parts);
        }
        return is_scalar($value) ? (string)$value : '';
    }
}
