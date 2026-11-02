<?php
namespace Protocol\OneBot;

use Protocol\Core\Adapter as BaseAdapter;
use Protocol\Core\HttpAdapter;
use Protocol\Core\Bridge;
use Utils\Logger;

/**
 * OneBot11 适配器
 *
 * - 事件：实现端通过正向/反向 WS 推送，结构本身即 OneBot11，无需转换
 * - API ：优先走 WS（正向/反向均可），无 WS 连接时回落到 HTTP
 */
class Adapter extends BaseAdapter {
	protected string $name = 'onebot';
	protected string $title = 'OneBot11 (WS)';

	private ?HttpAdapter $http = null;
	private ?int $fd = null;

	public function __construct(array $config = [], ?int $fd = null) {
		$this->fd = $fd;
		parent::__construct($config);
	}

	public static function currentAdapter(?int $fd = null): self {
		return new self([], $fd);
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		/*
		 * 优先使用「事件来源连接」回包：反向 WS 的每条连接各自持有会话，
		 * 若把回复推到别的连接，实现端可能回错人或直接查不到上下文。
		 * 没有来源 fd 时（HTTP 入口 / 定时任务）才退化为「正向优先，其次任选反向」。
		 */
		if ($this->fd !== null || WsHub::hasForward() || WsHub::hasReverse()) {
			$response = WsHub::callApi($action, $params, 30.0, $this->fd);
			if ($response !== null) {
				return (object)$response;
			}
		}

		// 2) 无 WS 连接：回落 HTTP
		$this->http ??= new HttpAdapter();
		return $this->http->call($action, $params, $method);
	}

	public function meta(): array {
		$meta = parent::meta();
		$meta['forward'] = [
			'running' => WsHub::hasForward(),
			'url'     => WsHub::forwardUrl(),
		];
		$meta['reverse'] = [
			'count'    => WsHub::reverseCount(),
			'sessions' => WsHub::sessions(),
		];
		return $meta;
	}
}
