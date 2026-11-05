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
		// 没显式指定 fd 时，优先复用「当前事件来源」的适配器：
		// 多账号 / 多条反向 WS 时，new self() 会丢掉来源连接，WsHub 只能任选一条，
		// 偶发把回复发到别的连接（实现端没有该群上下文 → 看起来就是「有时不回」）。
		if ($fd === null) {
			$incoming = \Core\Bot\Context::adapter();
			if ($incoming instanceof self) {
				return $incoming;
			}
		}
		return new self([], $fd);
	}

	/**
	 * 只发不等回执：外部 WebHook 通知用。
	 *
	 * GitHub 要求 10 秒内响应，而 call() 里的 WS 会等实现端 echo（默认 30 秒），
	 * 连接异常时会把回调拖超时。通知类消息不需要 message_id，写完就返回。
	 */
	public function sendNoWait(string $action, array $params = []): bool {
		$params = (array)\Core\Extension\Hooks::filter('onebot.call', $params, $action);
		if ($this->fd !== null || WsHub::hasForward() || WsHub::hasReverse()) {
			if (WsHub::sendApi($action, $params, $this->fd)) {
				return true;
			}
		}
		$this->http ??= new HttpAdapter();
		$response = $this->http->call($action, $params);
		return is_object($response) && (string)($response->status ?? '') !== 'failed';
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		// 插件钩子：发出去之前可以改参数（例如统一加 auto_escape、改写消息段）
		$params = (array)\Core\Extension\Hooks::filter('onebot.call', $params, $action);
		/*
		 * 优先使用「事件来源连接」回包：反向 WS 的每条连接各自持有会话，
		 * 若把回复推到别的连接，实现端可能回错人或直接查不到上下文。
		 * 没有来源 fd 时（HTTP 入口 / 定时任务）才退化为「正向优先，其次任选反向」。
		 */
		if ($this->fd !== null || WsHub::hasForward() || WsHub::hasReverse()) {
			$response = WsHub::callApi($action, $params, 30.0, $this->fd);
			if (is_array($response)) {
				return (object)$response;
			}
			if ($response === false) {
				// 已经发到 WS 上了，只是等不到 echo：不能再回落 HTTP，
				// 否则 send_* 会被投递两次（协议端可能已经发出去了）。
				return $this->fail(-1, "OneBot WS 调用超时：{$action}（已发出，未收到 echo，请勿重试以避免重复发送）");
			}
		}

		// 2) 确实没有 WS 连接：回落 HTTP
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