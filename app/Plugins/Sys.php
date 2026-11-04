<?php
namespace App\Plugins;

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
		// 计时用 microtime：v1 的 cache::get('request_time') 在 v2 已经没人写，
		// 取到 null 会让「耗时」变成 unix 时间戳（1793817313.15 秒就是这么来的）。
		$start = microtime(true);
		$http = cache::$http;
		$message = $this->api->message;

		// 和面板的「重新加载」做同一件事：清全局开关缓存 + 让注册表重扫。
		// 以前群里 reload 只调 $http->reload()，不重扫，所以新装的插件要等
		// 后台点一次「重新加载」才看得到。
		$registry = null;
		try {
			$registry = \Core\App::boot()->container->get(\Core\Bot\Registry::class);
			\Core\Bot\PluginState::flush();
			$registry->invalidate();
			$registry->load(true);
		} catch (\Throwable $e) {
			Logger::debug('reload 重扫注册表失败：' . $e->getMessage());
		}

		$count = 0;
		if ($registry !== null) {
			try {
				$count = count($registry->plugins());
			} catch (\Throwable) {
				$count = 0;
			}
		}

		// Cache::$http 只在 Swoole 的 HttpServer 里赋值。PHP 内置服务器没有常驻
		// worker（每请求重新加载），重扫这一步已经足够。
		$restarted = false;
		if (is_object($http) && method_exists($http, 'reload')) {
			$restarted = (bool)$http->reload();
		}
		$elapsed = round(microtime(true) - $start, 3);

		if ($restarted) {
			Logger::Warning('重启成功！');
			$this->initGroupInfoIfNeed();
			return $message->sendMsg(group: $this->group, message: '重载成功：已重扫 ' . $count . ' 个插件，耗时 ' . $elapsed . ' 秒；worker 正在重启，稍后新插件/开关即生效。');
		}
		return $message->sendMsg(group: $this->group, message: '已重扫插件：当前 ' . $count . ' 个，耗时 ' . $elapsed . ' 秒。本架构没有常驻 worker，改动在下一条消息生效。');
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
		// v3：序号对应注册表（文件扫描）里的插件顺序，worker 只存开关状态
		$plugins = \Core\App::boot()->container->get(\Core\Bot\Registry::class)->plugins();
		$classes = array_map(static fn(array $p): string => (string)($p['class'] ?? ''), $plugins);
		$worker = (array)($info->worker ?? []);
		foreach(explode(',', $keys) as $key) {
			$str = "";
			$key = $key - 1;
			if(isset($classes[$key]) && $app = $classes[$key]) {
				$short = basename(str_replace('\\', '/', $app));
				if(($worker[$app] ?? true) === false) {
					$str = "{$short} 没有被开启";
				} else {
					$str = "{$short} 已关闭";
					$worker[$app] = false;
				}
			} else {
				$str = "{$key} 不存在";
			}
			array_push($result, $str);
		}
		$info->worker = $worker;
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
		// v3：序号对应注册表（文件扫描）里的插件顺序，worker 只存开关状态
		$plugins = \Core\App::boot()->container->get(\Core\Bot\Registry::class)->plugins();
		$classes = array_map(static fn(array $p): string => (string)($p['class'] ?? ''), $plugins);
		$worker = (array)($info->worker ?? []);
		foreach(explode(',', $keys) as $key) {
			$str = "";
			$key = $key - 1;
			if(isset($classes[$key]) && $app = $classes[$key]) {
				$short = basename(str_replace('\\', '/', $app));
				if(($worker[$app] ?? true) === true) {
					$str = "{$short} 没有被关闭";
				} else {
					$str = "{$short} 已开启";
					$worker[$app] = true;
				}
			} else {
				$str = "{$key} 不存在";
			}
			array_push($result, $str);
		}
		$info->worker = $worker;
		$this->updateGroupInfo($this->group, $info); //更新数据
		return $message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	protected function initSetGroupReaction() {
		$this->api->message->setGroupReaction($this->group, $this->message_id, $this->reaction);
	}
}