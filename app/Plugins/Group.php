<?php
namespace App\Plugins;

use Controller\Attr\Notice;
use Controller\Attr\Request;
use Controller\Attr\Rule;
use App\BaseApp;
use Utils\Logger;
use Utils\Utils;

/**
 * @name group
 * @description 群的一些操作（入群欢迎、退群通知、同意/拒绝请求）
 * @author oiapi
 * @lastUpdate 2025-01-01
 * @cover 图标
 * @version 1.0
 */

class group extends BaseApp {
	#[Notice('group_increase')]
	public function groupAdd() {
		$message = $this->api->message;
		if($this->input->operator_id) {
			$message->sendMsg(group: $this->group, message: "{$this->input->operator_id} 同意了 {$this->qq} 加入本群");
		} else {
			$message->sendMsg(group: $this->group, message: "{$this->qq} 加入了本群");
		}
		$key = "{$this->group}.{$this->qq}.request";
		if($res = json_decode($this->Redis->get($key))) {
			$this->Redis->del($key);
			$key = "{$this->group}.{$res->id}.request";
			$this->Redis->del($key);
		}
	}
	#[Notice('group_decrease')]
	public function groupDelete() {
		$message = $this->api->message;
		switch($this->input->sub_type) {
			case 'kick':
				return $message->sendMsg(group: $this->group, message: "{$this->qq} 被 {$this->input->operator_id} 踢出了本群，到底发生了什么呢？");
			break;
			default:
				return $message->sendMsg(group: $this->group, message: "{$this->qq} 退出了本群");
		}
	}
	/**
	#[Notice('friend_add')]
	public function friendAdd() {
		return $this->__initFriendList();
	}
	*/
	#[Request]
	public function request() {
		$message = $this->api->message;
		$bool = false;
		if($this->group == 637615159 || $this->group == 130558695) $bool = true;
		if($this->input->invitor_id) {
			$msg = "{$this->input->invitor_id} 邀请 {$this->qq} 进入本群";
		} else {
			$msg = "{$this->qq} 正在申请进群";
		}
		$handle = $this->api->handle;
		if($bool === true) {
			if(str_contains(strtolower($this->input->comment ?? ''), 'oiapi.net') === true) {
				print_r($this->input);
				Logger::debug(print_r($handle->setGroupAddRequest($this->input->flag, $this->input->sub_type, true), true));
				return $message->sendMsg(group: $this->group, message: "{$this->qq} 申请入群时，问题答案相符合，已自动同意！");
			} else {
				
			}
		}
		$msg .= ($this->input->comment ? "\n 入群信息：{$this->input->comment}" : '');
		$key = "{$this->group}.{$this->qq}.request";
		$result = $message->sendMsg(group: $this->group, message: $msg);
		$this->Redis->set($key, json_encode([
			'sub_type' => $this->input->sub_type,
			'flag' => $this->input->flag,
			'qq' => $this->qq,
			'id' => $result->data->message_id,
		]));
		$key = "{$this->group}.{$result->data->message_id}.request";
		return $this->Redis->set($key, json_encode([
			'sub_type' => $this->input->sub_type,
			'flag' => $this->input->flag,
			'qq' => $this->qq,
			'id' => $result->data->message_id,
		]));
	}
	#[Rule('/^(\[cq:reply.*)?同意 ?(\d*)$/i', permission: 3, name: '同意')]
	public function OkAdd() {
		$message = $this->api->message;
		$handle = $this->api->handle;
		$key = "{$this->group}.{$this->qq}.request";
		if($this->input->message[0]->type == 'reply') {
			$key = "{$this->group}.{$this->input->message[0]->data->id}.request";
		} else if(preg_match($this->app['match'], $this->raw, $rep)){
			if(isset($rep[2]) && $rep[2] && utils::is_uin($rep[2])) {
				$key = "{$this->group}.{$rep[2]}.request";
			} else {
				return $message->sendMsg(group: $this->group, message: "输入账号有误");
			}
		} else {
			return $message->sendMsg(group: $this->group, message: "请回复或携带账号");
		}
		if($this->Redis->exists($key)) {
			$res = json_decode($this->Redis->get($key));
			($handle->setGroupAddRequest($res->flag, $res->sub_type, true));
			return $message->sendMsg(group: $this->group, message: "已处理 {$res->qq} 的入群申请，如无反应请手动处理");
		} else {
			return $message->sendMsg(group: $this->group, message: "缓存中并不存在此消息，请检查是否有误");
		}
	}
	#[Rule('/^(\[cq:reply.*)?拒绝 ?(.*)$/i', permission: 3, name: '拒绝')]
	public function NoAdd() {
		$message = $this->api->message;
		$handle = $this->api->handle;
		$key = "{$this->group}.{$this->qq}.request";
		$response = null;
		if($this->input->message[0]->type == 'reply') {
			$key = "{$this->group}.{$this->input->message[0]->data->id}.request";
			preg_match($this->app['match'], $this->raw, $rep);
			if(isset($rep[2]) && $rep[2]) $response = $rep[2];
			unset($rep);
		} else if(preg_match($this->app['match'], $this->raw, $rep)){
			if(isset($rep[2]) && $rep[2] && utils::is_uin($rep[2])) {
				$key = "{$this->group}.{$rep[2]}.request";
			} else {
				return $message->sendMsg(group: $this->group, message: "输入账号有误");
			}
		} else {
			return $message->sendMsg(group: $this->group, message: "请回复或携带账号");
		}
		if($this->Redis->exists($key)) {
			$res = json_decode($this->Redis->get($key));
			$handle->setGroupAddRequest($res->flag, $res->sub_type, false, $response);
			return $message->sendMsg(group: $this->group, message: "已处理 {$res->qq} 的入群申请，如无反应请手动处理");
		} else {
			return $message->sendMsg(group: $this->group, message: "缓存中并不存在此消息，请检查是否有误");
		}
	}
}