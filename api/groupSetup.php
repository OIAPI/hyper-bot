<?php
namespace Api;

/**
 * @desc 与群设置相关
 */

class groupSetup extends app {
	/**
	 * @access public
	 * @desc 设置群名
	 * @param string|float|int $group 群号
	 * @param string $group_name 要设置的群名
	 * @return object
	 */
	public function setGroupName(string|float|int $group, string $group_name) : object {
		$url = $this->api . '/set_group_name';
		$data = ['group_id'=>$group, 'group_name'=>$group_name];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置头像
	 * @param string|float|int $group 群号
	 * @param string $file 图片链接，可以file://，url，base64://
	 * @return object
	 */
	public function setGroupPortrait(string|float|int $group, string $file) : object {
		$url = $this->api . '/set_group_portrait';
		$data = ['group_id'=>$group, 'file'=>$file];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置管理员
	 * @param string|float|int $group 群号
	 * @param string|float|int $qq 账号
	 * @param bool $enable true设置 false取消
	 * @return object
	 */
	public function setGroupAdmin(string|float|int $group, string|float|int $qq, bool $enable = true) : object {
		$url = $this->api . '/set_group_admin';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'enable'=>$enable];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群名片
	 * @param string|float|int $group 群号
	 * @param string|float|int $qq 账号
	 * @param ?string $card 群名片，不填或空为删除群名片
	 * @return object
	 */
	public function setGroupCard(string|float|int $group, string|float|int $qq, ?string $card = null) : object {
		$url = $this->api . '/set_group_card';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'card'=>$card];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群头衔
	 * @param string|float|int $group 群号
	 * @param string|float|int $qq 账号
	 * @param ?string $special_title 群头衔，不填或空为删除群头衔
	 * @param int $duration 专属头衔有效期, 单位秒, -1 表示永久, 不过此项似乎没有效果, 可能是只有某些特殊的时间长度有效, 有待测试
	 * @return object
	 */
	public function setGroupSpecialTitle(string|float|int $group, string|float|int $qq, ?string $special_title = null, int $duration = -1) : object {
		$url = $this->api . '/set_group_special_title';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'special_title'=>$special_title, 'duration'=>$duration];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 群单人禁言
	 * @param string|float|int $group 群号
	 * @param string|float|int $qq 账号
	 * @param float $duration 时长，单位秒 默认1800，0为取消禁言
	 * @return object
	 */
	public function setGroupBan(string|float|int $group, string|float|int $qq, float $duration = 1800): object {
		$url = $this->api . '/set_group_ban';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'duration'=>$duration];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群全体禁言
	 * @param string|float|int $group 群号
	 * @param bool $enable 是否禁言，默认true
	 * @return object
	 */
	public function setGroupWholeBan(string|float|int $group, bool $enable = true) : object {
		$url = $this->api . '/set_group_whole_ban';
		$data = ['group_id'=>$group, 'enable'=>$enable];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 群匿名用户禁言
	 * @param string|float|int $group 群号
	 * @param object|array|null $anonymous 可选, 要禁言的匿名用户对象（群消息上报的 anonymous 字段）
	 * @param ?string $flag 可选, 要禁言的匿名用户的 flag（需从群消息上报的数据中获得）
	 * @param float $duration 禁言时长, 单位秒, 无法取消匿名用户禁言
	 * @return object
	 */
	public function setGroupAnonymousBan(string|float|int $group, object|array|null $anonymous = null, ?string $flag = null, float $duration = 1800) : object {
		$url = $this->api . '/set_group_anonymous_ban';
		$data = ['group_id'=>$group, 'anonymous'=>$anonymous, 'flag'=>$flag, 'duration'=>$duration];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置精华消息
	 * @param string|float|int $message_id 消息id
	 * @return object
	 */
	public function setEssenceMsg(string|float|int $message_id) : object {
		$url = $this->api . '/set_essence_msg';
		$data = ['message_id'=>$message_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 群打卡
	 * @param string|float|int $group 群号
	 * @return object
	 */
	public function sendGroupSign(string|float|int $group) : object {
		$url = $this->api . '/send_group_sign';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群匿名开关
	 * @param string|float|int $group 群号
	 * @param bool $enable 开关 true开false关，默认true
	 * @return object
	 */
	public function setGroupAnonymous(string|float|int $group, bool $enable = true) : object {
		$url = $this->api . '/set_group_anonymous';
		$data = ['group_id'=>$group, 'enable'=>$enable];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送群公告
	 * @param string|float|int $group 群号
	 * @param string $content 公告内容
	 * @param ?string $image 公告 image 链接, 支持 http/https/file/base64
	 * @return object
	 */
	public function sendGroupNotice(string|float|int $group, string $content, ?string $image = null) : object {
		$url = $this->api . '/_send_group_notice';
		$data = ['group_id'=>$group, 'content'=>$content, 'image'=>$image];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除群公告
	 * @param string|float|int $group 群号
	 * @param string $notice_id 公告id
	 * @return object
	 */
	public function delGroupNotice(string|float|int $group, string $notice_id) : object {
		$url = $this->api . '/_del_group_notice';
		$data = ['group_id'=>$group, 'notice_id'=>$notice_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群公告
	 * @param string|float|int $group 群号
	 * @return object
	 */
	public function getGroupNotice(string|float|int $group) : object {
		$url = $this->api . '/_get_group_notice';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 群组踢人
	 * @param string|float|int $group 群号
	 * @param string|float|int $qq 账号
	 * @param bool $reject_add_request 是否拒绝此人进群，默认false
	 * @return object
	 */
	public function setGroupKick(string|float|int $group, string|float|int $qq, bool $reject_add_request = false) : object {
		$url = $this->api . '/set_group_kick';
		$data = ['group_id'=>$group, 'user_id'=>$qq, 'reject_add_request'=>$reject_add_request];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 退出群
	 * @param string|float|int $group 群号
	 * @param bool $is_dismiss 是否解散(只在Bot是群主时候有用)，默认false [已废弃]
	 * @return object
	 */
	public function setGroupLeave(string|float|int $group, bool $is_dismiss = false) : object {
		$url = $this->api . '/set_group_leave';
		$data = ['group_id'=>$group];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群机器人状态
	 * @param string|float|int $group 群号
	 * @param string|float|int $bot bot 账号
	 * @param int $enable 是否开启
	 * @return object
	 */
	public function setGroupBotStatus(string|float|int $group, string|float|int $bot, int $enable = 0) : object {
		$url = $this->api . '/set_group_bot_status';
		$data = ['group_id'=>$group, 'bot_id' => $bot, 'enable'=>$enable];
		return $this->post($url, $data);
	}
}