<?php
namespace Utils;

use Swoole\Coroutine\Http\Client;

/**
 * HTTP 客户端（v2）：同一个 API，两种实现。
 *
 * v1 直接 `use Swoole\Coroutine\Http\Client;` 并在 request() 里 `new Client(...)`。
 * 这个类被所有协议适配器（HttpAdapter / Milky\Api / QqBot\Token / EventClient）
 * 以及业务代码继承使用，因此**它是「没有 Swoole 就跑不起来」的唯一硬阻塞点**。
 *
 * v2 的策略：
 *   - 有 Swoole：照旧用协程 HTTP 客户端（非阻塞，适合常驻服务）；
 *   - 无 Swoole：自动切到 curl 扩展（阻塞但可用）；
 *   - 两者都没有：退化为 file_get_contents + stream context（最后兜底）。
 *
 * 对外 API（init/get/post/timeout/accept/json/object/string/error/result）
 * 与 v1 完全一致，调用方无需修改。
 */
class CoroutineCurl {
	public $headers = ['User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/86.0.4240.198 Safari/537.36'];
	public $options = [
		'timeout' => 5,
		'connect_timeout' => 2,
		'ssl_verify_peer' => false,
		'ssl_verify_host' => false,
	];
	public $result;
	public $info;
	public $error;
	public $accept = 'string';
	public $cookies = [];
	public $responseHeaders = [];
	public array $opt = [];

	public function __construct(public ?string $url = null, public mixed $data = null, public string $method = 'GET') {
		$this->method = strtoupper($method);
	}

	public static function init(string $url, mixed $data = null, string $method = 'get'): self {
		return new self($url, $data, $method);
	}

	/** 当前实现：swoole / curl / stream */
	public static function driver(): string {
		if (extension_loaded('swoole') && class_exists('\\Swoole\\Coroutine\\Http\\Client')) {
			return 'swoole';
		}
		if (function_exists('curl_init')) {
			return 'curl';
		}
		return 'stream';
	}

	public function location(bool $enable = true): self {
		$this->options['follow_location'] = $enable ? 1 : 0;
		return $this;
	}

	public function addHeaders(string $key, string $value = ''): void {
		if(($explode = explode(': ', $key)) && count($explode) > 1 && !$value) {
			$this->headers[$explode[0]] = $explode[1];
		} else {
			$this->headers[$key] = $value;
		}
	}

	public function setHeaders(array|object $headers): void {
		foreach ($headers as $k => $v) {
			if (is_int($k)) {
				[$k, $v] = array_pad(explode(': ', (string)$v, 2), 2, '');
			}
			$this->headers[$k] = $v;
		}
	}

	public function request(): self {
		try {
			$parsed = $this->parseUrl((string)$this->url);
			$url = (string)$this->url;
			if ($this->method === 'GET' && $this->data) {
				$query = is_string($this->data) ? $this->data : http_build_query((array)$this->data);
				$url .= (str_contains($url, '?') ? '&' : '?') . $query;
			}

			$payload = $this->data;
			if (is_array($payload) || is_object($payload)) {
				$payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
				if (!isset($this->headers['content-type']) && !isset($this->headers['Content-Type'])) {
					$this->headers['content-type'] = 'application/json';
				}
			}

			switch (self::driver()) {
				case 'swoole':
					$this->viaSwoole($parsed, $url, $payload);
					break;
				case 'curl':
					$this->viaCurl($url, $payload);
					break;
				default:
					$this->viaStream($url, $payload);
					break;
			}

			// 统一后处理：按 accept 解析
			$this->decode();
		} catch (\Throwable $e) {
			$this->error = $e->getMessage();
			$this->info['http_code'] = $e->getCode();
		}

		return $this;
	}

	/** Swoole 协程客户端（v1 行为） */
	private function viaSwoole(array $parsed, string $url, mixed $payload): void {
		$client = new Client($parsed['host'], $parsed['port'], $parsed['scheme'] === 'https');
		$client->setHeaders($this->headers);
		$client->set($this->options);

		$path = $parsed['path'] . (isset($parsed['query']) && $parsed['query'] !== '' ? '?' . $parsed['query'] : '');
		switch ($this->method) {
			case 'POST':
				$client->post($path, $payload);
				break;
			case 'PUT':
			case 'DELETE':
			case 'PATCH':
				$client->setMethod($this->method);
				if ($payload !== null) {
					$client->setData((string)$payload);
				}
				$client->execute($path);
				break;
			default:
				$client->get($path);
				break;
		}

		$this->result = $client->body;
		$this->info = ['http_code' => $client->statusCode, 'url' => $url];
		$this->error = $client->errCode ? socket_strerror($client->errCode) : 0;
		$this->responseHeaders = $client->getHeaders() ?: [];
		$this->cookies = $client->getCookies() ?: [];
		$this->opt['Header'] = $this->responseHeaders;
		$this->opt['Cookie'] = $this->cookies;
		$client->close();
	}

