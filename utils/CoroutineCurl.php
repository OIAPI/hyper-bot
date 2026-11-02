<?php
namespace Utils;

use Swoole\Coroutine\Http\Client;

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
				[$k, $v] = explode(': ', $v, 2);
			}
			$this->headers[$k] = $v;
		}
	}
	public function request(): self {
		try {
			$parsedUrl = $this->parseUrl($this->url);
			$host = $parsedUrl['host'];
			$port = $parsedUrl['port'];
			$path = $parsedUrl['path'];
			$query = $parsedUrl['query'];
			
			if ($this->method === 'GET' && $this->data) {
				$query .= ($query ? '&' : '') . $this->data;
			}
	
			$client = new Client($host, $port, $parsedUrl['scheme'] === 'https');
			$client->setHeaders($this->headers);
			$client->set($this->options);
	
			// 处理不同的 HTTP 方法
			switch ($this->method) {
				case 'POST':
					$client->post($path . ($query ? "?{$query}" : ''), $this->data);
					break;
				case 'PUT':
				case 'DELETE':
				case 'PATCH':
					$client->setMethod($this->method);
					if ($this->data) {
						$client->setData($this->data);
					}
					$client->execute($path . ($query ? "?{$query}" : ''));
					break;
				default: // get
					$client->get($path . ($query ? "?{$query}" : ''));
					break;
			}
	
			$this->result = $client->body;
			$this->info = [
				'http_code' => $client->statusCode,
				'url' => $this->url,
			];
			$this->error = $client->errCode ? socket_strerror($client->errCode) : 0;
			$this->responseHeaders = $client->getHeaders();
			$this->cookies = $client->getCookies() ?? [];
			$this->opt["Header"] = $this->responseHeaders;
			$this->opt["Cookie"] = $this->cookies;
			//$client->close();
	
			// Handle response format
			switch (strtolower($this->accept)) {
				case 'json':
					$this->result = json_decode($this->result, true);
					break;
				case 'object':
					$this->result = json_decode($this->result);
					break;
			}
		} catch (\Exception $e) {
			$this->error = $e->getMessage();
			$this->info['http_code'] = $e->getCode();
		} finally {
			if(isset($client) && $client instanceof Client) {
				$client->close();
			}
		}

		return $this;
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
	
	/**
	 * 解析URL并设置相关属性
	 */
	public function parseUrl(string $url): array {
		$parsed = parse_url($url);
		
		if ($parsed === false) {
			throw new \InvalidArgumentException("Invalid URL: {$url}");
		}
		
		// 设置默认值
		$scheme = $parsed['scheme'] ?? 'http';
		$host = $parsed['host'] ?? '';
		$port = $parsed['port'] ?? null;
		$path = $parsed['path'] ?? '/';
		$query = $parsed['query'] ?? '';
		$fragment = $parsed['fragment'] ?? '';
		
		// 如果没有指定端口，根据协议设置默认端口
		if (!$port) {
			$port = ($scheme === 'https') ? 443 : 80;
		}
		
		// 可以在这里存储解析后的URL组件，方便后续使用
		return [
			'scheme' => $scheme,
			'host' => $host,
			'port' => $port,
			'path' => $path,
			'query' => $query,
			'fragment' => $fragment
		];
	}

	public function json(): array|null {
		return json_decode($this->result, true);
	}
	public function array(): array|null {
		return json_decode($this->result, true);
	}

	public function object(): object|null {
		return json_decode($this->result);
	}

	/**
	 * @access public
	 * @设置返回为string并输出
	 * @return string
	 */
	public function string() : string {
		return (string) ($this->result);
	}
	/**
	 * @access public
	 * @设置返回为string并输出
	 * @return string
	 */
	public function __toString(): string {
		return (string)$this->result;
	}
	/**
	 * 魔术方法：统一处理 GET/POST/PUT/DELETE/PATCH 请求
	 * @param string $method 调用的方法名（如 get/post/put）
	 * @param array $args 方法参数（args[0] = URL，args[1] = 数据，可选）
	 * @return $this
	 * @throws \InvalidArgumentException
	 */
	public function __call($method, $args) {
		// 只处理指定的HTTP方法，其他方法抛异常
		$allowMethods = ['get', 'post', 'put', 'delete', 'patch', 'curl'];
		if (!in_array(strtolower($method), $allowMethods)) {
			throw new \InvalidArgumentException(
				"不支持的方法：{$method}，仅支持：" . implode('/', $allowMethods)
			);
		}
	
		// 校验参数：至少需要传入URL（args[0]）
		if ((empty($args[0]) || !is_string($args[0])) && !$this->url) {
			throw new \InvalidArgumentException("第一个参数必须是有效的URL字符串");
		}
	
		// 统一赋值：方法名、URL、数据（数据可选，默认null）
		$this->method = strtoupper($method);
		$this->url = $args[0] ?? $this->url;
		$this->data = $args[1] ?? null; // 没有数据时设为null，避免空值问题
	
		// 触发请求并返回当前实例（保持链式调用）
		return $this->request();
	}
}