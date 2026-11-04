<?php
namespace Protocol\Core;

use Core\App;
use Core\Bot\AccountContext;
use Core\Bot\Accounts;
use Core\Bot\Event;
use Utils\Logger;
use Utils\MessageLog;

/**
 * 协议层事件入口（v2）：把各协议送来的一事件转交给 Core\Bot\Dispatcher。
 *
 * 这个类保留下来只是为了**不动 5 个调用点**：
 *   app/Controller/Protocol.php::webhook
 *   protocol/Milky/EventClient.php
 *   protocol/OneBot/WsHub.php
 *   protocol/QqBot/EventClient.php（两处）
 *
 * v1 的实现在这里做了四件事（现在全部下移到 v2 内核）：
 *   1. 读 Config.ini [type] 过滤      → Core\Bot\Subscription
 *   2. 写 MessageLog 落库             → 这里保留（属于审计，不是业务）
 *   3. 开子协程 + Cache::set 上下文   → Core\Bot\Context::run（自动压栈出栈）
 *   4. new Controller\Trigger\*   → Core\Bot\Dispatcher（注册表匹配 + 权限）
 *
 * 返回值仍是 OneBot11 的 {"status":"ok","retcode":0,...}，因为调用方会把它
 * 直接回给协议端（Webhook 需要立即 200，否则协议端会重投）。
 */
class EventDispatcher
{
    /**
     * 分发一条标准 OneBot11 事件。
     *
     * 多账号：进来先确定这条事件属于哪个账号（调用方已压栈就沿用，否则按 self_id 反查），
     * 处理期间 AccountContext 持有它 —— 协议层读 milky.api / qqbot.token 时拿到的就是这个账号的。
     * 调用方没压栈时由这里负责弹栈，保证不会泄漏到下一个请求。
     */
    public static function dispatch(array $event, ?Adapter $adapter = null, ?callable $responder = null): array
    {
        $event = self::normalize($event);
        $postType = ucwords(strtolower((string)($event['post_type'] ?? '')));

        if ($postType === '') {
            Logger::warning('[事件分发] 缺少 post_type，已忽略：' . substr(json_encode($event, JSON_UNESCAPED_UNICODE), 0, 200));
            return self::accepted();
        }

        $pushed = false;
        if (AccountContext::current() === null) {
            $account = Accounts::bySelfId((string)($event['self_id'] ?? ''));
            if ($account !== null) {
                AccountContext::use($account);
                $pushed = true;
            }
        }
        $current = AccountContext::current();
        if ($current !== null && ($event['account'] ?? '') === '') {
            $event['account'] = (string)($current['id'] ?? '');
        }

        // 插件钩子（v3.2）：收到的消息先过一遍「过滤」。
        // 返回数组继续（内容可以改），返回 null / false 表示这条事件到此为止。
        // 被丢弃的事件不落库、不分发，但仍然回 200 —— 否则协议端会一直重投。
        $filtered = \Core\Extension\Hooks::filter('message.received', $event, $adapter);
        if ($filtered === null || $filtered === false) {
            Logger::info('[事件分发] 插件丢弃了一条 ' . ($postType ?: 'unknown') . ' 事件');
            try {
                \Core\Extension\Hooks::do('message.filtered', $event, $adapter);
            } catch (\Throwable $e) {
                Logger::warning('[事件分发] message.filtered 钩子异常：' . $e->getMessage());
            }
            if ($pushed) {
                AccountContext::reset();
            }
            if ($responder !== null) {
                try {
                    $responder(self::accepted());
                } catch (\Throwable $e) {
                    Logger::error('[事件分发] 响应回调异常：' . $e->getMessage());
                }
            }
            return self::accepted();
        }
        if (is_array($filtered)) {
            $event = $filtered;
        }

        // 审计落库：在订阅过滤之前写，保证「被丢弃的事件」也能在 WebUI 里查到，
        // 这是排查「为什么我的插件没触发」最直接的证据。
        try {
            MessageLog::write($event, strtolower($postType));
        } catch (\Throwable $e) {
            Logger::error('[事件分发] 消息落库失败：' . $e->getMessage());
        }

        try {
            $app = App::boot();
            // 插件钩子：分发前可以改事件（加字段、临时改类型、拒收）
            $event = \Core\Extension\Hooks::filter('event.before_dispatch', $event, $adapter);
            $result = $app->dispatcher->dispatch(Event::fromArray($event), $adapter);
            \Core\Extension\Hooks::do('event.after_dispatch', $event, $result);
            Logger::debug('[事件分发] ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Logger::error("[事件分发] {$postType} 处理异常：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
        } finally {
            if ($pushed) {
                AccountContext::reset();
            }
            if ($responder !== null) {
                try {
                    $responder(self::accepted());
                } catch (\Throwable $e) {
                    Logger::error('[事件分发] 响应回调异常：' . $e->getMessage());
                }
            }
        }

        return self::accepted();
    }

    /** 补齐公共字段（协议端不一定都带） */
    public static function normalize(array $event): array
    {
        $event['time']        ??= time();
        $event['self_id']     ??= 0;
        $event['post_type']   ??= '';
        $event['raw_message'] ??= '';
        return $event;
    }

    private static function accepted(): array
    {
        return [
            'status'  => 'ok',
            'retcode' => 0,
            'data'    => ['status' => 'accepted'],
        ];
    }
}