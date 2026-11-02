<?php
namespace Protocol\Core;

use Utils\Cache;
use Utils\Logger;

/**
 * API 转发桥：所有 Api\* 类的 HTTP 调用最终都经过这里，
 * 由当前事件上下文对应的协议适配器去执行，未命中时回落到「默认后端」。
 *
 * 默认后端由 [protocol] default 决定（http / onebot / milky / qqbot）：
 *  - 有事件上下文时永远优先用事件来源适配器（保证回复回到同一条连接/同一个账号）；
 *  - 没有事件上下文时（定时任务、WebUI 主动发消息、CLI 工具），
 *    以前固定回落 {cq.api} HTTP，导致「已经改用 Milky / 官方机器人」的部署
 *    在这类主动发送场景仍然把消息发给旧协议端。现在按配置选择。
 */
class Bridge {
	private static ?Adapter $fallback = null;

	public static function call(string $action, array $params = [], string $method = 'POST'): object {
		$params = self::enrich($action, $params);
		$adapter = Context::current();
		if ($adapter !== null && $adapter->handles($action)) {
			try {
				return self::shape($adapter->call($action, $params, $method));
			} catch (\Throwable $e) {
				Logger::error("[协议桥] 适配器 {$adapter->name()} 调用 {$action} 失败：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
			}
		}

		$fallback = self::fallback();
		try {
			return self::shape($fallback->call($action, $params, $method));
		} catch (\Throwable $e) {
			Logger::error("[协议桥] 默认后端（{$fallback->name()}）调用 {$action} 失败：{$e->getMessage()}");
			return (object)['status' => 'failed', 'retcode' => -1, 'data' => null, 'message' => $e->getMessage()];
		}
	}

	/**
	 * 补齐协议差异要求的参数
	 *
	 * 典型场景：OneBot11 的 delete_msg 只带 message_id，因为协议端自己知道
	 * 这条消息属于哪个会话；但 Milky 的 recall 接口要求 message_scene + peer_id，
	 * 官方机器人要求 group_openid/user_openid 才能拼出 URL。
	 * 这些信息其实都在当前事件上下文里（Cache::get('param')），
	 * 与其要求每个插件调用点自己补，不如在桥这一层统一补。
	 */
	private static function enrich(string $action, array $params): array {
		if (!in_array($action, ['delete_msg', 'recall_msg', 'get_msg'], true)) {
			return $params;
		}
		$event = Cache::get('param');
		if (!is_object($event) && !is_array($event)) {
			return $params;
		}
		$event = (array)$event;
		$hasTarget = !empty($params['group_id']) || !empty($params['user_id'])
			|| !empty($params['group_openid']) || !empty($params['user_openid']);
		if ($hasTarget) {
			return $params;
		}
		foreach (['group_id', 'group_openid', 'user_id', 'user_openid', 'message_type'] as $field) {
			if (!empty($event[$field]) && empty($params[$field])) {
				$params[$field] = $event[$field];
			}
		}
		return $params;
	}

	/**
	 * 统一响应结构中的 data 形态。
	 *
	 * 历史业务代码（如 app/Plugins/Group.php）从 HTTP 协议端直连拿到的 data 是
	 * json_decode() 默认形态（对象 → stdClass，列表 → stdClass[]），并直接写
	 * $res->data->message_id。而新适配器（Milky / QQ 官方 / OneBot-WS）内部用数组。
	 * 若不做归一化，同一段插件代码在「旧 HTTP 部署」能跑、换成 Milky 就静默拿到 null。
	 *
	 * 因此这里把关联数组递归转成 stdClass，使 data 在两条路径上完全一致。
	 */
	private static function shape(object $response): object {
		if (isset($response->data)) {
			$response->data = self::shapeValue($response->data);
		}
		return $response;
	}

	private static function shapeValue(mixed $value): mixed {
		if (is_array($value)) {
			// 列表保持数组（旧形态就是 stdClass[]），关联数组转 stdClass
			if (array_is_list($value)) {
				return array_map(fn($v) => self::shapeValue($v), $value);
			}
			$obj = new \stdClass();
			foreach ($value as $k => $v) {
				$obj->{$k} = self::shapeValue($v);
			}
			return $obj;
		}
		return $value;
	}

	/**
	 * 无事件上下文时的默认后端
	 *
	 * 注意：这里**按进程缓存**是安全的——缓存的是适配器实例（无会话状态），
	 * 真正有状态的是 WS 连接（由 WsHub 持有），适配器只是调用入口。
	 */
	public static function fallback(): Adapter {
		if (self::$fallback === null) {
			self::$fallback = self::makeDefault();
		}
		return self::$fallback;
	}

	/** 按 [protocol] default 构造默认后端 */
	private static function makeDefault(): Adapter {
		$backend = Config::defaultBackend();
		$enabled = Config::bool($backend . '.enable', false);
		if ($backend !== 'http' && !$enabled) {
			// 配置指向了未启用的后端：说明部署其实还在用 HTTP 协议端
			Logger::debug("[协议桥] 默认后端 {$backend} 未启用，回落 HTTP");
			return new HttpAdapter();
		}
		return match ($backend) {
			'onebot' => new \Protocol\OneBot\Adapter(),
			'milky'  => new \Protocol\Milky\Adapter(),
			'qqbot'  => new \Protocol\QqBot\Adapter(),
			default  => new HttpAdapter(),
		};
	}

	/**
	 * 该 URL 是否指向协议端自身（{cq.api} 前缀）
	 *
	 * 只有协议端的调用才是 OneBot action，第三方 HTTP 接口必须直连。
	 */
	public static function isProtocolUrl(string $url): bool {
		$base = rtrim((string)\Utils\Env::get('cq.api', ''), '/');
		if ($base === '') {
			return false;
		}
		return str_starts_with($url, $base);
	}

	/**
	 * 这次调用是否需要经协议桥
	 *
	 * 命中条件：URL 属于协议端，并且
	 *  - 当前事件有来源适配器（事件驱动的回复），或
	 *  - 默认后端不是 http（主动发送场景也应走新协议）
	 */
	public static function routes(string $url): bool {
		if (!self::isProtocolUrl($url)) {
			return false;
		}
		return self::hasAdapter() || self::fallback()->name() !== 'http';
	}

	/** 当前是否绑定了非 HTTP 适配器（协程安全：读的是协程上下文） */
	public static function hasAdapter(): bool {
		$adapter = Cache::get('adapter');
		return $adapter instanceof Adapter && $adapter->name() !== 'http';
	}

	/** 测试用：重置默认后端缓存 */
	public static function resetFallback(): void {
		self::$fallback = null;
	}
}