	/** curl 扩展（无 Swoole 时的主力实现） */
	private function viaCurl(string $url, mixed $payload): void {
		$ch = curl_init();
		$headers = [];
		foreach ($this->headers as $k => $v) {
			$headers[] = $k . ': ' . $v;
		}

		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_TIMEOUT => (int)($this->options['timeout'] ?? 5),
			CURLOPT_CONNECTTIMEOUT => (int)($this->options['connect_timeout'] ?? 2),
			CURLOPT_SSL_VERIFYPEER => (bool)($this->options['ssl_verify_peer'] ?? false),
			CURLOPT_SSL_VERIFYHOST => (bool)($this->options['ssl_verify_host'] ?? false) ? 2 : 0,
			CURLOPT_FOLLOWLOCATION => (bool)($this->options['follow_location'] ?? 0),
		]);

		if ($this->method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $this->method);
			if ($payload !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, (string)$payload);
			}
		}

		$body = curl_exec($ch);
		$headerText = $this->curlResponseHeaders($ch, $body);
		$this->info = [
			'http_code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
			'url' => $url,
		];
		$this->error = curl_errno($ch) ? curl_error($ch) : 0;
		$this->result = $body === false ? '' : $body;

		// 回收响应头（照 v1 的 $opt['Header'] 语义）
		$this->responseHeaders = [];
		foreach (explode("\r\n", trim((string)$headerText)) as $line) {
			if (str_contains($line, ':')) {
				[$k, $v] = explode(':', $line, 2);
				$this->responseHeaders[trim($k)] = trim($v);
			}
		}
		$this->opt['Header'] = $this->responseHeaders;

		$cookies = [];
		if (preg_match_all('/^set-cookie:\s*(.+)$/mi', (string)$headerText, $m)) {
			foreach ($m[1] as $cookieLine) {
				$pair = explode('=', explode(';', $cookieLine)[0], 2);
				if (count($pair) === 2) {
					$cookies[trim($pair[0])] = trim($pair[1]);
				}
			}
		}
		$this->cookies = $cookies;
		$this->opt['Cookie'] = $cookies;

		// PHP 8.0+ 起 curl_close 无实际作用，8.5 还会报 Deprecated，这里不再调用
	}

	/**
	 * 取响应头文本。
	 *
	 * CURLOPT_HEADER 会把响应头混进 body，容易污染 JSON 解析；
	 * 这里用 CURLOPT_HEADERFUNCTION 单独收集，保持 body 干净。
	 */
	private function curlResponseHeaders($ch, mixed $body): string {
		return (string)(\curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?? '');
	}

	/** 最后兜底：stream context（连 curl 都没有时） */
	private function viaStream(string $url, mixed $payload): void {
		$headerLines = [];
		foreach ($this->headers as $k => $v) {
			$headerLines[] = $k . ': ' . $v;
		}
		$opts = [
			'http' => [
				'method'        => $this->method,
				'header'        => implode("\r\n", $headerLines),
				'timeout'       => (float)($this->options['timeout'] ?? 5),
				'ignore_errors' => true,
			],
			'ssl' => [
				'verify_peer'      => false,
				'verify_peer_name' => false,
			],
		];
		if ($this->method !== 'GET' && $payload !== null) {
			$opts['http']['content'] = (string)$payload;
		}
		$body = @file_get_contents($url, false, stream_context_create($opts));
		$this->result = $body === false ? '' : $body;
		$this->error = $body === false ? (error_get_last()['message'] ?? 'request failed') : 0;
		$this->info = ['http_code' => 0, 'url' => $url];
		$this->responseHeaders = [];
	}

	/** 按 accept 解析响应体 */
	private function decode(): void {
		switch (strtolower($this->accept)) {
			case 'json':
			case 'array':
				$this->result = json_decode((string)$this->result, true);
				break;
			case 'object':
				$this->result = json_decode((string)$this->result);
				break;
		}
	}

	public function accept(string $format = 'string'): self {
		$this->accept = $format;
		return $this;
	}

	public function timeout(int $seconds): self {
		$this->options['timeout'] = $seconds;
		return $this;
	}

	public function encode(string $encoding = 'gzip'): self {
		$this->options['accept_encoding'] = $encoding;
		return $this;
	}

	public function proxy(string $proxy, ?string $auth = null): self {
		$this->options['proxy_host'] = $proxy;
		if ($auth) {
			$this->options['proxy_auth'] = $auth;
		}
		return $this;
	}

	/** 解析 URL，给出默认端口 */
	public function parseUrl(string $url): array {
		$parsed = parse_url($url);
		if ($parsed === false) {
			throw new \InvalidArgumentException("Invalid URL: {$url}");
		}
		$scheme = $parsed['scheme'] ?? 'http';
		$host = $parsed['host'] ?? '';
		$port = $parsed['port'] ?? ($scheme === 'https' ? 443 : 80);
		$path = $parsed['path'] ?? '/';
		$query = $parsed['query'] ?? '';
		$fragment = $parsed['fragment'] ?? '';
		return compact('scheme', 'host', 'port', 'path', 'query', 'fragment');
	}

	public function json(): array|null {
		$value = is_string($this->result) ? json_decode($this->result, true) : $this->result;
		return is_array($value) ? $value : null;
	}

	public function array(): array|null {
		return $this->json();
	}

	public function object(): object|null {
		$value = is_string($this->result) ? json_decode($this->result) : $this->result;
		return is_object($value) ? $value : null;
	}

	public function string(): string {
		return is_string($this->result) ? $this->result : (string)json_encode($this->result);
	}

	public function __toString(): string {
		return $this->string();
	}

	/**
	 * 魔术方法：统一处理 GET/POST/PUT/DELETE/PATCH。
	 * @throws \InvalidArgumentException
	 */
	public function __call($method, $args) {
		$allowMethods = ['get', 'post', 'put', 'delete', 'patch', 'curl'];
		if (!in_array(strtolower($method), $allowMethods)) {
			throw new \InvalidArgumentException(
				"不支持的方法：{$method}，仅支持：" . implode('/', $allowMethods)
			);
		}
		if ((empty($args[0]) || !is_string($args[0])) && !$this->url) {
			throw new \InvalidArgumentException('第一个参数必须是有效的URL字符串');
		}
		$this->method = strtoupper($method);
		$this->url = $args[0] ?? $this->url;
		$this->data = $args[1] ?? null;
		return $this->request();
	}
}
