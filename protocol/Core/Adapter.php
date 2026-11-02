<?php
namespace Protocol\Core;

use Utils\Logger;

/**
 * 协议适配器抽象基类
 *
 * 统一命名：内部统一使用 OneBot11 事件 / API（action）模型，适配器负责与具体协议端交互。
 */
abstract class Adapter {
	protected array $config;
	protected bool $running = false;
	/** @var string 适配器唯一标识（onebot/milky/qqbot/http） */
	protected string $name = '';
	/** @var string 人类可读名称 */
	protected string $title = '';

	public function __construct(array $config = []) {
		$this->config = $config;
	}

	/** 适配器标识 */
	public function name(): string {
		return $this->name;
	}

	/** 人类可读名称 */
	public function title(): string {
		return $this->title ?: $this->name;
	}

	public function running(): bool {
		return $this->running;
	}

	/**
	 * 调用 OneBot11 风格 API（action + params）
	 * @param string $action 例如 send_msg / get_group_list
	 * @param array $params 参数
	 * @param string $method 上游 HTTP 方法（GET/POST），部分协议端需要
	 * @return object 统一响应：{status, retcode, data, message}
	 */
	abstract public function call(string $action, array $params = [], string $method = 'POST'): object;

	/** 启动适配器（可空实现） */
	public function start(): void {
		$this->running = true;
	}

	/** 停止适配器 */
	public function stop(): void {
		$this->running = false;
	}

	/** 是否支持该 action，默认全部支持，具体适配器可覆写 */
	public function handles(string $action): bool {
		return true;
	}

	public function meta(): array {
		return [
			'name'   => $this->name(),
			'title'  => $this->title(),
			'running'=> $this->running(),
			'config' => $this->safeConfig(),
		];
	}

	/** 输出配置（过滤敏感字段） */
	protected function safeConfig(): array {
		$mask = ['token', 'secret', 'access_token', 'auth', 'client_secret'];
		$out = [];
		foreach ($this->config as $k => $v) {
			$out[$k] = in_array(strtolower((string)$k), $mask, true) ? '******' : $v;
		}
		return $out;
	}

	/** 统一的成功响应 */
	protected function ok(mixed $data = null, string $message = ''): object {
		return (object)[
			'status'  => 'ok',
			'retcode' => 0,
			'data'    => $data === null ? new \stdClass() : $data,
			'message' => $message,
		];
	}

	/** 统一的失败响应 */
	protected function fail(int $retcode, string $message = ''): object {
		return (object)[
			'status'  => 'failed',
			'retcode' => $retcode,
			'data'    => null,
			'message' => $message,
		];
	}

	protected function logInfo(string $msg): void {
		Logger::info("[协议-{$this->name()}] {$msg}");
	}

	protected function logSuccess(string $msg): void {
		Logger::success("[协议-{$this->name()}] {$msg}");
	}

	protected function logWarning(string $msg): void {
		Logger::warning("[协议-{$this->name()}] {$msg}");
	}

	protected function logError(string $msg): void {
		Logger::error("[协议-{$this->name()}] {$msg}");
	}

	protected function logDebug(string $msg): void {
		Logger::debug("[协议-{$this->name()}] {$msg}");
	}

	/** 读取自身配置 */
	protected function conf(string $key, mixed $default = null): mixed {
		$segments = explode('.', $key);
		$value = $this->config;
		foreach ($segments as $seg) {
			if (!is_array($value) || !array_key_exists($seg, $value)) {
				return $default;
			}
			$value = $value[$seg];
		}
		return $value === null || $value === '' ? $default : $value;
	}
}
