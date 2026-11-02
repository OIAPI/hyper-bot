<?php
namespace Protocol\Core;

use Core\App;
use Core\Bot\Event;
use Utils\Logger;
use Utils\MessageLog;

/**
 * 协议层事件入口（v2）：把各协议送来的一事件转交给 `Core\Bot\Dispatcher`。
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
 *   4. new \Controller\Trigger\*   → Core\Bot\Dispatcher（注册表匹配 + 权限）
 *
 * 返回值仍是 OneBot11 的 {"status":"ok","retcode":0,...}，因为调用方会把它
 * 直接回给协议端（Webhook 需要立即 200，否则协议端会重投）。
 */
class EventDispatcher
{
    /**
     * 分发一条标准 OneBot11 事件。
     *
     * @param array $event 标准事件
     * @param Adapter|null $adapter 事件来源（决定插件发消息的去向）
     * @param callable|null $responder 处理完成后的回调（Webhook 用它写响应）
     * @return array OneBot11 风格的成功应答
     */
    public static function dispatch(array $event, ?Adapter $adapter = null, ?callable $responder = null): array
    {
        $event = self::normalize($event);
        $postType = ucwords(strtolower((string)($event['post_type'] ?? '')));

        if ($postType === '') {
            Logger::warning('[事件分发] 缺少 post_type，已忽略：' . substr(json_encode($event, JSON_UNESCAPED_UNICODE), 0, 200));
            return self::accepted();
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
            $result = $app->dispatcher->dispatch(Event::fromArray($event), $adapter);
            Logger::debug('[事件分发] ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        } catch (\Throwable $e) {
            Logger::error("[事件分发] {$postType} 处理异常：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
        } finally {
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
