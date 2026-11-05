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
        if (!self::eventSubscribed($event, $payload, $events)) {
            WebhookStore::record($id, $event, true, '事件未订阅（events=' . implode(',', $events) . '），已忽略');
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
        // 只广播一次，topic 用具体事件名 github.<event>。
        // 之前同时发了 'github' 和 'github.<event>'：ExtensionPoint::emit 会把
        // 每个 topic 都投给所有插件的 onExtension，Github 插件按前缀判断，
        // 于是同一条事件被处理两次，群里每条通知都发两遍。
        return ExtensionPoint::emit($registry, 'github.' . $event, $context);
    }

    /**
     * 事件过滤：events 支持 event / event.action / event:ref / event.* / *。
     *
     *   release              → 所有 release 动作（published / created / edited…）
     *   release.published    → 只有 payload.action = published
     *   release.*            → 所有 release 动作
     *   issues.opened        → 只有新建 issue
     *   push                 → 所有 push
     *   push:main            → 只匹配推送到 main（也接受 push#main）
     *   push.created         → 分支/标签刚创建（payload.created = true）
     *   push.deleted         → 分支/标签刚删除；push.forced → 强推
     *
     * @param list<string> $events
     */
    public static function eventSubscribed(string $event, array $payload, array $events): bool
    {
        $event = strtolower(trim($event));
        $list = [];
        foreach ($events as $item) {
            $item = strtolower(trim((string)$item));
            if ($item !== '') {
                $list[] = $item;
            }
        }
        if ($list === [] || in_array('*', $list, true)) {
            return true;
        }
        $action = strtolower(trim((string)($payload['action'] ?? '')));
        $ref = strtolower((string)($payload['ref'] ?? ''));
        $isTag = str_starts_with($ref, 'refs/tags/');
        $branch = $isTag ? '' : (string)preg_replace('#^refs/heads/#', '', $ref);
        $tag = $isTag ? substr($ref, strlen('refs/tags/')) : '';
        // release 事件没有 ref，用 release.tag_name 给 release:tag:v* 用
        $releaseTag = strtolower((string)($payload['release']['tag_name'] ?? ''));

        foreach ($list as $item) {
            if ($item === $event) {
                return true;
            }
            // event.action / event.*
            if (str_starts_with($item, $event . '.')) {
                $suffix = substr($item, strlen($event) + 1);
                if ($suffix === '*') {
                    return true;
                }
                if ($suffix !== '' && $suffix === $action) {
                    return true;
                }
                // push 没有 action 字段，用 payload 的 created/deleted/forced 区分
                if ($event === 'push' && in_array($suffix, ['created', 'deleted', 'forced'], true)
                    && !empty($payload[$suffix])) {
                    return true;
                }
            }
            // event:ref / event#ref（push 分支 / 标签过滤，支持通配符）
            if (preg_match('/^' . preg_quote($event, '/') . '[:#](.+)$/', $item, $m)) {
                if (self::refMatches($m[1], $ref, $branch, $tag, $isTag)) {
                    return true;
                }
                if ($event === 'release' && $releaseTag !== ''
                    && self::refMatches($m[1], 'refs/tags/' . $releaseTag, '', $releaseTag, true)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * push 的 ref 过滤。
     *
     *   push:main                 短分支名
     *   push:heads/main           等价写法
     *   push:refs/heads/main      完整 ref
     *   push:tag / push:tags      任意标签推送
     *   push:tag:v3.2.20          标签名
     *   push:tags/v3.2.20         同上
     *   push:refs/tags/v3.2.20    完整标签 ref
     *   push:refs/tags/v*         通配符（fnmatch）
     */
    private static function refMatches(string $target, string $ref, string $branch, string $tag, bool $isTag): bool
    {
        $target = trim($target);
        if ($target === '') {
            return false;
        }
        if ($target === '*') {
            return true;
        }
        if (in_array($target, ['tag', 'tags'], true)) {
            return $isTag;
        }
        $patterns = [];
        if (str_starts_with($target, 'tag:')) {
            $patterns[] = 'refs/tags/' . substr($target, 4);
        } elseif (str_starts_with($target, 'tags/')) {
            $patterns[] = 'refs/tags/' . substr($target, 5);
        } elseif (str_starts_with($target, 'branch:')) {
            $patterns[] = 'refs/heads/' . substr($target, 7);
        } elseif (str_starts_with($target, 'heads/')) {
            $patterns[] = 'refs/heads/' . substr($target, 6);
        } else {
            $patterns[] = $target;
        }
        // 同时匹配完整 ref、短分支名、短标签名
        foreach ($patterns as $pattern) {
            foreach ([$ref, $branch, $tag] as $subject) {
                if ($subject === '') {
                    continue;
                }
                if ($pattern === $subject || fnmatch($pattern, $subject)) {
                    return true;
                }
            }
            // tag:v3.2.20 这种展开成完整 ref 后，subject 只可能是完整 ref
            if (str_starts_with($pattern, 'refs/tags/') && fnmatch($pattern, $ref)) {
                return true;
            }
            if (str_starts_with($pattern, 'refs/heads/') && fnmatch($pattern, $ref)) {
                return true;
            }
        }
        return false;
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
