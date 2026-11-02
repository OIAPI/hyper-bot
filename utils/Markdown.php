<?php
namespace Utils;

class Markdown {
	public array $button = [];
	public array $buttons = [];
	public int $key = 0;
	public \Api\message $message;
	public function __construct(public $group, public $markdown) {
		$this->message = cache::$api->message;
		$this->markdown = '';
		$this->markdown($markdown);
	}
	/**
	 * @access public
	 * @设置render_data
	 * @param string $label 按钮显示文本
	 * @param string $visited 按钮按下后显示文本，默认同$label
	 * @param int $style 主题颜色 0灰色 1蓝色 默认0
	 * @return void
	 */
	public function render(string $label = '按钮', ?string $visited = null, int $style = 0) : void {
		$key = $this->key;
		$this->button[$key]['render_data']['label'] = $label;
		$this->button[$key]['render_data']['visited_label'] = $visited ?? $label;
		$this->button[$key]['render_data']['style'] = $style;
	}
	/**
	 * @access public
	 * @设置action
	 * @param int $type 按钮类型 设置 0 跳转按钮：http 或 小程序 客户端识别 scheme，设置 1 回调按钮：回调后台接口, data 传给后台，设置 2 指令按钮：自动在输入框插入 @bot data
	 * @param string $data 操作相关的数据
	 * @param bool $reply 按钮按下后是否使用回复消息，默认否
	 * @param bool $enter 按钮按下后是否发送，默认否
	 * @param string $tips 客户端不支持本action的时候，弹出的toast文案
	 * @return void
	 */
	public function action(int $type = 2, string $data = '按下按钮', bool $reply = false, bool $enter = false, string $tips = '看什么看') : void {
		$key = $this->key;
		$this->button[$key]['action']['type'] = $type;
		$this->button[$key]['action']['data'] = $data;
		$this->button[$key]['action']['reply'] = $reply;
		$this->button[$key]['action']['enter'] = $enter;
		$this->button[$key]['action']['unsupport_tips'] = $tips;
	}
	/**
	 * @access public
	 * @设置按钮id
	 * @param ?string $id 按钮id，默认为uniqid()
	 * @return void
	 */
	public function id(?string $id = null) {
		$key = $this->key;
		$this->button[$key]['id'] = ($id ?: uniqid());
	}
	/**
	 * @access public
	 * @设置permission
	 * @param int $type 0 指定用户可操作，1 仅管理者可操作，2 所有人可操作，3 指定身份组可操作（仅频道可用）
	 * @param float|array $user 有权限的用户 id 的列表
	 * @return void
	 */
	public function permission(int $type = 2, float|array|string $user = []) : void {
		$key = $this->key;
		$this->button[$key]['action']['permission']['type'] = $type;
		if($user) {
			if(is_array($user) && isset($user[0])) {
				foreach($user as $k=>$v) {
					$user[$k] = strval($v);
				}
				$this->button[$key]['action']['permission']['specify_user_ids'] = $user;
				// $this->button[$key]['action']['permission']['specify_role_ids'] = $user;
			} else {
				// if(utils::is_uin($user)) {
					$this->button[$key]['action']['permission']['specify_user_ids'] = [strval($user)];
					// $this->button[$key]['action']['permission']['specify_role_ids'] = [strval($user)];
				// } else {
					
				// }
			}
		}
	}
	/**
	 * @access public
	 * @将key向后移动一位
	 * @return void
	 */
	public function next() : void {
		$this->key += 1;
		// if($this->key > 4) $this->key = 4; //按钮最多一排5个，做限制
	}
	/**
	 * @access public
	 * @将key向前移动一位
	 * @return void
	 */
	public function prev() : void {
		$this->key -= 1;
		if($this->key < 0) $this->key = 0;
	}
	/**
	 * @access public
	 * @返回当前key的button值 不存在则反回空值
	 * @return array
	 */
	public function current() : array {
		if(isset($this->button[$this->key])) return $this->button[$this->key];
		return [];
	}
	/**
	 * @access public
	 * @将当前按钮组添加到buttons按钮列表中，随后清空button
	 * @return void
	 */
	public function buttons() : void {
		if($this->button) {
			array_push($this->buttons, ['buttons'=>$this->button]);
			$this->button = [];
			$this->key = 0;
		}
	}
	/**
	 * @access public
	 * @添加一个超链接
	 * @param ?string $title 链接名
	 * @param string $url 链接
	 * @return bool
	 */
	public function url(?string $title = null, string $url = '') : bool {
		if(utils::is_domain($url)) {
			if($title) {
				//$this->markdown .= "[🔗{$title}]({$url})  "; //[🔗腾讯网](https://www.qq.com)
				$this->markdown .= "[🔗{$title}]({$url})  "; //[🔗腾讯网](https://www.qq.com)
			} else {
				$this->markdown .= "[🔗{$url}]({$url})";
			}
			return true;
		}
		return false;
	}
	/**
	 * @access public
	 * @添加一个图片链接
	 * @param ?string $title 链接名
	 * @param string $url 链接
	 * @return bool
	 */
	public function Image(?string $title = null, string $url = '') : bool {
		if(utils::is_domain($url)) {
			try {
				$curl = new CoroutineCurl($url);
				$curl->curl();
				$Imagick = new \Imagick;
				$Imagick->readimageBlob($curl->result);
				$w = $Imagick->getImageWidth();
				$h = $Imagick->getImageHeight();
				[$w, $h] = $this->scaleImage($w, $h, 250);
				$Imagick->destroy();
				$title = ($title ?: '图片');
				$this->markdown .= "![{$title} #{$w}px #{$h}px]({$url})";
				return true;
			} catch (\Exception $e) {
				return false;
			}
		}
		return false;
	}
	/**
	 * @access public
	 * @添加markdown语法
	 * @param string $markdown markdown语法的str
	 * @return void
	 */
	public function markdown(?string $markdown) : void {
	/*
		if($url = utils::domain($markdown)) {
			// $urlOld = $url;
			// if(!str_contains($markdown, 'https://oiapi.net')) {
				// $markdown = preg_replace('/(http:\/\/)?oiapi/i', 'https://oiapi', $markdown);
				
				// if(!str_starts_with($url, 'http') || !str_starts_with($url, '//')) {
					// $urlOld = '//' . $url;
				// }
				// $url = str_replace($url, $urlOld, $markdown);
				// $markdown = str_replace($url, 'https://jump.oiapi.net/s.php/jump?url=' . $url, $markdown);
			// }
			$markdown = preg_replace("/\b(?:https?\:)?(\/\/)?(?:www\.)?((?!jump\.oiapi\.net)[a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/i", 'https://jump.oiapi.net/s.php/jump?url=$0', $markdown);
		}
		*/
		$this->markdown .= "{$markdown}";
		// echo $this->markdown;
		// $this->markdown = trim($this->markdown); //去除有可能存在的空行，以便于美观
	}
	/*
	 * @access public
	 * @desc 添加艾特
	 * @param mixed $User 用户id
	 * @return void
	 */
	public function at($User) : void {
		$this->markdown('<qqbot-at-user id="' . $User . '" />');
	}
	/*
	 * @access public
	 * @desc 添加表情
	 * @param mixed $id 表情id
	 * @return void
	 */
	public function Face($id) : void {
		$this->markdown("<emoji:{$id}>");
	}
	/**
	 * @access public
	 * @添加markdown语法
	 * @param string $markdown markdown语法的str
	 * @return void
	 */
	public function add(?string $markdown) : void {
		$this->markdown($markdown);
	}
	/**
	 * @access public
	 * @desc 清空markdown
	 * @return void
	 */
	public function cleanMarkdown() : void {
		$this->markdown = '';
	}
	/**
	 * @access public
	 * @desc 清空Button
	 * @return void
	 */
	public function cleanButton() : void {
		$this->button = [];
	}
	/**
	 * @access public
	 * @desc 清空本类
	 * @return void
	 */
	public function clean() : void {
		$this->button = [];
		$this->markdown = '';
		$this->buttons = [];
	}
	/**
	 * @access public
	 * @desc 获取本类中的某些内容
	 * @param string $key
	 * @return mixed
	 */
	public function get(string $key) {
		if(isset($this->$key)) return $this->$key;
		return null;
	}
	/**
	 * @access public
	 * @发送当前类的Markdown语法的Node
	 * @param bool $callback 是否回调，默认否
	 * @return array|Object
	 */
	public function send(bool $callback = false, bool $width = false, bool $height = false) : array|Object|bool {
		if($this->button) {
			//如果button存在
			foreach($this->button as $k=>$v) {
				if(!isset($v['action']['permission']['type'])) {
					//如果permission.type不存在
					$this->button[$k]['action']['permission']['type'] = 2;
				} else if(!is_int($v['action']['permission']['type'])) {
					//如果不是int
					$this->button[$k]['action']['permission']['type'] = intval($v['action']['permission']['type']);
				}
			}
			$this->buttons();
		}
		$button = $this->buttons;
		$curl = new CoroutineCurl;
		$curl->timeout(30);
		// $markdown = preg_replace('/\b(?:http:\/\/)?(?!.+\.)oiapi.net\b/i', 'https://oiapi.net', $this->markdown);
		// $markdown = preg_replace('/jump\.oiapi\.net\/s.php\/jump\?url=(https?\:\/\/)?oiapi\.net/i', 'oiapi.net', $markdown);
		// $markdown = preg_replace('/((https?)?\:?(\/\/)?)?(https.+)url=((?!http?s?\:\/\/).+)/i', '$4url=//$5', $markdown);
		// $markdown = str_replace(['//http', 'http:http', 'https:http'], ['http', 'http', 'http'], $markdown);
		// print_r($markdown);exit;
		$post = JSON_encode([
			'group_id' => $this->group,
			'markdown1' => $this->markdown,//preg_replace("/[\r\n]/", "\\n", $this->markdown),
			// 'rows' => $button,
			'bot' => 1,
		], 320);
		$curl->post('http://sign.oiapi.net/send.php', $post);
		// operate::set('./markdown.json', print_r($curl, true));
		if($res = $curl->Object()) {
			if($res->code === 0) {
				return $res;
			}
		}
		$curl = new CoroutineCurl;
		$curl->addheaders("content-type", "application/json");
		$curl->post('https://oiapi.net/API/screenshot/markdown', json_encode([
			'markdown' => $this->markdown,
		], 320));
		return $this->message->sendMsg(group: $this->group, message: $this->message->structureImage('base64://' . base64_encode($curl->result), subType: 0));
	}
	/**
	 * @access public
	 * @添加一个绑定按钮
	 * @param float|string $qq
	 * @return void
	 */
	public function bind(float|string $qq) : void {
		return;
		$this->buttons();
		$this->action(1, json_encode([
			'group'=>$this->group,
			'message' => 'bind',
			// 'message_type' => 'group',
			// 'post_type' => 'message',
			'user_id' => $qq,
			/*
			'sender'=>[
				'user_id' => $qq,
				'nickname' => '',
				'user_name' => ''
			]
			*/
		]));
		$this->permission(0, $qq);
		$this->render('绑定账号', style: 1);
		$this->id("bind_{$qq}");
		$curl = new CoroutineCurl();
		$curl->post('http://docs.oiapi.net/cq/button.php/register', json_encode([
			'id' => "bind_{$qq}",
			'group' => $this->group,
			'qq' => $qq,
			'callback' => 'http://docs.oiapi.net/cq/index.php',
			'self' => true
		], 320));
		$this->buttons();
	}
	private function scaleImage($originalWidth, $originalHeight, $targetWidth) {
		// 计算缩放比例
		$scaleRatio = $targetWidth / $originalWidth;
		// 如果缩放后的宽度小于450像素，则保持原样
		if ($originalWidth < 250) {
			$targetWidth = $originalWidth;
			$newHeight = $originalHeight;
		} else {
			// 计算新的高度
			$newHeight = $originalHeight * $scaleRatio;
		}
	
		// 返回新的宽度和高度
		return array(intval($targetWidth), intval($newHeight));
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
}