<?php
namespace Api;

/**
 * @desc 与 bot 自身相关
 */

class bot extends app {
	/**
	 * @access public
	 * @desc 获取登录账号的信息
	 * @return object
	 */
	public function getLoginInfo() {
		$url = $this->api . '/get_login_info';
		return $this->get($url);
	}
	/**
	 * @access public
	 * @desc 设置账号信息
	 * @param string $nickname 名称
	 * @param ?string $company 公司
	 * @param ?string $email 邮箱
	 * @param ?string $college 学校
	 * @param ?string $personal_note 个人介绍
	 * @return object
	 */
	public function setQQProfile(string $nickname, ?string $company = null, ?string $email = null, ?string $college = null, ?string $personal_note = null) : object {
		$url = $this->api . '/set_qq_profile';
		$data = [
			'nickname'=>$nickname,
			'company'=>$company,
			'email'=>$email,
			'college'=>$college,
			'personal_note'=>$personal_note
		];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取在线机型
	 * @param string $model 机型名称
	 * @return object
	 */
	public function getModelShow(string $model = '') : object {
		$data = ['model'=>$model];
		$url = $this->api . '/_get_model_show';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置在线机型
	 * @param string $model 机型名称
	 * @param string $model_show -
	 * @return object
	 */
	public function setModelShow(string $model = '', string $model_show = '') : object {
		$data = ['model'=>$model, 'model_show'=>$model_show];
		$url = $this->api . '/_set_model_show';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取在线客户端列表
	 * @param bool $cache 是否无视缓存
	 * @return object
	 */
	public function getOnlineClients(bool $cache = true) {
		$data = ['no_cache'=>$cache];
		$url = $this->api . '/get_online_clients';
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 设置QQ头像
	 * @param string $file file 链接, 支持 http/https/file/base64
	 * @return object
	 */
	public function setQQAvatar(string $file) {
		$data = ['file'=>$file];
		$url = $this->api . '/set_qq_avatar';
		return $this->post($url, $data);
	}
}