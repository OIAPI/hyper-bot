<?php
namespace utils;

class Utils {
	private function __construct(){}
	private function __clone(){}
	/**
	 * @access public
	 * @json|object 格式化输出
	 * @param array|object $array 需要输出的内容
	 * @return string
	 */
	public static function json(array|object $array) {
		return json_encode($array, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	}
	/**
	 * Cookie转json
	 * @access public
	 * @param array $Cookie 需要转换的Cookie
	 * @return array
	 */
	public static function Cookie2json(string $Cookie) {
		$e = [];
		foreach(explode(';', $Cookie) as $v) {
			if(preg_match('/(.+?)=(.+)/', $v, $preg)) {
				$e[trim($preg[1])] = $preg[2];
			}
		}
		return $e;
	}
	/**
	 * json转Cookie
	 * @access public
	 * @param array $json jsonCookie
	 * @return string
	 */
	public static function json2Cookie(array $json) {
		$Cookie = '';
		foreach($json as $k=>$v) {
			if(is_array($v) || is_object($v)) {
				$Cookie .= "{$k}=". json_encode($v, 320) . '; ';
			} else {
				$Cookie .= "{$k}={$v}; ";
			}
			// echo $k, $Cookie;
		}
		return $Cookie;
		return trim($Cookie, '; ');
	}
	public static function header2json(string|array $header) {
		if(is_array($header)) return $header;
		$json = array();
		preg_match_all("/(.+):\s*(.+)/", $header, $h);
		foreach($h[1] as $k => $v) {
			$json[$v] = $h[2][$k];
		}
		return $json;
	}
	/**
	 * bytes转换为可读大小
	 * @access public
	 * @param mixed $bytes bytes
	 * @param int $r 返回小数点位数
	 * @return string
	 */
	public static function B2Size(mixed $bytes, int $r = 2) {
		if ($bytes >= 1073741824)
		{
			$bytes = number_format($bytes / 1073741824, $r) . ' GB';
		}
		elseif ($bytes >= 1048576)
		{
			$bytes = number_format($bytes / 1048576, $r) . ' MB';
		}
		elseif ($bytes >= 1024)
		{
			$bytes = number_format($bytes / 1024, $r) . ' KB';
		} else {
			$bytes = $bytes . ' bytes';
		}
		return $bytes;
	}
	/**
	 * 秒转时间
	 * @access public
	 * @param int $seconds 秒数
	 * @return string 时间
	 */
	public static function formatTime($seconds) {
		$units = [
			"天" => 24 * 60 * 60,
			"小时" => 60 * 60,
			"分钟" => 60,
			"秒" => 1
		];
		
		$timeComponents = [];
		
		foreach ($units as $unit => $value) {
			if ($seconds >= $value) {
				$amount = floor($seconds / $value);
				$timeComponents[] = "{$amount}{$unit}";
				$seconds -= $amount * $value;
			}
		}
		
		return join(' ', $timeComponents) ?: 0;
	}
	/**
	 * 一个字符串内，将大写转换为小写，小写转换为大写
	 * @access public
	 * @param $str 需要转换的字符串
	 * @return string
	 */
	public static function A2a($str) {
		return preg_replace_callback('/[A-Za-z]/', function($match) {
			$match = join('', $match);
			return ctype_upper($match) ? strtolower($match) : strtoupper($match);
		}, $str);
	}
	/**
	 * 数字格式化成汉字
	 * @access public
	 * @param number $figure 待格式化的数字
	 * @param boolean $capital 使用汉字大写数字
	 * @param boolean $mode 单字符转换模式
	 * @return string
	 */
	public static function ChineseNumerals($figure, $capital = false, $mode = true) {
		$numberChar = ['零', '一', '二', '三', '四', '五', '六', '七', '八', '九'];
		$unitChar = ['', '十', '百', '千', '', '万', '亿', '兆', '京', '垓', '秭', '穣', '沟', '涧', '正', '载', '极', '恒河沙', '阿僧祇', '那由他', '不可思议', '无量大数'];
		if ($capital !== false) {
			$numberChar = ['零', '壹', '贰', '叁', '肆', '伍', '陆', '柒', '捌', '玖'];
			$unitChar = ['', '拾', '佰', '仟', '', '万', '亿', '兆', '京', '垓', '秭', '穣', '沟', '涧', '正', '载', '极', '恒河沙', '阿僧祇', '那由他', '不可思议', '无量大数'];
		}
		$dec = "点";
		$target = '';
		$matches = [];
			if ($mode) {
			preg_match("/^0*(\d*)\.?(\d*)/", $figure, $matches);
		} else {
			preg_match("/(\d*)\.?(\d*)/", $figure, $matches);
		}
			list(, $number, $point) = $matches;
			if ($point) {
			$target = $dec . self::toChineseNumerals($point, $capital, false);
		}
			if (!$number) {
			return $target;
		}
		$str = strrev($number);
		for ($i = 0; $i < strlen($str); $i++) {
			$out[$i] = $numberChar[$str[$i]];
			if ($mode === false) {
				continue;
			}
			$out[$i] .= $str[$i] != '0' ? $unitChar[$i % 4] : '';
			if ($i > 0 && $str[$i] + $str[$i - 1] == 0) {
				$out[$i] = '';
			}
			if ($i % 4 == 0) {
				$temp = substr($str, $i, 4);
				$out[$i] = str_replace($numberChar[0], '', $out[$i]);
				if (strrev($temp) > 0) {
					$out[$i] .= $unitChar[4 + floor($i / 4)];
				} else {
					$out[$i] .= $numberChar[0];
				}
			}
		}
		return join('', array_reverse($out)) . $target;
	}
	/**
	 * 返回一个uuid
	 * @access public
	 * @param ?string $prefix 字首
	 * @return string
	 */
	public static function uuid(?string $prefix=""){
		$chars = md5(uniqid(mt_rand(), true));
		$uuid = substr ( $chars, 0, 8 ) . '-'
			. substr ( $chars, 8, 4 ) . '-'
			. substr ( $chars, 12, 4 ) . '-'
			. substr ( $chars, 16, 4 ) . '-'
			. substr ( $chars, 20, 12 );
		return $prefix.$uuid ;
	}
	/**
	* 判断传入的是否是QQ号/群号
	* @param mixed $uin QQ号|群号
	* @return Bool
	*/
	public static function is_uin($uin = null)
	{
		if(!$uin) return false;
		if(preg_match('/^[1-9][0-9]{4,11}$/', $uin)) return true;
		return false;
	}
	/**
	 * base64
	 * @access public
	 * @param String $value 被base64的字符串
	 * @param Bool $encode true为加密其他为解密;
	 * @return String
	 */
	public static function base(String $value, $encode = true) {
		if($encode === true) return self::a2A(base64_encode($value));
		return base64_decode(self::a2A($value));
	}
	/**
	 * 判断是否是手机号
	 * @access public
	 * @param mixed $phone_number 手机号
	 * @return Bool
	 */
	public static function is_phone($phone_number) {
		$phone_number = (String) $phone_number;
		$pattern = '/^1[3-9]\d{9}$/';
		if (preg_match($pattern, $phone_number)) {
			return true;
		} else {
			return false;
		}
	}
	/**
	 * @name
	 * @desc 检测文本中是否包含域名
	 * @param string $text
	 * @return bool|string
	 */
	public static function domain($text) {
		// 正则表达式匹配域名
		$pattern = '/\b(?:https?:\/\/)?(?:www\.)?([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})\b/';
		// 使用preg_match函数检查文本中是否存在匹配的域名
		if (preg_match($pattern, $text, $matches)) {
			// print_r($matches);
			return $matches[1]; // 包含域名
		} else {
			return false; // 不包含域名
		}
	}
	/**
	 * 判断是否是域名
	 * @access public
	 * @param String $domain 域名
	 * @param Bool $v4 是否启用IPV4检测
	 * @return String|Bool
	 */
	public static function is_domain(string $domain, Bool $v4 = false)
	{
		$domain = filter_var($domain, FILTER_SANITIZE_URL);
		$explode = explode('/', $domain);
		foreach($explode as $v)
		{
			$v = reset(explode(':', $v));
			if(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return ($v4 === true ? $v : false);
			if(filter_var($v, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) return false;
			if($v == 'http' || $v == 'https' || !$v) continue;
			if(!str_starts_with($v, 'http')) $v = "http://{$v}";
			// print_r(filter_var($v, FILTER_VALIDATE_URL));
			if(filter_var($v, FILTER_VALIDATE_URL)) {
				$domain = end(explode('/', $v));
				if(preg_match('/.+\..+/', $domain)) {
					return $domain;
				}
			}
		}
		return false;
	}
	/**
	 * 获取Cookie
	 * @access public
	 * @param $key Cookie地址
	 * @param $robot 提供Cookie的账号
	 * @param $url Cookie的api，可以自己搞，我的不定时寄
	 * @return string
	 */
	public static function cookie($key, $word = false, $robot = 2830877581, $url = '') {
		$url = $url ?: 'http://docs.oiapi.net/cq/c.php';
		$curl = new curl($url, 'domain=' . $key);
		$curl->curl();
		$Cookie = self::Cookie2json($curl->result);
		return $word === true ? (strtolower($key) == 'skey' ? $Cookie['skey'] : (strtolower($key) == 'robot' ? str_replace('o', '', $Cookie['uin']) : $Cookie['p_skey'])) : self::json2Cookie($Cookie);
	}
	/**
	 * 获取Cookie
	 * @access public
	 * @param $key Cookie地址
	 * @param $robot 提供Cookie的账号
	 * @param $url Cookie的api，可以自己搞，我的不定时寄
	 * @return string
	 */
	public static function cookie2($key, $word = false, ) {
		$url = 'http://Swoole.lkaa.top/';
		$curl = new curl($url, 'domain=' . $key);
		$curl->curl();
		$Cookie = $curl->json()['data'];
		// print_r($Cookie);exit;
		return $word === true ? (strtolower($key) == 'skey' ? $Cookie['skey'] : (strtolower($key) == 'robot' ? str_replace('o', '', $Cookie['uin']) : $Cookie['p_skey'])) : self::json2Cookie($Cookie);
	}
	/**
	 * QQ的bkn/gtk计算方法
	 * @access public
	 * @param String $skey skey或者pskey
	 * @param String|int $hash hash
	 * @return string
	 */
	public static function GTK($skey, $hash = 5381) {
		$len = strlen((String)$skey);
		for ($i = 0; $i < $len; $i++) {
			$hash += ($hash << 5 & 2147483647) + ord($skey[$i]) & 2147483647;
			$hash &= 2147483647;
		}
		return $hash & 2147483647;
	}
	/**
	 * @Access public
	 * @desc 检测skey长度是否合规
	 * @param ?string $skey skey
	 * @param bool $p 是否为检测p_skey的长度
	 * @return bool
	 */
	public static function is_skey(?string $skey = null, bool $p = false) : bool {
		if($skey) {
			if($p) {
				if(strlen($skey) == 44) return true;
				return false;
			} else {
				if(strlen(str_replace('@', '', $skey)) == 10 || strlen(str_replace('@', '', $skey)) == 9) return true;
				return false;
			}
		}
		return false;
	}
	/**
	 * @获取毫秒时间戳
	 * @return int
	 */
	public static function time() {
		$microtime = microtime();
		list($microseconds, $seconds) = explode(' ', $microtime);
		$milliseconds = round($microseconds * 1000);
		return intval($seconds . $milliseconds);
	}
	/**
	 * @name scaleImage
	 * @desc 等比例缩放
	 * @param int $originalWidth 原来宽度
	 * @param int $originalHeight 原来高度
	 * @param int $targetWidth 缩放宽度，高度会随之变化
	 * @param int $maxWidth 如果缩放后的宽度小于$maxWidth像素，则保持原样
	 * @return array [width, height]
	 */
	public static function scaleImage(int $originalWidth, int $originalHeight, int $targetWidth, int $maxWidth = 40) : array {
		// 计算缩放比例
		$scaleRatio = $targetWidth / $originalWidth;
		if ($originalWidth < $maxWidth) {
			$targetWidth = $originalWidth;
			$newHeight = $originalHeight;
		} else {
			// 计算新的高度
			$newHeight = $originalHeight * $scaleRatio;
		}
	
		// 返回新的宽度和高度
		return array(intval($targetWidth), intval($newHeight));
	}
	public static function array2List(array $array, $level = 0) {
		$output = '';
		$i = 1;
		foreach ($array as $key => $value) {
			$indent = str_repeat('	', $level);
			if (is_array($value)) {
				$output .= $indent . $i . '. ' . $key . ' => Array' . "\n";
				$output .= self::array2List($value, $level + 1);
			} else {
				$output .= $indent . $i . '. ' . $key . ' => ' . $value . "\n";
			}
			$i++;
		}
		return trim($output);
	}
	public static function match($reg, $raw) {
		return preg_match($reg, $raw, $match) ? $match : [];
	}
	/**
	 * 按中文标点分段，支持保留/不保留标点符号
	 * @param string $str 待处理文本
	 * @param string $pattern 匹配的中文标点正则（默认包含句号、感叹号、问号、逗号）
	 * @param bool $keep 是否保留标点符号（true保留，false不保留），默认false
	 * @return array 分段结果
	 */
	public static function split(string $str, string $pattern = '/[。！？，]/u', bool $keep = false) {
		// 根据是否保留标点，调整正则和preg_split标志
		if ($keep) {
			// 保留标点：正则需要捕获分组，同时开启DELIM_CAPTURE标志
			$capturePattern = '/(' . trim($pattern, '/') . ')/u'; // 包裹捕获组
			$flags = PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY;
		} else {
			// 不保留标点：直接用原正则，不捕获分隔符
			$capturePattern = $pattern;
			$flags = PREG_SPLIT_NO_EMPTY;
		}
	
		// 分割文本
		$segments = preg_split($capturePattern, $str, -1, $flags);
		$result = [];
	
		if ($keep) {
			// 保留标点时：需要将内容和标点重组（内容+标点为一段）
			$temp = '';
			foreach ($segments as $segment) {
				// 检查是否为标点（利用捕获的分隔符）
				if (preg_match($pattern, $segment)) {
					$result[] = $temp . $segment;
					$temp = '';
				} else {
					$temp .= $segment;
				}
			}
			// 处理末尾剩余内容
			if (!empty($temp)) {
				$result[] = $temp;
			}
		} else {
			// 不保留标点时：分割后的片段直接作为结果（已自动过滤空值）
			$result = $segments;
		}
	
		return $result;
	}
	/**
	 * 转换 Router array 为 key => false
	 * @param array $array 要转换的数组
	 * @param array $filter 要过滤的数组，存在的内容为true
	 */
	public static function fill(array &$array, array $filter = []) {
		if(is_array($array)) {
			$Router = [];
			foreach($array as $key => $value) {
				$Router[$value] = in_array($value, $filter);
			}
			$array = $Router;
			unset($Router);
		}
	}
	/**
	 * @access public
	 * @desc 获取对象键名列表
	 * @param object $object 对象
	 * @return array
	 */
	public static function object_keys(object $object): array {
		return array_keys(get_object_vars($object));
	}
	/**
	 * @access public
	 * @desc 优化原来的http_build_query，使用PHP_QUERY_RFC3986标准
	 * @param mixed $data
	 * @return string|false
	 */
	public static function http_build_query($data) {
		return http_build_query($data, '', '&', PHP_QUERY_RFC3986);
	}
	public static function urlencode($data) {
		return self::http_build_query($data);
	}
}