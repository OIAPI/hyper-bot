<?php
namespace Api;

/**
 * @desc 与 群请求、好友请求 相关
 */

class handle extends app {
	/**
	 * @access public
	 * @desc 处理好友请求
	 * @param string $flag 好友请求的flag，从上报中获取
	 * @param bool $approve 是否同意，默认同意
	 * @param ?string $reason 拒绝原因
	 * @return object
	 */
	public function setFriendAddRequest(string $flag, bool $approve = true, ?string $reason = null) : object {
		$url = $this->api . '/set_friend_add_request';
		$data = ['flag'=>$flag, 'approve'=>$approve, 'reason'=>$reason];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 处理入群请求
	 * @param string|float $flag 入群请求的flag，从上报中获取
	 * @param string $type 入群请求的type，从上报中的sub_type获取，(add/invite);
	 * @param bool $approve 是否同意，默认同意
	 * @param ?string $reason 拒绝理由，只在拒绝的时候有效，可以为空
	 * @return object
	 */
	public function setGroupAddRequest(string|float $flag, string $type = 'add', bool $approve = true, ?string $reason = null) : object {
		$url = $this->api . '/set_group_add_request';
		$data = ['flag'=>$flag, 'sub_type'=>$type, 'approve'=>$approve, 'reason'=>strval($reason)];
		// $data = ['flag'=>$flag, 'approve'=>$approve, 'reason'=>strval($reason)];
		return $this->post($url, $data);
		return ($this);
	}
}