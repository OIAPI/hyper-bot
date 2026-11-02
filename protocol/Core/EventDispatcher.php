<?php
namespace Protocol\Core;

use Utils\Cache;
use Utils\Logger;
use Utils\MessageLog;
use Utils\Env;

/**
 * 统一事件分发器：把「标准 OneBot11 事件数组」分发给既有 Trigger 控制器。
 *
 * 使用场景：
 *  - Webhook 入站（Milky / QQ 官方机器人）——协议端推送 JSON，本类构造上下文并分发；
 *  - WebSocket 入站（OneBot 正向/反向、Milky WS、QQ 官方 Gateway）——同上。
 *
 * 注意：一切事件最终都会被转换成 OneBot11 事件结构，以保证既有插件零改动可用。
 */
class EventDispatcher {
	/** 允许处理的触发类型（与 Config.ini [type] 保持一致） */
	private static function allowedTypes(): array {
		$types = Env::get('type', []);
		$allowed = [];
		foreach ((array)$types as $key => $value) {
			if ($value === true || (is_numeric($value) && (int)$value === 1) || $value === '1') {
				$allowed[] = ucwords(strtolower((string)$key));
			}
		}
		return $allowed;
	}

	/**
	 * 分发一条标准 OneBot11 事件
	 * @param array $event 标准事件
	 * @param Adapter|null $adapter 事件来源适配器（决定后续 API 转发去向）
	 * @param callable|null $responder 可选：事件处理完成后的回调（参数：响应数组）
	 * @return array 响应数组（成功 {"status":"ok","retcode":0,...}）
	 */
	public static function dispatch(array $event, ?Adapter $adapter = null, ?callable $responder = null): array {
		$event = self::normalize($event);
		$postType = ucwords(strtolower((string)($event['post_type'] ?? '')));

		if ($postType === '') {
			Logger::warning('[事件分发] 缺少 post_type，已忽略：' . substr(json_encode($event, JSON_UNESCAPED_UNICODE), 0, 200));
			return self::accepted();
		}

		// 订阅类型过滤（[type] 段）
		$allowed = self::allowedTypes();
		if ($allowed !== [] && !in_array($postType, $allowed, true)) {
			Logger::debug("[事件分发] 类型 {$postType} 未订阅，已忽略");
			return self::accepted();
		}

		Cache::set('param', (object)$event);
		Cache::$adapter = $adapter;
		MessageLog::write($event, $postType);

		// 触发控制器
		$class = "\\Controller\\Trigger\\{$postType}";
		if (!class_exists($class)) {
			Logger::warning("[事件分发] 控制器不存在：{$class}");
			return self::accepted();
		}

		\Swoole\Coroutine::create(function () use ($class, $postType, $event, $responder) {
			try {
				// 通过协程上下文隔离请求参数
				Cache::set('param', (object)$event);
				$controller = new $class();
				Logger::debug("[事件分发] 已处理 {$postType} 事件");
			} catch (\Throwable $e) {
				Logger::error("[事件分发] {$postType} 处理异常：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
			} finally {
				if ($responder) {
					try {
						$responder(self::accepted());
					} catch (\Throwable $e) {
						Logger::error('[事件分发] 响应回调异常：' . $e->getMessage());
					}
				}
			}
		});

		return self::accepted();
	}

	/** 补齐公共字段 */
	public static function normalize(array $event): array {
		$event['time']   ??= time();
		$event['self_id'] ??= 0;
		$event['post_type'] ??= '';
		$event['raw_message'] ??= '';
		return $event;
	}

	private static function accepted(): array {
		return [
			'status'  => 'ok',
			'retcode' => 0,
			'data'    => ['status' => 'accepted'],
		];
	}
}
