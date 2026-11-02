<?php
namespace App\Plugins;

use App\BaseApp;
use Controller\Attr\Rule;
use Controller\Enum\PermRule;
use Utils\CommandParser;
use Utils\Utils;

/**
 * @name permissio
 * @description 权限管理，查看、修改
 * @author 作者
 * @lastUpdate 时间
 * @cover 图标
 * @version 1.0
 */

class Permission extends BaseApp {
	const Permission = 6;
	private array $parse = [];
	#[Rule('/^\/?Permission .+$/i')]
	public function pn() {
		preg_match($this->app["match"], $this->raw, $s);
		// $Redis = $this->Redis;
		// $Permission = 6;
		// $memberPermission = $this->Permission($Redis->exists('memberPermission.' . $this->qq) ? $Redis->get('memberPermission.' . $this->qq) : 'default');
		$parse = CommandParser::getInstance();
		$Parser = $parse->parse($s[0]);
		$this->parse = $Parser;
		if($Parser['options']) {
			if(isset($Parser['options']['s'])) return $this->setting();
			if(isset($Parser['options']['g'])) return $this->getPermission();
		}
		return match(strtolower($Parser['arguments'][0])) {
			'setting' => $this->setting(),
			'get' => $this->getPermission(),
			default => $this->help()
		};
	}
	public function help() {
		$result = [
			"以下是 Permission 参数：",
			"",
			"setting PermissionNameOrLevel 'n[,n?]'    用来设置权限",
			"get n?                  用来获取权限",
			"help                    获取帮助",
			"","",
			"__end__",
		];
		return $this->api->message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	public function setting() {
		$user = $this->getUserPermission($this->qq);
		$message = $this->api->message;
		$to = $this->PermRule(isset($this->parse['options']['s']) ? $this->parse['options']['s'] : ($this->parse["arguments"][1])); //用户权限
		// if($this->comparePermission($to, PermRule::SUPER)) return $this->help();
		$members = isset($this->parse['options']['s']) ? $this->parse['arguments'][0] : $this->parse["arguments"][2]; //需要去的权限
		if(!$members || !$to) return $message->sendMsg(group: $this->group, message: "要设置什么？");
		if(!$this->comparePermission($user, self::Permission)) return;
		if(!$this->comparePermission($user, $to)) return $message->sendMsg(group: $this->group, message: "你只能给予比你小的权限");
		$result = [];
		$members = array_unique(explode(',', $members));
		if(Count($members) > 10) {
			$message->sendMsg(group: $this->group, message: "超过10个，取前10");
			$members = array_slice($members, 0, 10);
		}
		foreach($members as $val) {
			$member = $this->getUserPermission($val);
			if($this->comparePermission($member, $user) || $this->qq == $val) {
				array_push($result, "{$val} 设置失败，你貌似在做一些危险的事情？");
				continue;
			}
			if($this->Redis->set("memberPermission.{$val}", $to->value)) {
				array_push($result, "{$val} 设置完成");
			} else {
				array_push($result, "{$val} 设置失败");
			}
		}
		return $message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	private function getPermission() {
		$user = $this->qq;
		$message = $this->api->message;
		if(isset($this->parse['arguments'][1])) {
			$user = $this->parse['arguments'][1];
		}
		if(isset($this->parse['options']['g']) && Utils::is_uin($this->parse['options']['g'])) {
			$user = $this->parse['options']['g'];
		}
		$user = array_unique(explode(',', $user));
		if(Count($user) > 10) {
			$message->sendMsg(group: $this->group, message: "超过10个，取前10");
			$user = array_slice($user, 0, 10);
		}
		$result = [];
		foreach($user as $val) {
			array_push($result, "{$val} 的权限：" . $this->getUserPermission($val)->value);
		}
		return $message->sendMsg(group: $this->group, message: join("\n", $result));
	}
	protected function initSetGroupReaction() {
		$this->api->message->setGroupReaction($this->group, $this->message_id, $this->reaction);
	}
}