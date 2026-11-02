<?php
namespace Protocol\Milky;

use Protocol\Core\Adapter as BaseAdapter;
use Utils\Logger;

/**
 * Milky 协议适配器：把 OneBot11 风格的 action 翻译成 Milky HTTP API
 *
 * 设计要点：
 *  - Milky 没有统一 send_msg，私聊/群聊是两个端点；
 *  - Milky 没有 message_id，只有 message_seq（会话内唯一），撤回/取消息需带场景+peer；
 *  - 未知 action 直接返回 failed，避免误调用。
 */
class Adapter extends BaseAdapter {
	protected string $name = 'milky';
	protected string $title = 'Milky';
	private Api $api;

	public function __construct(array $config = []) {
		parent::__construct($config);
		$this->api = new Api($config);
	}

	/**
	 * Milky 协议中确实不存在对应能力的 OneBot 操作
	 *
	 * 这里列出来是为了给出可读的错误原因，而不是一律 "not found"。
	 */
	private const UNSUPPORTED = [
		'_get_model_show'           => '无资料卡展示接口',
		'_set_model_show'           => '无资料卡展示接口',
		'delete_unidirectional_friend' => '无单向好友接口',
		'get_unidirectional_friend_list' => '无单向好友接口',
		'download_file'             => '无通用下载接口，请改用 get_resource_temp_url 取链接',
		'fetch_mface_key'           => '无表情包密钥接口',
		'get_ai_characters'         => '无 AI 角色接口',
		'get_ai_record'             => '无 AI 语音接口',
		'send_group_ai_record'      => '无 AI 语音接口',
		'get_clientkey'             => '无 clientkey 接口',
		'get_credentials'           => '无凭证接口',
		'get_rkey'                  => '无 rkey 接口',
		'get_skey'                  => '无 skey 接口',
		'get_group_at_all_remain'   => '无 @全体成员额度接口',
		'get_group_file_system_info'=> '无群文件系统信息接口',
		'get_group_honor_info'      => '无群荣誉接口',
		'ocr_image'                 => '无 OCR 接口',
		'send_group_bot_callback'   => '无机器人回调接口',
		'send_group_sign'           => '无群签到接口',
		'send_pb'                   => '无 protobuf 直发接口',
		'set_group_anonymous'       => '无匿名聊天接口',
		'set_group_anonymous_ban'   => '无匿名禁言接口',
		'set_group_bot_status'      => '无机器人状态管理接口',
		'set_restart'               => '不允许应用侧重启协议端',
	];

	public function handles(string $action): bool {
		// Milky 是当前事件来源，所有动作都由这里应答：
		// 已映射的转成 Milky API，未映射的给出明确原因，
		// 避免 Bridge 悄悄回落到 HTTP 协议端（那可能是另一个账号）。
		return true;
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		$mapped = $this->mapAction($action, $params);
		if ($mapped === null) {
			// 这些是协议差异导致「Milky 侧无对应接口」的操作，
			// 明确提示而不是笼统报错，方便业务侧判断是否需要降级逻辑。
			$reason = self::UNSUPPORTED[$action] ?? 'Milky 协议无对应接口';
			$this->logWarning("[Milky] 不支持的操作 {$action}：{$reason}");
			return $this->fail(-404, "Milky 不支持该操作：{$action}（{$reason}）");
		}
		[$api, $payload, $transformer] = $mapped;

		$result = $this->api->call($api, $payload);
		if (($result->retcode ?? -1) !== 0) {
			return (object)[
				'status'  => 'failed',
				'retcode' => $result->retcode ?? -1,
				'data'    => null,
				'message' => $result->message ?? '',
			];
		}
		$data = $transformer ? $transformer($result->data) : $result->data;

		return (object)[
			'status'  => 'ok',
			'retcode' => 0,
			'data'    => $data ?? new \stdClass(),
			'message' => $result->message ?? '',
		];
	}

