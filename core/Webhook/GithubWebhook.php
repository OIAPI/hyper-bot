<?php
namespace Core\Webhook;

use Core\Bot\Registry;
use Core\Extension\ExtensionPoint;
use Core\Http\Request;
use Core\Http\Response;
use Utils\Logger;

/**
 * GitHub WebHook 接收器。
 *
 * 校验 X-Hub-Signature-256（HMAC-SHA256，密钥就是订阅时填的 secret），
 * 归一化成一条 summary 存进投递记录，再以 github.<event> 广播给插件。
 *
 * 这里刻意不落库、不做重放保护：GitHub 会重试，重复投递在业务上由插件自己按
 * X-GitHub-Delivery 去重更合适；框架只保证「签名对、格式对、能查得到」。
 */
final class GithubWebhook
{
    public static function handle(Request $request, string $id): Response
    {
        $hook = WebhookStore::get($id);
        if ($hook === null) {
            return Response::fail('没有这个 WebHook 订阅：' . $id, -1, 404);
        }
        if (empty($hook['enabled'])) {
            return Response::fail('该订阅已停用', -2, 403);
        }

        $raw = $request->rawBody;
        $signature = $request->header('x-hub-signature-256', '');
        $expected = 'sha256=' . hash_hmac('sha256', $raw, (string)$hook['secret']);
        if (!hash_equals($expected, $signature)) {
            WebhookStore::record($id, 'invalid-signature', false, '签名校验失败（secret 不一致或请求被篡改）');
            Logger::warning('[webhook] GitHub 签名校验失败 | id=' . $id . ' | ip=' . $request->remoteIp);
            return Response::fail('签名校验失败', -3, 401);
        }

        $event = $request->header('x-github-event', 'unknown');
        $delivery = $request->header('x-github-delivery', '');
        $payload = is_array($request->body) ? $request->body : [];
        if ($payload === []) {
            $decoded = json_decode($raw, true);
            $payload = is_array($decoded) ? $decoded : [];
        }

        $events = (array)($hook['events'] ?? ['*']);
        if ($events !== ['*'] && !in_array($event, $events, true)) {
            WebhookStore::record($id, $event, true, '事件未订阅，已忽略');
            return Response::ok(['ignored' => true], '事件未订阅');
        }

        $summary = self::summarize($event, $payload);
        WebhookStore::record($id, $event, true, $summary);

        $registry = \Core\App::boot()->registry;
        $handled = self::broadcast($registry, $event, $payload, $id, $delivery);

        Logger::info('[webhook] GitHub ' . $event . ' | id=' . $id . ' | ' . $summary . ' | plugins=' . $handled);

        return Response::ok([
            'event'    => $event,
            'delivery' => $delivery,
            'summary'  => $summary,
            'plugins'  => $handled,
        ], 'accepted');
    }

    private static function broadcast(Registry $registry, string $event, array $payload, string $hookId, string $delivery = ''): int
    {
        $context = [
            'hook'     => $hookId,
            'event'    => $event,
            'payload'  => $payload,
            'delivery' => $delivery,
            'summary'  => self::summarize($event, $payload),
            'repo'     => (string)($payload['repository']['full_name'] ?? ''),
            'sender'   => (string)($payload['sender']['login'] ?? ''),
            'received_at' => time(),
        ];
        $count = 0;
        foreach (['github', 'github.' . $event] as $topic) {
            $count += ExtensionPoint::emit($registry, $topic, $context);
        }
        return $count;
    }

    /** 一句话说清这次推送了什么，面板里直接看 */
    public static function summarize(string $event, array $payload): string
    {
        $repo = (string)($payload['repository']['full_name'] ?? '');
        switch ($event) {
            case 'ping':
                return 'ping（' . (string)($payload['zen'] ?? 'WebHook 配置成功') . '）';
            case 'push':
                $branch = str_replace('refs/heads/', '', (string)($payload['ref'] ?? ''));
                $commits = is_array($payload['commits'] ?? null) ? count($payload['commits']) : 0;
                return $repo . ' 推送到 ' . $branch . '，' . $commits . ' 个提交';
            case 'issues':
                return $repo . ' ' . (string)($payload['action'] ?? '') . ' issue #' . (string)($payload['issue']['number'] ?? '') . '：' . (string)($payload['issue']['title'] ?? '');
            case 'pull_request':
                return $repo . ' ' . (string)($payload['action'] ?? '') . ' PR #' . (string)($payload['pull_request']['number'] ?? '') . '：' . (string)($payload['pull_request']['title'] ?? '');
            case 'release':
                return $repo . ' 发布 ' . (string)($payload['release']['tag_name'] ?? '');
            case 'star':
                return $repo . ' 新增 star（共 ' . (string)($payload['repository']['stargazers_count'] ?? '?') . '）';
            default:
                return $repo . ' 事件 ' . $event . ' ' . (string)($payload['action'] ?? '');
        }
    }
}
