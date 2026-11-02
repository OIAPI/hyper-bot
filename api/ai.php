<?php
namespace Api;

use Controller\Enum\GroupAiRecord;

/**
 * @desc AI 相关
 */

class ai extends app {
	/**
	 * @access public
	 * @desc 获取说话人列表
	 * @param int $type 类型，1 tts，2 song
	 * @return Object
	 */
	public function getAiCharacter(int $type = 1) : Object|null {
		$url = $this->api . '/get_ai_characters';
		$data = [
			'chat_type' => $type
		];
		return $this->post($url, $data);
	}
	/**
	 * @access public
	 * @desc 获取ai语音
	 * @param string $character 语音声音，可通过getAiCharacter获取
	 * @param string|float|int $group 群号
	 * @param string $content 音频内容
	 * @return Object
	 */
	public function getAiRecord(string $character = 'lucy-voice-laibixiaoxin', string|float|int $group = 0, string $content = '你好') : Object|null {
		$url = $this->api . '/get_ai_record';
		$data = [
			'character' => $character,
			'group_id' => $group,
			'text' => $content,
		];
		return $this->post($url, $data);
		// $res = $this->sendGroupAiRecord($character, $group ?: , )
	}
	/**
	 * @access public
	 * @desc 发送ai语音
	 * @param string $character 语音声音，可通过getAiCharacter获取
	 * @param string|float|int $group 群号
	 * @param string $content 音频内容
	 * @param Enum::GroupAiRecord<int> $chat_type 语音类型，1朗读，2说唱
	 * @return Object
	 */
	public function sendGroupAiRecord(string $character = 'lucy-voice-laibixiaoxin', string|float|int $group = 0, string $content = '你好', GroupAiRecord $chat_type = GroupAiRecord::READ) : Object|null {
		$url = $this->api . '/send_group_ai_record';
		$data = [
			'character' => $character,
			'group_id' => $group,
			'text' => $content,
			'chat_type' => $chat_type->value,
		];
		return $this->post($url, $data);
	}
	
}