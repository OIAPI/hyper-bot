<?php
namespace Api;

/**
 * @desc 与消息相关
 */

class message extends app {
	/**
	 * @access public
	 * @desc 发送私聊消息
	 * @param string|float|int $qq 账号
	 * @param string|float|int $group 群号
	 * @param string|array $message 消息内容
	 * @param bool $auto_escape 发送内容是否是纯文本(不解析CQ码)
	 * @return object
	 */
	public function sendPrivateMsg(string|float|int $qq, string|float|int $group = 0, string|array $message = '', bool $auto_escape = false) : object {
		$data = [
			'user_id'=>$qq,
			'message'=>(is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message),
			'auto_escape'=>$auto_escape
		];
		if($group) $data['group_id'] = $group; //临时消息必填
		$url = $this->api . '/send_private_msg';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送群消息
	 * @param string|float|int $group 群号
	 * @param string|array $message 消息内容
	 * @param bool $auto_escape 发送内容是否是纯文本(不解析CQ码)
	 * @return object
	 */
	public function sendGroupMsg(string|float|int $group = 0, string|array $message = '', bool $auto_escape = false) : object {
		$data = [
			'group_id'=>$group,
			'message'=>(is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message),
			'auto_escape'=>$auto_escape
		];
		$url = $this->api . '/send_group_msg';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送消息
	 * @param string $message_type 类型(group/private)
	 * @param string|float|int $qq 账号
	 * @param string|float|int $group 群号
	 * @param string|array $message 消息内容
	 * @param bool $auto_escape 发送内容是否是纯文本(不解析CQ码)
	 * @return object
	 */
	public function sendMsg(string $message_type = 'group', string|float|int $qq = 0, string|float|int $group = 0, string|array|object $message = '', bool $auto_escape = false) : object {
		$message = json_decode(json_encode((is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message)), true);
		$data = [
			'message'=>$message,
			'auto_escape'=>$auto_escape,
		];
		if($message_type === 'group') {
			$data['group_id'] = $group;
		} else {
			$data['user_id'] = $qq;
		}
		$url = $this->api . '/send_msg';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取消息
	 * @param string|float|int $message_id 消息id（QQ 官方机器人为字符串）
	 * @return object
	 */
	public function getMsg(string|float|int $message_id) : object {
		$url = $this->api . '/get_msg';
		$data = ['message_id'=>$message_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 撤回消息
	 * @param string|float|int $message_id 消息id（QQ 官方机器人为字符串）
	 * @return object
	 */
	public function deleteMsg(string|float|int $message_id) : object {
		$url = $this->api . '/delete_msg';
		$data = ['message_id'=>$message_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 标记消息已读
	 * @param string|float|int $message_id 消息id
	 * @return object
	 */
	public function markMsgAsRead(string|float|int $message_id) : object {
		$url = $this->api . '/mark_msg_as_read';
		$data = ['message_id'=>$message_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取合并转发
	 * @param string|float|int $message_id 消息id
	 * @return object
	 */
	public function getForwardMsg(string|float|int $message_id) : object {
		$url = $this->api . '/get_forward_msg';
		$data = ['message_id'=>$message_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送假合并转发
	 * @param object|array $message 消息内容
	 * @return object
	 */
	public function sendForwardMsg(array|object $message) : object {
		$url = $this->api . '/send_forward_msg';
		$data = ['messages'=>(is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message)];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送群聊合并转发
	 * @param string|float|int $group 群号
	 * @param object|array $message 消息内容
	 * @return object
	 */
	public function sendGroupForwardMsg(string|float|int $group, array|object $message) : object {
		$url = $this->api . '/send_group_forward_msg';
		$data = ['group_id'=>$group, 'messages'=>(is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message)];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送好友合并转发
	 * @param string|float|int $qq 账号
	 * @param object|array $message 消息内容
	 * @return object
	 */
	public function sendPrivateForwardMsg(string|float|int $qq, array|object $message) : object {
		$url = $this->api . '/send_private_forward_msg';
		$data = ['user_id'=>$qq, 'messages'=>(is_array($message) ? (isset($message[0]) ? $message : [$message]) : $message)];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送点赞
	 * @param string|float|int $qq 账号
	 * @param int $times 点赞数量
	 * @return object
	 */
	public function sendLike(string|float|int $qq, int $times = 1) : object {
		$url = $this->api . '/send_like';
		$data = ['user_id'=>$qq, 'times'=>$times];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取消息历史记录
	 * @param string|float|int $group 群号
	 * @param string|float|int $message_id 起始消息id
	 * @param int $count 消息数量
	 * @return object
	 */
	public function getGroupMsgHistory(string|float|int $group, string|float|int $message_id, int $count = 20) : object {
		$url = $this->api . '/get_group_msg_history';
		$data = ['group_id'=>$group, 'message_id'=>$message_id, 'count' => $count];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 加入群聊表情接龙
	 * @param string|float|int $group 群聊账号
	 * @param string|float|int $message_id 期望加入表情接龙的消息id
	 * @param float $emoji_id 表情id
	 * @return object
	 */
	public function joinGroupEmojiChain(string|float|int $group, string|float|int $message_id, int $emoji_id) : object {
		$url = $this->api . '/join_group_emoji_chain';
		$data = ['group_id'=>$group, 'message_id' => $message_id, 'emoji_id' => $emoji_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 加入好友表情接龙
	 * @param string|float|int $qq 账号
	 * @param string|float|int $message_id 期望加入表情接龙的消息id
	 * @param float $emoji_id 表情id
	 * @return object
	 */
	public function joinFriendEmojiChain(string|float|int $qq, string|float|int $message_id, float $emoji_id) : object {
		$url = $this->api . '/join_friend_emoji_chain';
		$data = ['user_id'=>$qq, 'message_id' => $message_id, 'emoji_id' => $emoji_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取rkey
	 * @return object
	 */
	public function getRkey() : object {
		$url = $this->api . '/get_rkey';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 调用群机器人回调
	 * @param string|float|int $group 群号
	 * @param string|float|int $bot 机器人账号
	 * @param string $data1 数据1
	 * @param string $data2 数据2
	 * @return object
	 */
	public function sendGroupBotCallback(string|float|int $group, string|float|int $bot, string $data1 = '', string $data2 = '') : object {
		$url = $this->api . '/send_group_bot_callback';
		$data = ['group_id' => $group, 'bot_id' => $bot, 'data_1' => $data1, 'data_2' => $data2];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 删除群精华消息
	 * @param string|float|int $message_id 消息id
	 * @return object
	 */
	public function deleteEssenceMsg(string|float|int $message_id) : object {
		$url = $this->api . '/delete_essence_msg';
		$data = ['message_id' => $message_id, ];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群精华消息
	 * @param string|float|int $message_id 消息id
	 * @return object
	 */
	public function setEssenceMsg(string|float|int $message_id) : object {
		$url = $this->api . '/set_essence_msg';
		$data = ['message_id' => $message_id, ];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取群聊精华消息
	 * @param string|float|int $group 群聊账号
	 * @return object
	 */
	public function getEssenceMsgList(string|float|int $group) : object {
		$url = $this->api . '/get_essence_msg_list';
		$data = ['group_id' => $group, ];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 私聊戳一戳
	 * @param string|float|int $qq 账号
	 * @return object
	 */
	public function friendPokr(string|float|int $qq) : object {
		$url = $this->api . '/friend_poke';
		$data = ['user_id' => $qq, ];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 群聊戳一戳
	 * @param string|float|int $group 群聊账号
	 * @param string|float|int $qq 账号
	 * @return object
	 */
	public function groupPokr(string|float|int $group, string|float|int $qq) : object {
		$url = $this->api . '/group_poke';
		$data = ['group_id' => $group, 'user_id' => $qq];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取好友历史消息
	 * @param string|float|int $qq 好友账号
	 * @param string|float|int $message_id 消息id
	 * @param int $count 数量，默认20
	 * @return object
	 */
	public function getFriendMsgHistory(string|float|int $qq, string|float|int $message_id, int $count = 20) : object {
		$url = $this->api . '/get_friend_msg_history';
		$data = ['user_id' => $qq, 'message_id' => $message_id, 'count' => $count];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置群表情回复
	 * @param string|float|int $group 群号
	 * @param string|float|int $message_id 消息id
	 * @param string $code 表情代码
	 * @param bool $is_add 是否添加，默认true
	 * @return object
	 */
	public function setGroupReaction(string|float|int $group, string|float|int $message_id, string $code = '', bool $is_add = true) : object {
		return $this->setMsgEmojiLike($message_id, intval($code));
		$url = $this->api . '/set_group_reaction';
		$data = ['group_id'=>$group, 'message_id' => $message_id, 'code'=>$code, 'is_add' => $is_add];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置表情回复 llbot
	 * @param string|float|int $message_id 消息id
	 * @param string $code 表情代码
	 * @return object
	 */
	public function setMsgEmojiLike(string|float|int $message_id, int $emoji_id = 66) : object {
		$url = $this->api . '/set_msg_emoji_like';
		$data = ['message_id' => $message_id, 'emoji_id' => $emoji_id];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 发送pb数据包
	 * @string $cmd 指令
	 * @array $data hex之前的数据 比如{"1":1505,"2":8,"4":{"4":"1E27A59409F051E88CAEDC11D60A1591","5":102129105,"20002":1,"20037":1,"20059":1,"27025":1},"12":1}可以通过appid和openid获取真实qq昵称
	 * @return object
	 */
	public function sendPb(string $cmd, array $data) {
		$url = 'http://oiapi.net/api/Protobuf/'; //加解码接口，默认encode
		$post = ([
			'data' => json_encode($data, 32),
		]);
		$res = $this->post($url, $post);
		if($res->code === 1) {
			$res = $this->post($this->api . '/send_pb', (['cmd' => $cmd, 'hex' => $res->message]));
			if($hex = $res->data?->hex) {
				$url .= 'decode'; //改为解码
				$res = $this->post($url, (['data' => $res->data->hex]));
				if($res->code === 1) {
					return $res->data;
				}
				return $res->message;
			}
			return $res->message;
		}
		return $res->message;
	}
	/**
	 * @access public
	 * @cq码转义
	 * @param string $str
	 * @param bool $escape 正反 默认正
	 * @return ?string
	 */ 
	public function escape(?string $str, bool $escape = true) : ?string {
		if(!$str) return '';
		$from = ['&', '[', ']', ',', '\\', '"'];
		$to = ['&amp;', '&#91;', '&#93;', '&#44;', '\\\\', '\\\\\"'];
		return ($escape === true ? str_replace($from, $to, $str) : str_replace($to, $from, $str));
	}
	/**
	 * @access public
	 * @构造图片消息
	 * @param ?string $file 文件路径/Url
	 * @param ?string $type 图片类型, flash 表示闪照, show 表示秀图, 默认普通图片
	 * @param ?int $subType 图片子类型, 只出现在群聊. 0	正常图片，1	表情包, 在客户端会被分类到表情包图片并缩放显示，2	热图，3	斗图，4	智图?，7	贴图，8	自拍，9	贴图广告?，10	有待测试，13	热搜图, 14	ai表情。默认1，后改成14了
	 * @param ?string $url 图片链接，与file相比，file优先
	 * @param int $cache 只在通过网络 URL 发送时有效, 表示是否使用已缓存的文件, 默认 1
	 * @param int $width 图片显示宽度 默认 0 既自适应
	 * @param int $height 图片显示高度 默认 0 既自适应
	 * @param int $id 发送秀图时的特效id, 默认为40000，有效id：40000-40005
	 * @param int $c 通过网络下载图片时的线程数, 默认单线程. (在资源不支持并发时会自动处理)
	 * @param bool $escape 是否用cq码输出，默认不是
	 * @return array|string
	 */
	public function structureImage(?string $file = null, ?string $type = "image", ?int $subType = 1, ?string $summary = "[动画表情]", int $cache = 1, int $width = 0, int $height = 0, int $id = 40000, int $c = 1, bool $escape = false) : array|string {
		if(str_starts_with($file, "https://multimedia.nt.qq.com.cn")) $file = str_replace("https://", "http://", $file);
		return ($escape === false ? [
			'type'=>'image',
			'data'=>[
				'file'=>$file,
				// 'type'=>$type,
				'subType'=>$subType,
				'width'=>$width,
				'height'=>$height,
				'summary'=>$summary,
				'cache'=>$cache,
				'id'=>$id,
				'c'=>$c
			]
		] : "[CQ:image,file={$this->escape($file)},type={$type},cache={$cache},id={$id},c={$c}]") ?? throw new \Exception('构造图片消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造视频消息
	 * @param ?string $file 文件路径/Url
	 * @param ?string $cover 视频封面, 支持http, file和base64发送, 格式必须为jpg
	 * @param int $c 通过网络下载图片时的线程数, 默认单线程. (在资源不支持并发时会自动处理)
	 * @param bool $escape 是否用cq码输出，默认不是
	 * @return array|string
	 */
	public function structureVideo(?string $file = null, ?string $cover = null, int $c = 1, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'video',
			'data'=>[
				'file'=>$file,
				'cover'=>$cover,
				'c'=>$c
			]
		] : "[CQ:video,file={$this->escape($file)},cover={$this->escape($cover)},c={$c}]") ?? throw new \Exception('构造视频消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造语音消息
	 * @param ?string $file 文件路径/Url
	 * @param int $magic 语音类型，0默认1变声;
	 * @param int $cache 是否使用缓存，默认1使用，0不使用
	 * @param int $proxy 只在通过网络 URL 发送时有效, 表示是否通过代理下载文件 ( 需通过环境变量或配置文件配置代理 ) , 默认 1
	 * @param int $timeout 只在通过网络 URL 发送时有效, 单位秒, 表示下载网络文件的超时时间 , 默认不超时
	 * @param bool $escape 是否用cq码输出，默认不是
	 * @return array|string
	 */
	public function structureRecord(?string $file = null, int $magic = 0, int $cache = 1, int $proxy = 1, ?int $timeout = null, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'record',
			'data'=>[
				'file'=>$file,
				'magic'=>$magic,
				'cache'=>$cache,
				'proxy'=>$proxy,
				'timeout'=>$timeout
			]
		] : "[CQ:record,file={$this->escape($file)},magic={$magic},cache={$cache},proxy={proxy},timeout=$timeout]") ?? throw new \Exception('构造语音消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造表情消息
	 * @param int $id 表情id
	 * @param bool $escape 是否用cq码输出，默认不是
	 * @return array|string
	 */
	public function structureFace(int|string $id, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'face',
			'data'=>[
				'id'=>(string) $id
			]
		] : "[CQ:face,id={$id}]") ?? throw new \Exception('构造表情消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造魔法表情消息
	 * @param int $id 表情id
	 * @param string $type 魔法表情类型，rps猜拳，dice骰子，shake串口抖动，默认猜拳
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureMagic(string $type ='rps', bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>$type,
			'data'=>[
			]
		] : "[CQ:{$type}]") ?? throw new \Exception('构造魔法表情消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造礼物消息
	 * @param string|float|int $qq 账号
	 * @param int $id 0	甜 Wink，1	快乐肥宅水，2	幸运手链，3	卡布奇诺，4	猫咪手表，5	绒绒手套，6	彩虹糖果，7	坚强，8	告白话筒，9	牵你的手，10	可爱猫咪，11	神秘面具，12	我超忙的，13	爱心口罩
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureGift(string|float|int $qq, int $id = 0, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'gift',
			'data'=>[
				'qq'=>(string) $qq,
				'id'=>$id
			]
		] : "[CQ:gift,qq={$qq},id={$id}]") ?? throw new \Exception('构造魔法表情消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造戳一戳消息
	 * @param string|float|int $qq 账号
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structurePoke(string|float|int $qq, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'poke',
			'data'=>[
				'qq'=>(string) $qq
			]
		] : "[CQ:poke,qq={$qq}]") ?? throw new \Exception('构造戳一戳消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造分享链接消息
	 * @param string $url 链接
	 * @param string $title 标题
	 * @param string $content 简介
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureShare(string $url, string $title, ?string $content = null, ?string $image = null, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'share',
			'data'=>[
				'url'=>$url,
				'title'=>$title,
				'content'=>$content,
				'image'=>$image
			]
		] : "[CQ:share,url={$this->escape($url)},title={$this->escape($title)},content={$this->escape($content)},image={$this->escape($image)}]") ?? throw new \Exception('构造链接分享消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造分享好友/群消息
	 * @param string $type qq为好友，group为群
	 * @param string|float|int $id 标题
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureContact(string $type, string|float|int $id, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'contact',
			'data'=>[
				'type'=>$type,
				'id'=>$id
			]
		] : "[CQ:contact,type={$type},id={$id}]") ?? throw new \Exception('构造分享好友消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造定位消息
	 * @param float $lat 经度
	 * @param float $lon 纬度
	 * @param ?string $title 发送时可选, 标题
	 * @param ?string $content 发送时可选, 内容描述
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureLocation(float $lat, float $lon, ?string $title = null, ?string $content = null, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'location',
			'data'=>[
				'lat'=>$lat,
				'lon'=>$lon,
				'title'=>$title,
				'content'=>$content
			]
		] : "[CQ:contact,lat={$lat},lon={$lon},title={$this->escape($title)},content={$this->escape($content)}]") ?? throw new \Exception('构造定位消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造音乐分享消息
	 * @param string|int $type qq 163 xm	分别表示使用 QQ 音乐、网易云音乐、虾米音乐，custom	表示音乐自定义分享
	 * @param string|int $id 歌曲id
	 * @param ?string $url 自定义时点击跳转链接
	 * @param ?string $audio 音乐链接
	 * @param ?string $title 标题
	 * @param ?string $content 发送时可选, 内容描述
	 * @param ?string $image 图片链接
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureMusic(string|int $type, string|int $id, ?string $url = null, ?string $audio = null, ?string $title = null, ?string $content = null, ?string $image = null, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'music',
			'data'=>[
				'type'=>$type,
				'id'=>$id,
				'url'=>$url,
				'audio'=>$audio,
				'title'=>$title,
				'content'=>$content,
				'image'=>$image
			]
		] : "[CQ:music,type={$type},id={$id},url={$this->escape($url)},audio={$this->escape($audio)},title={$this->escape($title)},content={$this->escape($content)},image={$this->escape($image)}]") ?? throw new \Exception('构造音乐分享消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造回复消息
	 * @param ?int $id 引用的消息id，如果有自定义，自定义优先
	 * @param ?string $text 自定义消息内容
	 * @param ?string|float|int $qq 自定义回复时的自定义QQ, 如果使用自定义信息必须指定.
	 * @param ?float $time 时间戳
	 * @param ?float $seq 起始消息序号, 可通过 get_msg 获得
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureReply(?int $id = null, ?string $text = null, string|float|int|null $qq = null, ?float $time = null, ?float $seq = null, bool $escape = false) : array|string {
		$time = $time ?: time();
		// $text = 
		return ($escape === false ? [
			'type'=>'reply',
			'data'=>[
				'id'=>(string)$id,
				'text'=>$text ?: '',
				'qq'=>(string) $qq ?: '',
				'time'=>($time ?: time()),
				'seq'=>$seq ?: '',
			]
		] : "[CQ:reply,id={$id},text={$this->escape($text)},qq={$qq},time={$time},seq={$seq}]") ?? throw new \Exception('构造回复消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造合并转发消息
	 * @param ?string $id 直接引用他人的消息合并转发, 实际查看顺序为原消息发送顺序 与下面的自定义消息二选一
	 * @param ?string $name 用于自定义消息 (自定义消息并合并转发, 实际查看顺序为自定义消息段顺序)
	 * @param ?string|float|int $uin 用于自定义消息中的账号
	 * @param string|array|null $content 用于自定义消息 不支持转发，message消息组或者cq码
	 * @param ?float $seq 起始消息序号, 可通过 get_msg 获得 用于自定义消息
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureNode(?string $id = null, ?string $name = null, string|float|int|null $uin = null, string|array|null $content = null, ?float $seq = null, bool $escape = false) : array|string {
		$node = [
			'type'=>'node',
			'data'=>[
				'id'=>$id,
				'name'=>$name,
				'uin'=>(string) $uin,
				// 'user_id'=>$uin,
				// 'nickname'=>$name,
				'content'=>(is_array($content) ? (isset($content[0]) ? $content : [$content]) : $content),
				// 'seq'=>$seq,
			]
		];
		if (!$id) unset($node['data']['id']);
		return ($escape === false ? $node : "[CQ:node,id={$id},name={$this->escape($name)},uin={$uin},content={$content}]") ?? throw new \Exception('构造合并转发消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造卡片消息
	 * @param string $data 卡片内容
	 * @param ?int $resid 可能为空, 或空字符串
	 * @param string $type 类型，xml，json
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureCard(string $data, ?int $resid = 0, string $type = 'json', bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>$type,
			'data'=>[
				'data'=>$data,
				'resid'=>$resid,
			]
		] : "[CQ:{$type},data={htmlentities($this->escape($data))},resid={$resid}]") ?? throw new \Exception('构造卡片消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造tts语音消息
	 * @param string $text 消息内容
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureTts(string $text, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'tts',
			'data'=>[
				'text'=>$text
			]
		] : "[CQ:tts,text={$this->escape($text)}]") ?? throw new \Exception('构造tts消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造文本消息
	 * @param string $text 消息内容
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureText(mixed $text, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'text',
			'data'=>[
				'text'=>(string) $text
			]
		] : $text) ?? throw new \Exception('构造文本消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构造文本消息Pro
	 * @param string $text 消息内容
	 * @param bool $escape 是否用cq码输出，默认不是，魔法表情无法使用cq码
	 * @return array|string
	 */
	public function structureTextPro(mixed $text, ?string $title = null, ?string $desc = null, ?string $picurl = null, bool $escape = false) : array|string {
		
		return ($escape === false ? [
			'type'=>'text',
			'data'=>[
				'text'=>(string) $text,
				'title' => $title,
				'desc' => $desc,
				'picurl' => $picurl,
			]
		] : $text) ?? throw new \Exception('构造文本消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建艾特消息
	 * @param string|float|int $qq 账号
	 * @param string $name 当在群中找不到此QQ号的名称时才会生效
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureAt(float|string $qq, string $name = '此人不在群中', bool $escape = false) : string|array {
		return ($escape === false ? [
			'type'=>'at',
			'data'=>[
				'qq'=>(string) $qq,
				'name'=>$name
			]
		] : "[at:{$qq},name={$name}]") ?? throw new \Exception('构造艾特消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建json消息
	 * @param string $data json内容, json的所有字符串记得实体化处理
	 * @param int resid 默认不填为0, 走小程序通道, 填了走富文本通道发送
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureJson(string $data, int $resid = 0, bool $escape = false) : string|array {
		return ($escape === false ? [
			'type'=>'json',
			'data'=>[
				'data'=>$data,
				'resid'=>$resid
			]
		] : "[CQ:json,data={$data},resid={$resid}]") ?? throw new \Exception('构造json消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建Markdown消息
	 * @param string $data json内容, json的所有字符串记得实体化处理
	 * @param int resid 默认不填为0, 走小程序通道, 填了走富文本通道发送
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureMarkdown(string $content, bool $escape = false) : string|array {
		$content = $this->escape($content, false);
		return ($escape === false ? [
			'type'=>'markdown',
			'data'=>[
				'content'=>json_encode(['content'=>"{$content}"])
			]
		] : "[CQ:markdown,content={$content}]") ?? throw new \Exception('构造Markdown消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建longmsg消息
	 * @param string|float|int $id 消息id
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureLongMsg(string|float|int $id, bool $escape = false) : string|array {
		return ($escape === false ? [
			'type'=>'longmsg',
			'data'=>[
				'id'=>$id
			]
		] : "[CQ:longmsg,id={$id}]") ?? throw new \Exception('构造LongMsg消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建Keyboard消息
	 * @param array $rows rows内容，参考 https://bot.q.qq.com/wiki/develop/api-v2/server-inter/message/trans/msg-btn.html
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureKeyboard(array|Object $rows, bool $callback = false, bool $escape = false) : string|array {
		// return 
		$res = [
			'type'=>'keyboard',
			'data'=>[
				'content'=>[
					'rows'=>(is_array($rows) ? (isset($rows[0]) ? $rows : [$rows]) : json_decode($rows, true)),
					'botAppid' => 102055187
				]
			]
		];
		if($callback === true) $res['data']['content']['botAppid'] = 102055187;
		return ($escape === false ? $res : "[CQ:keyboard,content={$rows}]") ?? throw new \Exception('构造Keyboard消息错误', -1, null);
	}
	/**
	 * @access public
	 * @构建greytip消息
	 * @param string $content 内容
	 * @param bool $escape 是否用cq码输出，默认不是。
	 * @return array|string
	 */
	public function structureGreyTip(string $content, bool $escape = false) : string|array {
		// return 
		$res = [
			'type'=>'greytip',
			'data'=>[
				'content'=>$content
			]
		];
		return ($escape === false ? $res : "[CQ:keyboard,content={$rows}]") ?? throw new \Exception('构造Keyboard消息错误', -1, null);
	}
}