<?php
namespace App\Plugins;

use Swoole\Timer;
use Utils\Logger;
use Utils\Utils;
use Utils\Cache;
use App\BaseApp;
use Controller\Attr\Rule;

/**
 * @name sys
 * @description 系统类，包括但不限于执行 cmd
 * @author 作者
 * @lastUpdate 2024-11-4
 * @cover 图标
 * @version 1.0
 */

class sys extends BaseApp {
	#[Rule('/^reload|restart$/i', permission: 'super', name: 'reload/restart    可重启 worker 进程')]
	public function reload() {
		$http = cache::$http;
		$message = $this->api->message;
		$message->sendMsg(group: $this->group, message: 'please wait...');
		Logger::debug('正在重启…');
		if($http->reload()) {
			Logger::Warning('重启成功！');
			$this->initGroupInfoIfNeed();
			return $message->sendMsg(group: $this->group, message: '重载成功，耗时：' . round((utils::time() - cache::get('request_time')) / 1000, 2) . '秒');
		} else {
			Logger::error('重载失败');
			return $message->sendMsg(group: $this->group, message: '重载失败，请检查控制台输出。');
		}
	}
	#[Rule('/^打开麦克风交流$/', name: '打开麦克风交流', permission: 4)]
	public function openTheGroup() {
		$info = $this->getGroupInfo($this->group); //获取群信息
		$message = $this->api->message;
		if($info->status == 0) {
			$info->status = 1;
			$this->Redis->set("{$this->group}.info", json_encode($info, 320));
			$message->sendMsg(group: $this->group, message: '我已开麦，请狗叫');
		} else {
			$message->sendMsg(group: $this->group, message: '狗叫？');
		}
	}
	#[Rule('/^闭麦$/', name: '闭麦', permission: 4)]
	public function closeTheGroup() {
		$info = $this->getGroupInfo($this->group); //获取群信息
		$message = $this->api->message;
		if($info->status == 1) {
			$info->status = 0;
			$this->Redis->set("{$this->group}.info", json_encode($info, 320));
			$message->sendMsg(group: $this->group, message: '麦已闭');
		} else {
			$message->sendMsg(group: $this->group, message: '狗叫？');
		}
	}
	#[Rule('/^关闭 ?(\d+)$/', permission: 4, name: '关闭 + id    可以关闭 model')]
	public function closeApp() {
		preg_match($this->app['match'], $this->raw, $keys);
		$info = $this->getGroupInfo($this->group); //获取群信息
		$message = $this->api->message;
		$keys = $keys[1];
		$result = [];
		$App = Utils::object_keys($info->worker);
		foreach(explode(',', $keys) as $key) {
			$str = "";
			$key = $key - 1;
			if(isset($App[$key]) && $app = $App[$key]) {
				if($info->worker->$app === false) {
					$str = "{$app} 没有被开启";
				} else {
					$str = "{$app} 已关闭";
					$info->worker->$app = false;
				}
			} else {
				$str = "{$key} 不存在";
			}
			array_push($result, $str);
		}
		$this->updateGroupInfo($this->group, $info); //更新数据
		return $message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	#[Rule('/^开启 ?([0-9,]+)$/', permission: 4, name: '开启 + id    可以开启 model')]
	public function openApp() {
		preg_match($this->app['match'], $this->raw, $keys);
		$info = $this->getGroupInfo($this->group); //获取群信息
		$message = $this->api->message;
		$keys = $keys[1];
		$result = [];
		$App = Utils::object_keys($info->worker);
		foreach(explode(',', $keys) as $key) {
			$str = "";
			$key = $key - 1;
			if(isset($App[$key]) && $app = $App[$key]) {
				if($info->worker->$app === true) {
					$str = "{$app} 没有被关闭";
				} else {
					$str = "{$app} 已开启";
					$info->worker->$app = true;
				}
			} else {
				$str = "{$key} 不存在";
			}
			array_push($result, $str);
		}
		$this->updateGroupInfo($this->group, $info); //更新数据
		return $message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	protected function initSetGroupReaction() {
		$this->api->message->setGroupReaction($this->group, $this->message_id, $this->reaction);
	}
}