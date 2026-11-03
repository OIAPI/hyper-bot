<?php
namespace Webui\Controller;

use Core\Bot\Subscription;
use Core\Bot\SubscriptionCatalog;
use Core\Config;
use Core\Http\Request;
use Core\Http\Response;

/**
 * 消息订阅配置（v2）。
 *
 * v1 的订阅藏在三个小节（[type] / [message] / [notice]）里，WebUI 没有入口，
 * 想改只能手改 Config.ini 并重启。v2 给它一个独立页面 + 可解释的判定。
 */
final class SubscriptionController extends Base
{
    /** 当前订阅 + 目录 */
    public function index(Request $request): Response
    {
        $subscription = Subscription::fromConfig();
        return $this->ok([
            'subscription' => $subscription->toArray(),
            'configured_from' => $this->detectSource(),
        ]);
    }

    /** 保存订阅 */
    public function update(Request $request): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $input = $request->params();
        $subscription = Subscription::fromConfig();
        if (!$subscription->save($input)) {
            return $this->fail('保存失败：Config.ini 不可写或取值不合法', -1, 500);
        }

        $reloaded = Subscription::fromConfig();
        return $this->ok([
            'subscription' => $reloaded->toArray(),
            'needs_restart' => true,
        ], '订阅已保存，重启后生效');
    }

    /** 可订阅目录：内置 + 插件声明 + 面板自定义，全部列出 */
    public function catalog(Request $request): Response
    {
        return $this->ok($this->catalogPayload());
    }

    /** 面板里添加一个自定义订阅类型 */
    public function addType(Request $request): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $input = $request->params();
        $result = SubscriptionCatalog::add(
            (string)($input['type'] ?? ''),
            (string)($input['label'] ?? ''),
            (string)($input['group'] ?? ''),
        );
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok($this->catalogPayload(), $result['msg']);
    }

    /** 删除自定义类型（内置与插件的不能删） */
    public function removeType(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $result = SubscriptionCatalog::remove((string)($params['type'] ?? ''));
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok($this->catalogPayload(), $result['msg']);
    }

    /** @return array<string, mixed> */
    private function catalogPayload(): array
    {
        return [
            'groups'   => SubscriptionCatalog::groups(),
            'types'    => SubscriptionCatalog::flat(),
            'stats'    => SubscriptionCatalog::stats(),
            'defaults' => SubscriptionCatalog::types(),
        ];
    }

    /**
     * 为什么需要订阅（作为 API 返回给前端做说明文案）。
     *
     * 用户会问「为什么保留消息订阅」，这里把答案固化在代码里，
     * 前端直接展示，避免下次维护者又把它删掉。
     */
    public function explain(Request $request): Response
    {
        return $this->ok([
            'why' => [
                '订阅不是协议层能力，而是业务路由的第一层过滤',
                '让「不关心的事件」在最便宜的位置被丢弃（不落库、不跑插件、不调 API）',
                '每条被丢弃的事件都能给出明确原因，便于排查「插件为什么没触发」',
                '新协议会产生大量业务无关事件（心跳、成员变更），订阅是唯一的止损点',
            ],
            'v1_keys' => ['[type] message/notice/request/meta_event', '[message] type', '[notice] {type} = 1'],
            'v2_keys' => ['[subscription] message/notice/request/meta_event', '[subscription] message_scope', '[subscription] notice_types'],
            'compatible' => '未写 [subscription] 时自动读 v1 的三个小节，老部署无需改配置',
        ]);
    }

    /** 判定当前订阅值来自 v2 新键还是 v1 老键 */
    private function detectSource(): string
    {
        return Config::get('subscription') !== null ? 'v2' : 'v1';
    }
}
