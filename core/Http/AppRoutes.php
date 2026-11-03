<?php
namespace Core\Http;

use Core\Bot\AccountContext;
use Core\Bot\Accounts;
use Core\Bot\Subscription;
use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Core\Runtime;
use Core\Version;
use Protocol\Milky\EventClient as MilkyClient;
use Protocol\QqBot\Webhook as QqBotWebhook;
use Protocol\QqBot\EventClient as QqBotClient;
use Protocol\OneBot\WsHub;

/**
 * 应用 HTTP 路由（v2）：统一挂载 WebUI、协议 Webhook、公开状态端点。
 *
 * v1 有两套互不相通的路由体系：
 *   - `Utils\Route`（注解扫描，给旧业务用，靠全局 Cache 传参）
 *   - `Core\Http\Router`（内核路由，只有 WebUI 用）
 * 结果是协议 Webhook 端点长在旧体系里（`App\Controller\Protocol`，靠注解 + 全局 Cache），
 * 而 WebUI 长在新体系里；同一台服务器上两套中间件、两套错误处理。
 *
 * v2 把协议端点也迁到内核路由上，旧注解体系保留作为「用户自己的 API」的兼容层。
 * 协议端点全部走显式签名（Request → Response），不再依赖 Cache::get('param')。
 */
final class AppRoutes
{
    /**
     * @param bool $acceptEvents 是否接受协议事件。
     *   webui-only 架构必须传 false：这时连路由都不该注册，
     *   否则请求会命中路由（返回 200 accepted），协议端会以为投递成功，
     *   而实际上没有任何业务被执行 —— 这是最坏的静默失败。
     */
    public static function register(Router $router, bool $acceptEvents = true): void
    {
        // ---- 协议接入端点（仅在有事件处理能力时注册）----
        if ($acceptEvents) {
            $router->post('/api/protocol/webhook', static fn(Request $req): Response => self::oneBotWebhook($req), 'protocol.onebot.webhook');
            $router->post('/api/protocol/milky/webhook', static fn(Request $req): Response => self::milkyWebhook($req), 'protocol.milky.webhook');
            $router->post('/api/protocol/qqbot/webhook', static fn(Request $req): Response => self::qqbotWebhook($req), 'protocol.qqbot.webhook');
        }
        $router->get('/api/protocol/status', static fn(Request $req): Response => self::protocolStatus($req), 'protocol.status');
        $router->get('/api/health', static fn(Request $req): Response => self::health($req), 'api.health');
    }

    // ---------------- OneBot11 HTTP 推送 ----------------

    private static function oneBotWebhook(Request $req): Response
    {
        $event = $req->params();
        if (!isset($event['post_type'])) {
            return Response::fail('缺少 post_type', -1, 400);
        }
        $account = self::resolveAccount($req, 'onebot', $event);
        self::withAccount($account, static function () use ($event): void {
            \Protocol\Core\EventDispatcher::dispatch($event, new \Protocol\OneBot\Adapter());
        });
        return Response::json(['status' => 'ok', 'retcode' => 0, 'data' => ['status' => 'accepted']]);
    }

    /**
     * 解析请求指向哪个机器人账号。
     *
     * 优先级：?account=<id> → X-Account-Id 头 → X-Self-Id 头 → 事件体里的 self_id。
     * 找不到就返回 null，走单账号行为（读 Config.ini），保证老部署不改配置也能跑。
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    private static function resolveAccount(Request $req, string $protocol, array $payload = []): ?array
    {
        $id = (string)($req->query['account'] ?? $req->header('x-account-id'));
        if ($id !== '') {
            $account = Accounts::get($id);
            if ($account !== null && strtolower((string)$account['protocol']) === $protocol) {
                return $account;
            }
        }

        $selfId = (string)$req->header('x-self-id');
        if ($selfId === '') {
            $selfId = (string)($payload['self_id'] ?? '');
        }
        if ($selfId === '' || $selfId === '0') {
            return null;
        }
        $account = Accounts::bySelfId($selfId);
        if ($account === null || strtolower((string)$account['protocol']) !== $protocol) {
            return null;
        }
        return (bool)($account['enable'] ?? true) ? $account : null;
    }

    /** 在账号上下文里执行一段逻辑（出栈由这里保证） */
    private static function withAccount(?array $account, callable $fn): void
    {
        $previous = AccountContext::current();
        AccountContext::use($account);
        try {
            $fn();
        } finally {
            AccountContext::use($previous);
        }
    }

