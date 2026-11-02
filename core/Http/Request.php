<?php
namespace Core\Http;

/** 不可变的请求对象。把 Swoole 请求适配成统一的抽象，控制器不再直接依赖 Swoole。 */
final class Request {
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $headers,
        public readonly string $rawBody,
        public readonly array $cookies = [],
        public readonly array $files = [],
        public readonly string $remoteIp = '',
        public readonly float $startedAt = 0.0,
    ) {}

    /** 从 Swoole\Http\Request 构造 */
    public static function fromSwoole(object $req): self {
        $server = (array)($req->server ?? []);
        $headers = [];
        foreach ((array)($req->header ?? []) as $k => $v) {
            $headers[strtolower((string)$k)] = (string)$v;
        }
        $raw = '';
        if (method_exists($req, 'rawContent')) {
            $raw = (string)($req->rawContent() ?? '');
        } elseif (isset($req->rawBody)) {
            $raw = (string)$req->rawBody;
        }
        $query = (array)($req->get ?? []);
        $post = (array)($req->post ?? []);

        $body = $post;
        if ($raw !== '' && str_contains(strtolower($headers['content-type'] ?? ''), 'json')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        } elseif ($raw !== '' && !$post) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }

        // Swoole 通常会填好 $req->cookie；但在纯 HTTP 桥接 / 测试桩里可能只有
        // Cookie 头，这里做一次兜底解析，避免会话"看着有 cookie 却读不到"。
        $cookies = (array)($req->cookie ?? []);
        if ($cookies === [] && isset($headers['cookie'])) {
            foreach (explode(';', (string)$headers['cookie']) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) {
                    continue;
                }
                [$name, $value] = explode('=', $pair, 2);
                $cookies[trim($name)] = rawurldecode(trim($value));
            }
        }

        $path = (string)($server['path_info'] ?? $query['__path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }

        return new self(
            strtoupper((string)($server['request_method'] ?? 'GET')),
            $path,
            $query,
            $body,
            $headers,
            $raw,
            $cookies,
            (array)($req->files ?? []),
            (string)($server['remote_addr'] ?? ''),
            (float)($server['request_time_float'] ?? microtime(true)),
        );
    }

    /** 合并后的参数（query + body），保持历史行为：body 覆盖 query */
    public function params(): array {
        return array_merge($this->query, $this->body);
    }

    public function input(string $key, mixed $default = null): mixed {
        $params = $this->params();
        return $params[$key] ?? $default;
    }

    public function header(string $name, string $default = ''): string {
        return (string)($this->headers[strtolower($name)] ?? $default);
    }

    public function bearer(): string {
        $auth = $this->header('authorization');
        return str_starts_with(strtolower($auth), 'bearer ') ? trim(substr($auth, 7)) : '';
    }

    /** 是否期望 JSON 响应（WebUI API 一律 JSON） */
    /** 附加当前会话（由 AuthMiddleware 注入） */
    public ?array $session = null;

    public function withSession(array $session): self {
        $clone = clone $this;
        $clone->session = $session;
        return $clone;
    }

    public function user(): array {
        return $this->session['user'] ?? [];
    }

    public function wantsJson(): bool {
        $accept = strtolower($this->header('accept'));
        return str_contains($accept, 'json') || str_starts_with($this->path, '/webui/api') || str_starts_with($this->path, '/api/v2');
    }
}