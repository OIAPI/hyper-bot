<?php
namespace Api;

use Controller\Enum\GroupHonorType;

/**
 * @desc 与群信息相关
 */

class groupInfo extends app {
	/**
	 * @access public
	 * @desc 获取群信息
	 * @param float $group 群号
	 * @param bool $no_cache 是否不使用缓存，默认是
	 * @return object
	 */
	public function getGroupInfo(float $group, bool $no_cache = true) : object {
		$url = $this->api . '/get_group_info';
		$data = ['group_id'=>$group, 'no_cache'=>$no_cache];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群列表
	 * @param bool $no_cache 是否不使用缓存，默认是
	 * @return object
	 */
	public function getGroupList(bool $no_cache = true) : object {
		$url = $this->api . '/get_group_list';
		$data = ['no_cache'=>$no_cache];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群成员信息
	 * @param float $group 群号
	 * @param float $qq 账号
	 * @param bool $no_cache 是否不使用缓存，默认是
	 * @return object
	 */
	public function getGroupMemberInfo(float $group, float $qq, bool $no_cache = true) : object {
		$url = $this->api . '/get_group_member_info';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'no_cache'=>$no_cache];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群成员列表
	 * @param float $group 群号
	 * @param bool $no_cache 是否不使用缓存，默认是
	 * @return object
	 */
	public function getGroupMemberList(float $group, bool $no_cache = true) : object {
		$url = $this->api . '/get_group_member_list';
		$data = ['group_id'=>$group, 'no_cache'=>$no_cache];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群荣誉信息
	 * @param float $group 群号
	 * @param string $type 要获取的群荣誉类型, 可传入 talkative performer legend strong_newbie emotion 以分别获取单个类型的群荣誉数据, 或传入 all 获取所有数据
	 * @return object
	 */
	public function getGroupHonorInfo(float $group, GroupHonorType $type = GroupHonorType::ALL) : object {
		$url = $this->api . '/get_group_honor_info';
		$data = ['group_id'=>$group, 'type'=>$type->value];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群系统信息
	 * @return object
	 */
	public function getGroupSystemMsg() : object {
		$url = $this->api . '/get_group_system_msg';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取群精华列表
	 * @param float $group 群号
	 * @return object
	 */
	public function getEssenceMsgList(float $group) : object {
		$url = $this->api . '/get_essence_msg_list';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群艾特全体剩余次数
	 * @param float $group 群号
	 * @return object
	 */
	public function getGroupAtAllRemain(float $group) : object {
		$url = $this->api . '/get_group_at_all_remain';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
}