<?php
namespace Api;

/**
 * @desc 与语音相关
 */

class record extends app {
	/**
	 * @access public
	 * @desc 获取语音文件缓存
	 * @param string $file 文件名
	 * @param string $out_format 要转换到的格式, 目前支持 mp3、amr、wma、m4a、spx、ogg、wav、flac
	 * @return object
	 */
	public function getRecord(string $file, string $out_format = 'mp3') : object {
		$out_format = strtolower($out_format);
		$data = ['file'=>$file, 'out_format'=>$out_format];
		$url = $this->api . '/get_record';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 检测是否可以发送语音
	 * @return object
	 */
	public function canSendRecord() : object {
		$url = $this->api . '/can_send_record';
		return $this->get($url);
	}
}