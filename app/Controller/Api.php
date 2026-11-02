<?php
namespace App\Controller;

use Controller\Attr\Route; //method
use Controller\Attr\RoutePrefix; //class
use Utils\Cache;
use Utils\Logger;
use Controller\Router;


#[RoutePrefix('/api')]
class Api extends Common {
	#[Route]
	public function group() {
		$group = $this->api->groupInfo->getGroupList(true);
		return $this->res(1, '获取成功', $group->data);
	}
	#[Route]
	public function getInfo() {
		$param = Cache::get('param');
		if(isset($param->group_id) && $param->group_id) {
			$list = Router::getGroupInfo($param->group_id);
			return $this->res(1, '获取成功', ($list ?? []));
		}
		return $this->res(-1, '获取失败，请输入参数');
	}
	#[Route]
	public function switch() {
		$param = Cache::get('param');
		$group_id = $param->group_id ?? 0;
		$plugin = $param->plugin ?? '';
		if($group_id && $plugin) {
			if(!class_exists('\\' . $plugin)) return $this->res(-1, 'plugin 不存在');
			if(str_contains(strtolower($plugin), 'help') || str_contains(strtolower($plugin), 'sys')) return $this->res(-1, '该插件无法关闭');
			$redis = $this->api->redis;
			$info = $redis->get("{$param->group_id}.info");
			$info = $info ? json_decode($info, true) : [];
			if(!$info) return $this->res(-2, 'group 不存在');
			if(isset($info['worker'][$plugin])) {
				$info['worker'][$plugin] = false;
				$open = false;
			} else {
				$info['worker'][$plugin] = true;
				$open = true;
			}
			$redis->set("{$param->group_id}.info", json_encode($info, 320));
			return $this->res(1, $open === true ? '开启成功' : '关闭成功');
		} elseif ($group_id) {
			$bool = false;
			if($list = $this->group()) {
				if($list['code'] === 1) {
					foreach($list['data'] as $v) {
						if($v->group_id == $group_id) $bool = true;
					}
				}
			}
			if($bool === false) return $this->res(-4, '群不存在');
			$key = "{$param->group_id}.info";
			$redis = $this->api->redis;
			if($redis->exists($key)) {
				$info = $this->getInfo()['data'];
				$info->status = $info->status === 1 ? 0 : 1;
				$redis->set("{$param->group_id}.info", json_encode($info, 320));
				return $this->res(1, ($info->status === 1 ? '开启成功' : '关闭成功'));
			} else {
				$this->getInfo();
				return $this->switch();
			}
		}
		return $this->res(-3, '获取失败，请输入参数');
	}
}