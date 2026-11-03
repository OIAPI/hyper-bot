<?php
namespace App\Plugins;

use Utils\Markdown;
use Utils\Git;
use Utils\Utils;
use Utils\Env;
use App\BaseApp;
use Core\Bot\Registry;
use Controller\Attr\Rule;
use Utils\CoroutineCurl as curl;

/**
 * @name help
 * @description 帮助类，可以查看帮助以及 model 的加载情况
 * @author 作者
 * @lastUpdate 2024-11-4
 * @cover 图标
 * @version 0.8
 */

class help extends BaseApp {
	#[Rule('/^\/(help|menu|菜单)$/i', name: '/help')]
	public function help() {
		$menu = Registry::menu();  // v1 的 Router::getMenu() 已并入注册表
		$message = $this->api->message;
		if($menu) {
			$markdown = new markdown($this->group, "```text\n" . join("\n", $menu) . "\n```");
			$markdown->send();
			// $message->sendMsg(group: $this->group, message: join("\n", $menu));
		} else {
			$message->sendMsg(group: $this->group, message: '看什么看');
		}
	}
	#[Rule('/^\/?(help|menu|菜单) ?(\d+)$/i', name: '/help + id    可查看 model 帮助')]
	public function helpModel() {
		preg_match($this->app['match'], $this->raw, $keys);
		$message = $this->api->message;
		$key = intval($keys[2] - 1);
		// v2：插件清单来自注册表（一次索引），不再反射扫描
		$plugins = \Core\App::boot()->container->get(Registry::class)->plugins();
		$app = array_map(static fn(array $p): string => $p['class'], $plugins);
		$docs = [];
		foreach ($plugins as $plugin) {
			$docs[$plugin['class']] = [
				'name'       => $plugin['displayName'] ?? $plugin['name'],
				'author'     => $plugin['author'] ?? '',
				'version'    => $plugin['version'] ?? '',
				'lastUpdate' => $plugin['lastUpdate'] ?? '',
				'description'=> $plugin['description'] ?? '',
			];
		}
		if(isset($app[$key]) && $model = $app[$key]) {
			if(isset($docs[$model])) {
				if($docs = $docs[$model]) {
					if(!isset($docs['name']) || !$docs['name']) return $message->sendMsg(group: $this->group, message: "{$app[$key]} 并没有任何介绍");
					return $message->sendMsg(group: $this->group, message: "名称：{$docs['name']}\n作者：{$docs['author']}\n版本：{$docs['version']}\n发布日期：{$docs['lastUpdate']}\n简介：{$docs['description']}");
				} else {
					return $message->sendMsg(group: $this->group, message: "{$app[$key]} 并没有任何介绍");
				}
			} else {
				return $message->sendMsg(group: $this->group, message: '该 model 可能不存在或未开启');
			}
		} else {
			return $message->sendMsg(group: $this->group, message: '该 model 不存在');
		}
	}
	#[Rule('/^\/?model$/i', permission: 4, name: 'model')]
	public function model() {
		$message = $this->api->message;
		$info = $this->getGroupInfo($this->group); //获取群信息
		$fn = function($app) {
			if(!$app) return false;
			$app = explode("\\", $app);
			return end($app);
		};
		$text = [];
		$worker = ($info->worker);
		$l = 1;
		foreach($worker as $k => $v) {
			$k = $fn($k);
			if($v === true) {
				array_push($text, ($l) . ". `{$k}`");
			} else {
				array_push($text, ($l) . ". `{$k} [已关闭]`");
			}
			$l++;
		}
		// print_r($text);
		$text[] = "\n### 发送 help + id";
		$markdown = new markdown($this->group, Join(PHP_EOL, $text));
		return $markdown->send();
		return $message->sendMsg(group: $this->group, message: Join(PHP_EOL, $text));
	}
	//branch 下载指定分支
	#[Rule('/^\/?下载插件 ?(.+?\.git(\?branch=.*?)?)$/i', permission: 4)]
	public function Git() {
		$message = $this->api->message;
		$url = Utils::match($this->app['match'], $this->raw)[1];
		$options = [];
		$explode = explode('?', $url);
		if(Count($explode) > 1) {
			$url = array_shift($explode);
			$array = [];
			parse_str(join('', $explode), $array);
			if(isset($array['branch']) && $array['branch']) {
				$options['branch'] = $array['branch'];
			}
			if(isset($array['branch']) && $array['branch']) {
				$options['branch'] = $array['branch'];
			}
			$name = $array['name'] ? $array['name'] : null;
		}
		$message->sendGroupMsg($this->group, "开始克隆 {$url}\n分支：" . (isset($options['branch']) ? $options['branch'] : 'main'));
		$Git = Git::getInstance(Env::get('github.localPath', __runtime__ . '/github'));
		$res = (isset($name) ? $Git->clone($url, $name, $options) : $Git->clone($url, options: $options));
		if(isset($res['success'])) {
			if($res['success'] === true) return $message->sendGroupMsg($this->group, "克隆完成： {$url}\n发送： reload 进行重载");
			return $message->sendGroupMsg($this->group, "{$res['msg']}");
		} else {
			return $message->sendGroupMsg($this->group, "未知错误，请查看命令行参数");
		}
	}
	#[Rule('/^\/?更新插件 ?(.+)$/i', permission: 4)]
	public function pullGit() {
		$message = $this->api->message;
		$url = Utils::match($this->app['match'], $this->raw)[1];
		$message->sendGroupMsg($this->group, "尝试更新 {$url}");
		$Git = Git::getInstance(Env::get('github.localPath', __runtime__ . '/github'));
		$res = ($Git->pull($url));
		if(isset($res['success'])) {
			if($res['success'] === true) return $message->sendGroupMsg($this->group, "更新完成： {$url}\n发送： reload 进行重载");
			return $message->sendGroupMsg($this->group, "{$res['msg']}");
		} else {
			return $message->sendGroupMsg($this->group, "未知错误，请查看命令行参数");
		}
	}
	#[Rule('/^\/?卸载插件 ?(.+)$/i', permission: 4)]
	public function unGit() {
		$message = $this->api->message;
		$url = Utils::match($this->app['match'], $this->raw)[1];
		$Git = Git::getInstance(Env::get('github.localPath', __runtime__ . '/github'));
		$message->sendGroupMsg($this->group, "收到卸载指令：{$url}");
		
		$res = ($Git->delete($url, true));
		if(isset($res['success'])) {
			if($res['success'] === true) return $message->sendGroupMsg($this->group, "卸载完成： {$url}\n发送： reload 进行重载");
			return $message->sendGroupMsg($this->group, "{$res['msg']}");
		} else {
			return $message->sendGroupMsg($this->group, "未知错误，请查看命令行参数");
		}
	}
	protected function initSetGroupReaction() {
		$this->api->message->setGroupReaction($this->group, $this->message_id, $this->reaction);
	}
}