	/**
	 * 动作映射
	 * @return array{0:string,1:array,2:?callable}|null [milky_api, params, 响应转换器]
	 */
	private function mapAction(string $action, array $p): ?array {
		$sendResult = fn($d) => [
			'message_id'   => $d->message_seq ?? 0,
			'message_seq'  => $d->message_seq ?? 0,
			'time'         => $d->time ?? 0,
		];
		$empty      = fn($d) => new \stdClass();
		$segments   = fn(): array => self::buildSegments($p);

		switch ($action) {
			// ================= 消息 =================
			case 'send_msg':
			case 'send_private_msg':
			case 'send_group_msg':
				$type = $this->detectMessageType($action, $p);
				if ($type === 'group') {
					return ['send_group_message', ['group_id' => (int)($p['group_id'] ?? 0), 'message' => $segments()], $sendResult];
				}
				return ['send_private_message', ['user_id' => (int)($p['user_id'] ?? 0), 'message' => $segments()], $sendResult];

			case 'delete_msg':
			case 'recall_msg':
				$scene = $this->detectScene($p);
				$api   = $scene === 'group' ? 'recall_group_message' : 'recall_private_message';
				$body  = [
					'message_scene' => $scene,
					'peer_id'       => (int)($p['peer_id'] ?? ($scene === 'group' ? ($p['group_id'] ?? 0) : ($p['user_id'] ?? 0))),
					'message_seq'   => (int)($p['message_id'] ?? ($p['message_seq'] ?? 0)),
				];
				return [$api, $body, $empty];

			case 'get_msg':
				return ['get_message', [
					'message_scene' => $this->detectScene($p),
					'peer_id'       => (int)($p['peer_id'] ?? ($p['group_id'] ?? ($p['user_id'] ?? 0))),
					'message_seq'   => (int)($p['message_id'] ?? ($p['message_seq'] ?? 0)),
				], fn($d) => $d];

			case 'get_group_msg_history':
			case 'get_friend_msg_history':
				$isGroup = $action === 'get_group_msg_history';
				$body = [
					'message_scene' => $isGroup ? 'group' : 'friend',
					'peer_id'       => (int)($isGroup ? ($p['group_id'] ?? 0) : ($p['user_id'] ?? 0)),
					'limit'         => (int)($p['count'] ?? 20),
				];
				$startSeq = $p['message_seq'] ?? ($p['message_id'] ?? 0);
				if ((int)$startSeq > 0) $body['start_message_seq'] = (int)$startSeq;
				return ['get_history_messages', $body, fn($d) => ['messages' => $d->messages ?? [], 'next_message_seq' => $d->next_message_seq ?? null]];

			case 'get_custom_face_url_list':
				return ['get_custom_face_url_list', [], fn($d) => $d];

			case 'send_forward_msg':
			case 'send_group_forward_msg':
			case 'send_private_forward_msg':
				$forward = [[
					'type' => 'forward',
					'data' => [
						'messages' => self::forwardNodes((array)($p['messages'] ?? [])),
						'title'    => (string)($p['title'] ?? '聊天记录'),
						'preview'  => [],
						'summary'  => (string)($p['summary'] ?? ''),
						'prompt'   => '[聊天记录]',
					],
				]];
				$scene = $this->detectScene($p);
				if ($scene === 'group') {
					return ['send_group_message', ['group_id' => (int)($p['group_id'] ?? 0), 'message' => $forward], $sendResult];
				}
				return ['send_private_message', ['user_id' => (int)($p['user_id'] ?? 0), 'message' => $forward], $sendResult];

			case 'send_like':
				return ['send_profile_like', ['user_id' => (int)($p['user_id'] ?? 0), 'count' => (int)($p['times'] ?? 1)], $empty];

			case 'mark_msg_as_read':
				return ['mark_message_as_read', [
					'message_scene' => $this->detectScene($p),
					'peer_id'       => (int)($p['peer_id'] ?? ($p['group_id'] ?? ($p['user_id'] ?? 0))),
					'message_seq'   => (int)($p['message_id'] ?? 0),
				], $empty];

			case 'get_forward_msg':
				return ['get_forwarded_messages', ['forward_id' => (string)($p['id'] ?? ($p['message_id'] ?? ''))], fn($d) => $d->messages ?? []];

			// ================= 好友 =================
			case 'get_friend_list':
				return ['get_friend_list', ['no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->friends ?? []];
			case 'get_stranger_info':
				return ['get_user_profile', ['user_id' => (int)($p['user_id'] ?? 0), 'no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d];
			case 'get_friend_info':
				return ['get_friend_info', ['user_id' => (int)($p['user_id'] ?? 0), 'no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->friend ?? $d];
			case 'delete_friend':
				return ['delete_friend', ['user_id' => (int)($p['user_id'] ?? 0)], $empty];
			case 'set_friend_add_request':
				$approve = (bool)($p['approve'] ?? true);
				$initiator = (int)preg_replace('/[^0-9]/', '', (string)($p['flag'] ?? ''));
				return $approve
					? ['accept_friend_request', ['initiator_id' => $initiator, 'is_filtered' => false], $empty]
					: ['reject_friend_request', ['initiator_id' => $initiator, 'is_filtered' => false, 'reason' => (string)($p['reason'] ?? '')], $empty];

			// ================= 群 =================
			case 'get_group_list':
				return ['get_group_list', ['no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->groups ?? []];
			case 'get_group_info':
				return ['get_group_info', ['group_id' => (int)($p['group_id'] ?? 0), 'no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->group ?? $d];
			case 'get_group_member_list':
				return ['get_group_member_list', ['group_id' => (int)($p['group_id'] ?? 0), 'no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->members ?? []];
			case 'get_group_member_info':
				return ['get_group_member_info', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'no_cache' => (bool)($p['no_cache'] ?? false)], fn($d) => $d->member ?? $d];
			case 'set_group_add_request':
				return $this->mapGroupRequest($p, $empty);
			case 'set_group_kick':
				return ['kick_group_member', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'reject_add_request' => (bool)($p['reject_add_request'] ?? false)], $empty];
			case 'set_group_ban':
				return ['set_group_member_mute', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'duration' => (int)($p['duration'] ?? 0)], $empty];
			case 'set_group_whole_ban':
				return ['set_group_whole_mute', ['group_id' => (int)($p['group_id'] ?? 0), 'is_mute' => (bool)($p['enable'] ?? true)], $empty];
			case 'set_group_card':
				return ['set_group_member_card', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'card' => (string)($p['card'] ?? '')], $empty];
			case 'set_group_name':
				return ['set_group_name', ['group_id' => (int)($p['group_id'] ?? 0), 'group_name' => (string)($p['group_name'] ?? '')], $empty];
			case 'set_group_admin':
				return ['set_group_member_admin', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'is_set' => (bool)($p['enable'] ?? true)], $empty];
			case 'set_group_special_title':
				return ['set_group_member_special_title', ['group_id' => (int)($p['group_id'] ?? 0), 'user_id' => (int)($p['user_id'] ?? 0), 'special_title' => (string)($p['special_title'] ?? '')], $empty];
			case 'set_group_reaction':
			case 'set_msg_emoji_like':
				return ['send_group_message_reaction', [
					'group_id'    => (int)($p['group_id'] ?? 0),
					'message_seq' => (int)($p['message_id'] ?? 0),
					'face_id'     => (string)($p['emoji_id'] ?? ($p['code'] ?? '')),
					'is_add'      => (bool)($p['is_add'] ?? true),
				], $empty];
			case 'group_poke':
			case 'friend_poke':
				return !empty($p['group_id'])
					? ['send_group_nudge', ['group_id' => (int)$p['group_id'], 'user_id' => (int)($p['user_id'] ?? 0)], $empty]
					: ['send_friend_nudge', ['user_id' => (int)($p['user_id'] ?? 0)], $empty];

			// ================= 文件 =================
			case 'upload_group_file':
				$body = [
					'group_id'         => (int)($p['group_id'] ?? 0),
					'parent_folder_id' => (string)($p['folder'] ?? '/'),
					'file_uri'         => (string)($p['file'] ?? ''),
					'file_name'        => (string)($p['name'] ?? ''),
				];
				return ['upload_group_file', $body, fn($d) => ['file_id' => $d->file_id ?? '']];
			case 'upload_private_file':
				return ['upload_private_file', [
					'user_id'   => (int)($p['user_id'] ?? 0),
					'file_uri'  => (string)($p['file'] ?? ''),
					'file_name' => (string)($p['name'] ?? ''),
				], fn($d) => ['file_id' => $d->file_id ?? '']];
			case 'get_group_file_url':
				return ['get_group_file_download_url', ['group_id' => (int)($p['group_id'] ?? 0), 'file_id' => (string)($p['file_id'] ?? '')], fn($d) => $d];
			case 'delete_group_file':
				return ['delete_group_file', ['group_id' => (int)($p['group_id'] ?? 0), 'file_id' => (string)($p['file_id'] ?? '')], $empty];
			case 'get_private_file_url':
				return ['get_private_file_download_url', [
					'user_id'        => (int)($p['user_id'] ?? 0),
					'file_id'        => (string)($p['file_id'] ?? ''),
					'file_hash'      => (string)($p['file_hash'] ?? ''),
					'is_self_send'   => (bool)($p['is_self_send'] ?? false),
				], fn($d) => $d];
			case 'rename_group_file_folder':
				return ['rename_group_folder', [
					'group_id'       => (int)($p['group_id'] ?? 0),
					'folder_id'      => (string)($p['folder_id'] ?? '/'),
					'new_folder_name' => (string)($p['name'] ?? ''),
				], $empty];
			case 'delete_group_file_folder':
			case 'delete_group_folder':
				return ['delete_group_folder', [
					'group_id'  => (int)($p['group_id'] ?? 0),
					'folder_id' => (string)($p['folder_id'] ?? '/'),
				], $empty];
			case 'get_group_root_files':
			case 'get_group_files_by_folder':
				return ['get_group_files', [
					'group_id'         => (int)($p['group_id'] ?? 0),
					'parent_folder_id' => (string)($p['folder_id'] ?? '/'),
					'no_cache'         => false,
				], fn($d) => $d];
			case 'create_group_file_folder':
				return ['create_group_folder', ['group_id' => (int)($p['group_id'] ?? 0), 'folder_name' => (string)($p['name'] ?? '')], fn($d) => $d];
			case 'move_group_file':
				return ['move_group_file', [
					'group_id'         => (int)($p['group_id'] ?? 0),
					'file_id'          => (string)($p['file_id'] ?? ''),
					'parent_folder_id' => (string)($p['parent_directory'] ?? '/'),
					'target_folder_id' => (string)($p['target_directory'] ?? '/'),
				], $empty];

			// ================= 群管理扩展 =================
			case 'set_group_leave':
			case 'quit_group':
				return ['quit_group', ['group_id' => (int)($p['group_id'] ?? 0)], $empty];
			case 'set_essence_msg':
			case 'set_group_essence_message':
				return ['set_group_essence_message', [
					'group_id'    => (int)($p['group_id'] ?? 0),
					'message_seq' => (int)($p['message_id'] ?? 0),
				], $empty];
			case 'delete_essence_msg':
			case 'delete_group_essence_message':
				return ['set_group_essence_message', [
					'group_id'    => (int)($p['group_id'] ?? 0),
					'message_seq' => (int)($p['message_id'] ?? 0),
				], $empty];
			case 'get_group_essence_msg_list':
			case 'get_essence_msg_list':
				// 规范字段是 page_index / page_size，不是 limit
				return ['get_group_essence_messages', [
					'group_id'   => (int)($p['group_id'] ?? 0),
					'page_index' => (int)($p['page'] ?? 0),
					'page_size'  => (int)($p['count'] ?? 20),
				], fn($d) => $d->messages ?? []];
			case '_get_group_notice':
			case 'get_group_notice':
			case 'get_group_announcements':
			case 'get_group_announcement_list':
				return ['get_group_announcements', ['group_id' => (int)($p['group_id'] ?? 0)], fn($d) => $d->announcements ?? []];
			case '_send_group_notice':
			case 'send_group_notice':
			case 'send_group_announcement':
				return ['send_group_announcement', [
					'group_id'  => (int)($p['group_id'] ?? 0),
					'content'   => (string)($p['content'] ?? ''),
					'image_uri' => (string)($p['image'] ?? ''),
				], fn($d) => $d];
			case '_del_group_notice':
			case 'delete_group_notice':
			case 'delete_group_announcement':
				return ['delete_group_announcement', [
					'group_id'       => (int)($p['group_id'] ?? 0),
					'announcement_id' => (string)($p['notice_id'] ?? ($p['announcement_id'] ?? '')),
				], $empty];
			case 'set_group_invitation':
			case 'accept_group_invitation':
				return ['accept_group_invitation', [
					'group_id'   => (int)($p['group_id'] ?? 0),
					'invitation_id' => (string)($p['flag'] ?? ($p['invitation_id'] ?? '')),
				], $empty];

			// ================= 资源 =================
			case 'get_resource_temp_url':
			case 'get_image':
			case 'get_record':
				return ['get_resource_temp_url', ['resource_id' => (string)($p['file'] ?? ($p['resource_id'] ?? ''))], fn($d) => ['url' => (string)($d->url ?? '')]];
			case 'get_cookies':
				return ['get_cookies', ['domain' => (string)($p['domain'] ?? '')], fn($d) => $d];
			case 'get_csrf_token':
				return ['get_csrf_token', [], fn($d) => $d];

			// ================= 机器人 =================
			case 'set_qq_profile':
			case 'set_self_nickname':
				return ['set_nickname', ['nickname' => (string)($p['nickname'] ?? '')], $empty];
			case 'set_qq_avatar':
			case 'set_avatar':
				return ['set_avatar', ['uri' => (string)($p['file'] ?? ($p['uri'] ?? ''))], $empty];
			case 'set_group_portrait':
			case 'set_group_avatar':
				return ['set_group_avatar', [
					'group_id'  => (int)($p['group_id'] ?? 0),
					'image_uri' => (string)($p['file'] ?? ($p['image_uri'] ?? '')),
				], $empty];
			case 'fetch_custom_face':
				return ['get_custom_face_url_list', [], fn($d) => $d->urls ?? []];
			case 'get_group_system_msg':
				return ['get_group_notifications', [
					'is_filtered' => false,
					'limit'       => (int)($p['count'] ?? 20),
				], fn($d) => $d];
			case 'can_send_image':
			case 'can_send_record':
				// Milky 协议端没有「能力探测」接口，按协议能力恒为 true
				return ['get_impl_info', [], fn($d) => ['yes' => true]];
			case 'check_url_safely':
				// 无对应接口，按安全放行（具体风险由业务侧自行判断）
				return ['get_impl_info', [], fn($d) => ['level' => 1]];
			case 'clean_cache':
			case 'reload_event_filter':
				// 无对应接口，视为本地 no-op（返回成功避免业务侧报错）
				return ['get_impl_info', [], fn($d) => new \stdClass()];
			case 'get_online_clients':
				return ['get_impl_info', [], fn($d) => []];
			case 'set_bio':
				return ['set_bio', ['bio' => (string)($p['bio'] ?? '')], $empty];
			case 'get_login_info':
				return ['get_login_info', [], fn($d) => ['user_id' => (int)($d->user_id ?? ($d->uin ?? 0)), 'nickname' => (string)($d->nickname ?? '')]];
			case 'get_version_info':
				return ['get_impl_info', [], fn($d) => $d];
			case 'get_status':
				return ['get_login_info', [], fn($d) => $d];

			default:
				return null;
		}
	}

	/** 判断消息场景：group / friend / temp */
	private function detectScene(array $p): string {
		if (isset($p['message_scene']) && $p['message_scene'] !== '') {
			return (string)$p['message_scene'];
		}
		if (!empty($p['group_id'])) return 'group';
		if (!empty($p['user_id'])) return 'friend';
		return 'group';
	}

	private function detectMessageType(string $action, array $p): string {
		if ($action === 'send_group_msg') return 'group';
		if ($action === 'send_private_msg') return 'private';
		if (isset($p['message_type']) && $p['message_type'] !== '') {
			return strtolower((string)$p['message_type']);
		}
		return !empty($p['group_id']) ? 'group' : 'private';
	}

	/** 入群/邀请请求 */
	private function mapGroupRequest(array $p, callable $empty): ?array {
		$approve = (bool)($p['approve'] ?? true);
		$seq = (int)preg_replace('/[^0-9]/', '', (string)($p['flag'] ?? ''));
		$group = (int)($p['group_id'] ?? 0);
		if ($group === 0) {
			$group = (int)preg_replace('/[^0-9]/', '', (string)($p['flag'] ?? ''));
		}
		$sub = strtolower((string)($p['sub_type'] ?? 'add'));
		if ($sub === 'invite') {
			return $approve
				? ['accept_group_invitation', ['group_id' => $group, 'invitation_seq' => $seq], $empty]
				: ['reject_group_invitation', ['group_id' => $group, 'invitation_seq' => $seq, 'reason' => (string)($p['reason'] ?? '')], $empty];
		}
		return $approve
			? ['accept_group_request', ['group_id' => $group, 'notification_seq' => $seq, 'is_filtered' => false], $empty]
			: ['reject_group_request', ['group_id' => $group, 'notification_seq' => $seq, 'is_filtered' => false, 'reason' => (string)($p['reason'] ?? '')], $empty];
	}

	// ---------------- 消息段转换 ----------------

	/** 把 OneBot11 message（数组或 CQ 码字符串）转为 Milky OutgoingSegment[] */
	public static function buildSegments(array $p): array {
		$message = $p['message'] ?? [];
		if (is_string($message)) {
			return self::cqToSegments($message);
		}
		if (is_object($message)) {
			$message = (array)$message;
		}
		$out = [];
		foreach ((array)$message as $seg) {
			$converted = Converter::segmentToOutgoing((array)$seg);
			if ($converted !== null) $out[] = $converted;
		}
		return $out;
	}

	/** CQ 码字符串 → Milky OutgoingSegment[]（简易解析） */
	public static function cqToSegments(string $message): array {
		if ($message === '') return [];
		$pattern = '/\\[CQ:(.+?)\\]/s';
		$parts = preg_split($pattern, $message, -1, PREG_SPLIT_DELIM_CAPTURE);
		$segments = [];
		foreach ((array)$parts as $index => $part) {
			if ($index % 2 === 1) {
				$items = explode(',', $part);
				$type = array_shift($items);
				$data = [];
				foreach ($items as $item) {
					$kv = explode('=', $item, 2);
					if (count($kv) === 2) {
						$data[$kv[0]] = str_replace(['&#91;', '&#93;', '&#44;', '&amp;'], ['[', ']', ',', '&'], $kv[1]);
					}
				}
				$converted = Converter::segmentToOutgoing(['type' => $type, 'data' => $data]);
				if ($converted !== null) $segments[] = $converted;
			} elseif (trim((string)$part) !== '') {
				$segments[] = ['type' => 'text', 'data' => ['text' => str_replace(['&#91;', '&#93;', '&#44;', '&amp;'], ['[', ']', ',', '&'], (string)$part)]];
			}
		}
		return $segments;
	}

	/** OneBot11 node 数组 → Milky OutgoingForwardedMessage[] */
	public static function forwardNodes(array $messages): array {
		$nodes = [];
		foreach ($messages as $node) {
			$node = (array)$node;
			if (($node['type'] ?? '') === 'node') {
				$data = (array)($node['data'] ?? []);
				if (isset($data['content'])) {
					$content = $data['content'];
					$segments = is_string($content)
						? self::cqToSegments($content)
						: array_values(array_filter(array_map(
							fn($s) => Converter::segmentToOutgoing((array)$s),
							(array)$content
						)));
				} else {
					$segments = [];
				}
				$nodes[] = [
					'user_id'     => (int)($data['uin'] ?? ($data['user_id'] ?? 0)),
					'sender_name' => (string)($data['name'] ?? ($data['nickname'] ?? '')),
					'segments'    => $segments,
				];
			} else {
				$converted = Converter::segmentToOutgoing($node);
				if ($converted !== null) {
					$nodes[] = ['user_id' => 0, 'sender_name' => '', 'segments' => [$converted]];
				}
			}
		}
		return $nodes;
	}

	public function meta(): array {
		$meta = parent::meta();
		$meta['api'] = $this->api->baseUrl();
		return $meta;
	}
}
