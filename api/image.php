<?php
namespace Api;

/**
 * @desc 与图片相关
 */

class image extends app {
	/**
	 * @access public
	 * @desc 获取图片消息
	 * @param string $file 图片缓存文件名
	 * @return object
	 */
	public function getImage(string $file) {
		$url = $this->api . '/get_image';
		$data = ['file'=>$file];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 检测是否可以发送图片消息
	 * @return object
	 */
	public function canSendImage() {
		$url = $this->api . '/can_send_image';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 图片ocr
	 * @param string $image 图片 http/https/file/base 链接
	 * @return object
	 */
	public function ocrImage(string $image) {
		$url = $this->api . '/ocr_image';
		$data = ['image'=>$image];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 图片上传，Lagrange.OneBot 特有
	 * @param string $file 图片链接/base64
	 * @return object
	 */
	public function uploadImage(string $file) {
		$url = $this->api . '/ocr_image';
		$data = ['image'=>$image];
		return $this->post($url, $data);
	}
	
}