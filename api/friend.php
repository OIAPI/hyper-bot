<?php
namespace Api;

/**
 * @desc 与好友相关
 */

class friend extends app {
	/**
	 * @access public
	 * @desc 获取陌生人信息
	 * @param string|float|int $qq QQ帐号
	 * @param bool $no_cache 是否无视缓存，无视可能更新不及时
	 * @return object
	 */
	public function getStrangerInfo(float|string $qq, bool $no_cache = true) : object {
		$url = $this->api . '/get_stranger_info';
		$data = ['user_id'=>$qq, 'no_cache'=>$no_cache];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取好友列表
	 * @return object
	 */
	public function getFriendList() : object {
		$url = $this->api . '/get_friend_list';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取单项好友列表
	 * @return object
	 */
	public function getUnidirectionalFriendList() : object {
		$url = $this->api . '/get_unidirectional_friend_list';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 删除好友
	 * @param string|float|int $qq 账号
	 * @param bool $block 是否拉黑
	 * @return object
	 */
	public function deleteFriend(string|float|int $qq, bool $block = true) : object {
		$url = $this->api . '/delete_friend';
		$data = ['user_id'=>$qq, 'block' => $block];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除单项好友
	 * @param string|float|int $qq 账号
	 * @return object
	 */
	public function deleteUnidirectionalFriend(string|float|int $qq) : object {
		$url = $this->api . '/delete_unidirectional_friend';
		$data = ['user_id'=>$qq];
		return $this->post($url, $data);
	}
	
}