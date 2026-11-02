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
		// {"app_initialized":true,"app_enabled":true,"app_good":true,"online":true,"good":true}
		// {"interval":5000,"status":{"app_initialized":true,"app_enabled":true,"app_good":true,"online":true,"good":true},"meta_event_type":"heartbeat","time":1770472483,"self_id":2830877581,"post_type":"meta_event"}
		$enabled = $param->status->app_enabled === true ? 'true' : 'false';
		$app = $param->status->app_good === true ? 'true' : 'false';
		$online = $param->status->online === true ? 'true' : 'false';
		$good = $param->status->good === true ? 'true' : 'false';
		Logger::info("收到客户端发来的心跳 || 应用启动：{$enabled} || 在线状态：{$online}");
	}
}