    // ---------------- Milky Webhook ----------------

    private static function milkyWebhook(Request $req): Response
    {
        $payload = $req->params();
        $account = self::resolveAccount($req, 'milky', $payload);
        // 多账号时用该账号自己的 token 校验；没有账号上下文才退回全局配置
        $expect = $account !== null
            ? (string)($account['token'] ?? '')
            : Config::string('protocol.milky.token', '');
        if ($expect !== '') {
            $auth = $req->header('authorization');
            $token = str_starts_with(strtolower($auth), 'bearer ') ? trim(substr($auth, 7)) : '';
            if ($token === '' || !hash_equals($expect, $token)) {
                return Response::fail('unauthorized', -1, 401);
            }
        }

        $result = null;
        self::withAccount($account, static function () use ($payload, &$result): void {
            $result = MilkyClient::handleEvent($payload, 'webhook');
        });
        return Response::json($result ?? ['status' => 'ok', 'retcode' => 0, 'data' => new \stdClass()]);
    }

    // ---------------- QQ 官方机器人 Webhook ----------------

    private static function qqbotWebhook(Request $req): Response
    {
        $payload = $req->params();

        // op=13：回调地址验证（协议端要求原样返回 plain_token + signature）
        if ((int)($payload['op'] ?? -1) === 13) {
            return Response::json(QqBotWebhook::validation($payload));
        }

        if (Config::bool('protocol.qqbot.webhook.verify', true)) {
            $secret = Config::string('protocol.qqbot.client_secret', '');
            if ($secret === '') {
                return Response::json(['code' => -1, 'message' => 'client_secret 未配置，无法校验签名']);
            }
            if (!function_exists('sodium_crypto_sign_verify_detached')) {
                return Response::json(['code' => -1, 'message' => '缺少 ext-sodium 扩展，无法校验签名']);
            }
            if (!QqBotWebhook::verify($req->headers, $req->rawBody)) {
                return Response::fail('signature verification failed', -1, 401);
            }
        }

        $account = self::resolveAccount($req, 'qqbot', $payload);
        self::withAccount($account, static function () use ($payload): void {
            QqBotClient::handlePayload($payload, 'webhook');
        });
        return Response::json(['op' => 12, 'd' => new \stdClass()]);
    }

    // ---------------- 状态 ---------------- ------------------------------------------------------------------

    private static function protocolStatus(Request $req): Response
    {
        $runtime = \Core\App::boot()->runtime;
        return Response::json([
            'runtime' => [
                'mode'       => $runtime->mode,
                'label'      => $runtime->label(),
                'degraded'   => $runtime->degraded,
                'reason'     => $runtime->reason,
                'limitations'=> $runtime->limitations(),
            ],
            'onebot' => [
                'enable'   => Config::bool('protocol.onebot.enable', false),
                'forward'  => ['running' => WsHub::hasForward(), 'url' => WsHub::forwardUrl()],
                'reverse'  => ['sessions' => WsHub::reverseCount()],
            ],
            'milky' => [
                'enable' => Config::bool('protocol.milky.enable', false),
                'api'    => Config::string('protocol.milky.api', ''),
                'event'  => MilkyClient::status(),
            ],
            'qqbot' => [
                'enable'  => Config::bool('protocol.qqbot.enable', false),
                'api'     => Config::string('protocol.qqbot.api', ''),
                'gateway' => QqBotClient::status(),
            ],
        ]);
    }

    private static function health(Request $req): Response
    {
        $app = \Core\App::boot();
        return Response::json([
            'ok'       => true,
            'version'  => Version::current(),
            'runtime'  => $app->runtime->mode,
            'store'    => get_class($app->store),
            'time'     => date('c'),
        ]);
    }
}