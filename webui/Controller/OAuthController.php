<?php
namespace Webui\Controller;

use Core\Config;
use Core\Http\Middleware\AuthMiddleware;
use Core\Http\Request;
use Core\Http\Response;
use Utils\CoroutineCurl;
use Webui\Auth;

/**
 * 第三方登录（OAuth2 授权码模式）。
 *
 * 刻意做成「配置驱动」：provider 的内置默认值只是省事，
 * 任意自建 OAuth2 服务只要在 Config.ini 里补 authorize_url / token_url /
 * userinfo_url 就能接进来，不必改代码。
 *
 * 安全要点：
 *  - state 用 HMAC 签名并带时间戳（10 分钟过期），防 CSRF 与重放；
 *  - 回调不信任任何前端传参，账号身份只认 userinfo 返回的 sub；
 *  - 未绑定过的第三方身份不会自动建号，必须先在面板里登录后手动绑定，
 *    避免「随便一个 GitHub 账号点一下就成了面板管理员」。
 */
final class OAuthController extends Base {
    /** 内置 provider 默认值，可被 Config.ini 的 [oauth.<id>] 覆盖 */
    private const PROVIDERS = [
        'github' => [
            'label'       => 'GitHub',
            'authorize'   => 'https://github.com/login/oauth/authorize',
            'token'       => 'https://github.com/login/oauth/access_token',
            'userinfo'    => 'https://api.github.com/user',
            'scope'       => 'read:user',
            'id_field'    => 'id',
            'name_field'  => 'login',
        ],
        'gitee' => [
            'label'       => 'Gitee',
            'authorize'   => 'https://gitee.com/oauth/authorize',
            'token'       => 'https://gitee.com/oauth/token',
            'userinfo'    => 'https://gitee.com/api/v5/user',
            'scope'       => 'user_info',
            'id_field'    => 'id',
            'name_field'  => 'name',
        ],
        'linuxdo' => [
            'label'       => 'Linux.do',
            'authorize'   => 'https://connect.linux.do/oauth2/authorize',
            'token'       => 'https://connect.linux.do/oauth2/token',
            'userinfo'    => 'https://connect.linux.do/api/user',
            'scope'       => 'openid profile',
            'id_field'    => 'id',
            'name_field'  => 'username',
        ],
    ];

    private const STATE_TTL = 600;

    public function __construct(private AuthMiddleware $auth) {
        parent::__construct();
    }

    /** 当前可用的 provider 列表 + 已绑定情况 */
    public function providers(Request $request): Response {
        $username = (string)($request->user()['username'] ?? '');
        $bound = Auth::bindings($username);
        $out = [];
        foreach (self::PROVIDERS as $id => $meta) {
            $enabled = $this->clientId($id) !== '' && $this->clientSecret($id) !== '';
            $out[] = [
                'id'          => $id,
                'label'       => $this->setting($id, 'label', $meta['label']),
                'enabled'     => $enabled,
                'bound'       => isset($bound[$id]),
                'bound_label' => $bound[$id]['label'] ?? '',
                'bound_at'    => $bound[$id]['bound_at'] ?? 0,
            ];
        }
        return $this->ok([
            'providers'    => $out,
            'callback'     => $this->callbackUrl($request, '{provider}'),
            'redirect_uri_required' => true,
        ]);
    }

