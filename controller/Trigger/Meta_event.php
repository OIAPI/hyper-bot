<?php
namespace Controller\Trigger;
use Utils\Logger;
use Utils\Cache;

class meta_event {
	public function __construct() {
		$this->event();
	}
	public function event() {
		$param = Cache::get('param');
		$metaType = strtolower((string)($param->meta_event_type ?? ''));

		// 生命周期事件（OneBot: enable/disable/connect；Milky: bot_offline；QQ官方: READY/RESUMED）
		if ($metaType === 'lifecycle') {
			$sub  = (string)($param->sub_type ?? '');
			$self = (string)($param->self_id ?? '');
			Logger::success("生命周期事件 | self_id：{$self} | sub_type：{$sub}");
			return;
		}

		// 心跳事件（部分协议端不推送 status，需容错）
		$status = $param->status ?? null;
		$enabled = (is_object($status) && ($status->app_enabled ?? null) === true) ? 'true' : 'false';
		$app     = (is_object($status) && ($status->app_good ?? null) === true) ? 'true' : 'false';
		$online  = (is_object($status) && ($status->online ?? null) === true) ? 'true' : 'false';
		$good    = (is_object($status) && ($status->good ?? null) === true) ? 'true' : 'false';
		Logger::debug("收到客户端发来的心跳 || 应用启动：{$enabled} || 在线状态：{$online} || 运行良好：{$good}");
	}
}
