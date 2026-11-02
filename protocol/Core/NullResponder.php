<?php
namespace Protocol\Core;

/**
 * 空响应器：用于 WebSocket / 常驻事件等没有 HTTP 响应对象的场景。
 *
 * 既有 Trigger 会把 Cache::$server 当作 \Controller\Server\RequestHandler，
 * 但实际只在 *webhook* 分支里调用 sendSuccess/sendError；在 WS 场景下这些调用会被忽略。
 */
class NullResponder {
	public function sendSuccess(array|object $data = [], int $code = 200): void {
		// 常驻事件无响应，静默忽略
	}

	public function sendJson(array|object $data = [], int $code = 200): void {
		// noop
	}

	public function sendError(int $code = 500, string $message = ''): void {
		// 常驻事件无响应，仅记录日志
		\Utils\Logger::debug("[NullResponder] 事件处理错误（无 HTTP 响应）：{$code} {$message}");
	}

	public function registerType(string|array $types): void {
		// noop
	}

	public function isEnd(): bool {
		return true;
	}
}