    /** 发起授权：bind=1 表示给当前账号绑定，否则是登录 */
    public function start(Request $request, array $params): Response {
        $provider = (string)($params['provider'] ?? '');
        $meta = self::PROVIDERS[$provider] ?? null;
        if ($meta === null) {
            return $this->fail('不支持的登录方式：' . $provider, -1, 404);
        }
        if ($this->clientId($provider) === '' || $this->clientSecret($provider) === '') {
            return $this->fail('未配置 ' . $meta['label'] . ' 的 client_id / client_secret', -2, 400);
        }

        $bind = (string)$request->input('bind', '') === '1';
        $username = '';
        if ($bind) {
            $username = (string)($request->user()['username'] ?? '');
            if ($username === '') {
                return $this->fail('绑定前请先登录', -3, 401);
            }
        }

        $state = $this->signState([
            'p'    => $provider,
            'b'    => $bind ? 1 : 0,
            'u'    => $username,
            'ts'   => time(),
            'n'    => bin2hex(random_bytes(8)),
        ]);

        $query = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->clientId($provider),
            'redirect_uri'  => $this->callbackUrl($request, $provider),
            'scope'         => $this->setting($provider, 'scope', (string)$meta['scope']),
            'state'         => $state,
        ]);
        return Response::redirect($this->setting($provider, 'authorize_url', (string)$meta['authorize']) . '?' . $query);
    }

    /** 回调：换 token -> 取 userinfo -> 绑定或登录 */
    public function callback(Request $request, array $params): Response {
        $provider = (string)($params['provider'] ?? '');
        $meta = self::PROVIDERS[$provider] ?? null;
        if ($meta === null) {
            return $this->failPage('不支持的登录方式：' . $provider);
        }

        $state = $this->verifyState((string)($request->input('state', '')));
        if ($state === null) {
            return $this->failPage('登录状态已过期或校验失败，请重新发起');
        }
        if (($state['p'] ?? '') !== $provider) {
            return $this->failPage('登录方式与授权状态不一致');
        }
        if ((string)$request->input('error', '') !== '') {
            return $this->failPage('第三方授权被拒绝：' . (string)$request->input('error_description', $request->input('error')));
        }
        $code = (string)$request->input('code', '');
        if ($code === '') {
            return $this->failPage('第三方未返回授权码');
        }

        $token = $this->exchangeCode($provider, $meta, $code, $this->callbackUrl($request, $provider));
        if ($token === null) {
            return $this->failPage('换取 access_token 失败，请检查 client_secret 与回调地址');
        }
        $profile = $this->fetchProfile($provider, $meta, $token);
        if ($profile === null) {
            return $this->failPage('读取第三方账号信息失败');
        }
        $sub = (string)$profile['sub'];
        $label = (string)$profile['label'];

        if (($state['b'] ?? 0) === 1) {
            $username = (string)($state['u'] ?? '');
            $error = Auth::bindOauth($username, $provider, $sub, $label);
            if ($error !== null) {
                return $this->donePage('/webui/account', 'bind_failed', $error);
            }
            return $this->donePage('/webui/account', 'bound', $meta['label'] . ' 已绑定到账号 ' . $username);
        }

        $username = Auth::userByOauth($provider, $sub);
        if ($username === null) {
            return $this->donePage('/webui/login', 'unbound',
                '这个 ' . $meta['label'] . ' 账号还没有绑定面板账号。请先用密码登录，再到「账号」页绑定。');
        }
        $role = Auth::normalizeRole((string)(Auth::users()[$username]['role'] ?? 'viewer'));
        $session = $this->auth->createSession(['username' => $username, 'role' => $role]);
        return $this->donePage('/', 'login', '已通过 ' . $meta['label'] . ' 登录')
            ->withCookie(AuthMiddleware::COOKIE, $session, AuthMiddleware::SESSION_TTL);
    }

    /** 解绑 */
    public function unbind(Request $request, array $params): Response {
        $provider = (string)($params['provider'] ?? '');
        $username = (string)($request->user()['username'] ?? '');
        if (!Auth::unbindOauth($username, $provider)) {
            return $this->fail('没有绑定记录', -1, 404);
        }
        return $this->ok(['providers' => []], '已解绑');
    }

    // ------------------------------------------------------------ 内部工具

    private function setting(string $provider, string $key, string $default): string {
        $value = Config::string('oauth.' . $provider . '.' . $key, '');
        return $value !== '' ? $value : $default;
    }

    private function clientId(string $provider): string {
        return Config::string('oauth.' . $provider . '.client_id', '');
    }

    private function clientSecret(string $provider): string {
        return Config::string('oauth.' . $provider . '.client_secret', '');
    }

    /** 回调地址：优先配置，否则按当前请求推导 */
    private function callbackUrl(Request $request, string $provider): string {
        $configured = Config::string('oauth.callback', '');
        if ($configured !== '') {
            return str_replace('{provider}', $provider, $configured);
        }
        $scheme = 'http';
        $proto = (string)($request->header('x-forwarded-proto') ?? '');
        if ($proto !== '') {
            $scheme = strtolower(explode(',', $proto)[0]);
        } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            $scheme = 'https';
        }
        $host = (string)($request->header('host') ?? 'localhost');
        return $scheme . '://' . $host . '/webui/api/auth/oauth/' . $provider . '/callback';
    }

    private function secret(): string {
        $configured = Config::string('webui.oauth_secret', '');
        if ($configured !== '') {
            return $configured;
        }
        $file = Auth::dir() . '/oauth-secret.txt';
        if (is_file($file)) {
            $read = trim((string)@file_get_contents($file));
            if ($read !== '') {
                return $read;
            }
        }
        $secret = bin2hex(random_bytes(32));
        if (\Core\Fs::write($file, $secret . "\n")) {
            @chmod($file, 0600);
        }
        return $secret;
    }

    private function signState(array $data): string {
        $payload = rtrim(strtr(base64_encode((string)json_encode($data)), '+/', '-_'), '=');
        return $payload . '.' . hash_hmac('sha256', $payload, $this->secret());
    }

    /** @return array|null 校验通过返回原始数据 */
    private function verifyState(string $state): ?array {
        $parts = explode('.', $state);
        if (count($parts) !== 2) {
            return null;
        }
        [$payload, $mac] = $parts;
        if (!hash_equals(hash_hmac('sha256', $payload, $this->secret()), $mac)) {
            return null;
        }
        $json = base64_decode(strtr($payload, '-_', '+/'), true);
        $data = is_string($json) ? json_decode($json, true) : null;
        if (!is_array($data) || (int)($data['ts'] ?? 0) + self::STATE_TTL < time()) {
            return null;
        }
        return $data;
    }

    /** @return string|null access_token */
    private function exchangeCode(string $provider, array $meta, string $code, string $redirect): ?string {
        $url = $this->setting($provider, 'token_url', (string)$meta['token']);
        $body = http_build_query([
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $this->clientId($provider),
            'client_secret' => $this->clientSecret($provider),
            'redirect_uri'  => $redirect,
        ]);
        $json = $this->http('POST', $url, $body, ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json']);
        if (!is_array($json)) {
            return null;
        }
        $token = (string)($json['access_token'] ?? '');
        return $token !== '' ? $token : null;
    }

    /** @return array{sub:string,label:string}|null */
    private function fetchProfile(string $provider, array $meta, string $token): ?array {
        $url = $this->setting($provider, 'userinfo_url', (string)$meta['userinfo']);
        $json = $this->http('GET', $url, '', ['Authorization: Bearer ' . $token, 'Accept: application/json', 'User-Agent: HyperBot-WebUI']);
        if (!is_array($json)) {
            return null;
        }
        $idField = $this->setting($provider, 'id_field', (string)$meta['id_field']);
        $nameField = $this->setting($provider, 'name_field', (string)$meta['name_field']);
        $sub = (string)($json[$idField] ?? ($json['sub'] ?? ''));
        if ($sub === '') {
            return null;
        }
        $label = (string)($json[$nameField] ?? ($json['name'] ?? $json['login'] ?? $sub));
        return ['sub' => $sub, 'label' => $label];
    }

    /** 极简 HTTP 客户端：有 curl 用 curl，没有就用 CoroutineCurl */
    private function http(string $method, string $url, string $body, array $headers): ?array {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            if ($body !== '') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            curl_close($ch);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            return is_array($decoded) ? $decoded : null;
        }
        $curl = new CoroutineCurl();
        $curl->timeout(10);
        $curl->setHeaders($headers);
        $method === 'POST' ? $curl->post($url, $body) : $curl->get($url);
        return $curl->json();
    }

    private function failPage(string $message): Response {
        return $this->donePage('/webui/login', 'oauth_error', $message);
    }

    /** 回调结束页：跳回前端并带上结果，前端负责提示 */
    private function donePage(string $path, string $status, string $message): Response {
        $sep = str_contains($path, '?') ? '&' : '?';
        return Response::redirect($path . $sep . 'oauth=' . rawurlencode($status) . '&message=' . rawurlencode($message));
    }
}
