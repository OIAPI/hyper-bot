<?php
namespace Core\Http;

/** 不可变的响应对象。控制器返回它，由 Kernel 负责真正写给客户端。 */
final class Response {
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public array $headers = [],
        public string $body = '',
        /** @var list<string> Set-Cookie 值 */
        public array $cookies = [],
    ) {}

    public static function json(mixed $data, int $status = 200, array $headers = []): self {
        $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            $payload = '{"code":-1,"message":"response encoding failed"}';
            $status = 500;
        }
        return new self($status, $headers + ['Content-Type' => 'application/json; charset=utf-8'], $payload);
    }

    public static function ok(mixed $data = null, string $message = 'ok'): self {
        return self::json(['code' => 1, 'message' => $message, 'data' => $data, 'timestamp' => time()]);
    }

    public static function fail(string $message, int $code = -1, int $status = 200): self {
        return self::json(['code' => $code, 'message' => $message, 'data' => null, 'timestamp' => time()], $status);
    }

    public static function html(string $html, int $status = 200): self {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }

    public static function redirect(string $location, int $status = 302): self {
        return new self($status, ['Location' => $location], '');
    }

    public static function text(string $text, int $status = 200): self {
        return new self($status, ['Content-Type' => 'text/plain; charset=utf-8'], $text);
    }

    public function withHeader(string $name, string $value): self {
        $clone = clone $this;
        $clone->headers[$name] = $value;
        return $clone;
    }

    /** 追加 Set-Cookie（HttpOnly + SameSite=Lax，前端 JS 无法读取 token） */
    public function withCookie(string $name, string $value, int $ttl = 0, string $path = '/', bool $httpOnly = true, string $sameSite = 'Lax'): self {
        $clone = clone $this;
        $cookie = $name . '=' . rawurlencode($value);
        if ($ttl !== 0) {
            $cookie .= '; Max-Age=' . $ttl;
            $cookie .= '; Expires=' . gmdate('D, d M Y H:i:s', time() + $ttl) . ' GMT';
        } else {
            $cookie .= '; Max-Age=0';
        }
        $cookie .= '; Path=' . $path;
        if ($httpOnly) {
            $cookie .= '; HttpOnly';
        }
        if ($sameSite !== '') {
            $cookie .= '; SameSite=' . $sameSite;
        }
        $clone->cookies[] = $cookie;
        return $clone;
    }
}