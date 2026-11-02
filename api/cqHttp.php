<?php
namespace Api;

/**
 * @desc 与客户端相关
 */

class cqHttp extends app {
	/**
	 * @access public
	 * @desc 获取cookies
	 * @param ?string $domain Cookie域名，例如：vip.qq.com、y.qq.com、qun.qq.com;
	 * @return object
	 */
	public function getCookies(?string $domain = null) : object {
		$url = $this->api . '/get_cookies';
		$data = ['domain'=>$domain];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取Client
	 * @return object
	 */
	public function getClientKey(?string $domain = null) : object {
		$url = $this->api . '/get_clientkey';
		$data = ['domain'=>$domain];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取skey
	 * @param ?string $domain Cookie域名，例如：vip.qq.com、y.qq.com、qun.qq.com;
	 * @return object
	 */
	public function getSkey(?string $domain = null) : object {
		$url = $this->api . '/get_skey';
		$data = ['domain'=>$domain];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取csrf token，用于刷新Cookie等
	 * @return object
	 */
	public function getCsrfToken() : object {
		$url = $this->api . '/get_csrf_token';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取qq相关接口凭证，上面两个接口的合并方法
	 * @param ?string $domain Cookie域名，例如：vip.qq.com、y.qq.com、qun.qq.com;
	 * @return object
	 */
	public function getCredentials(?string $domain = null) : object {
		$url = $this->api . '/get_credentials';
		$data = ['domain'=>$domain];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取版本信息
	 * @tips 如果该接口出错，证明cq运行错误了
	 * @return object
	 */
	public function getVersionInfo() : object {
		$url = $this->api . '/get_version_info';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取状态
	 * @tips 所有统计信息都将在重启后重置
	 * @return object
	 */
	public function getStatus() : object {
		$url = $this->api . '/get_status';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 重启cq
	 * @param float $delay 延迟时间，默认2000毫秒，最小2000
	 * @tips 该 API 由于技术原因，自 1.0.0 版本已被移除，目前暂时没有再加入的计划 #[https://github.com/Mrs4s/go-cqhttp/issues/1230]
	 * @return object
	 */
	public function setRestart(float $delay = 2000) : object {
		if($delay < 2000) $delay = 2000;
		$url = $this->api . '/set_restart';
		$data = ['delay'=>$delay];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 重载时间过滤器
	 * @param string $file 过滤文件
	 * @return object
	 */
	public function reloadEventFilter(string $file) : object {
		$url = $this->api . '/reload_event_filter';
		$data = ['file'=>$file];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 下载文件到缓存目录
	 * @param string $url 链接
	 * @param int $thread_count 线程数量
	 * @param string|array $headers 头部 ["User-Agent=YOUR_UA","Referer=https://www.baidu.com"] 或者 User-Agent=YOUR_UA\nReferer=https://www.baidu.com
	 * @return object
	 */
	public function downloadFilter(string $url, int $thread_count, string|array $headers = []) : object {
		$url = $this->api . '/download_file';
		$data = ['url'=>$url, 'thread_count'=>$thread_count, 'headers'=>$headers];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 检查链接安全性
	 * @param string $url 链接
	 * @return object
	 */
	public function checkUrlSafely(string $domain) : object {
		$url = $this->api . '/check_url_safely';
		$data = ['url' => $domain];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 清理缓存
	 * @realize 实现于拉格日朗的oneBot11
	 * @return object
	 */
	public function cleanCache(): object {
		$url = $this->api . '/clean_cache';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取自定义 Face
	 * @realize 实现于Lagrange.OneBot
	 * @return object
	 */
	public function fetchCustomFace(): object {
		$url = $this->api . '/fetch_custom_face';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 获取 mFace key
	 * @realize 实现于Lagrange.OneBot
	 * @param array $emoji_ids Emoji id 列表
	 * @return object
	 */
	public function fetchMFaceFace(array $emoji_ids): object {
		$url = $this->api . '/fetch_mface_key';
		$data = ['emoji_ids' => $emoji_ids];
		return $this->post($url, $data);
	}
}