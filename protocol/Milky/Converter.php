<?php
namespace Protocol\Milky;

use Utils\Logger;

/**
 * Milky → OneBot11 事件转换器
 *
 * Milky 事件顶层只有 time / self_id / event_type / data 四个字段，
 * 与 OneBot11 的 post_type / message_type / notice_type ... 完全不同，转换后即可复用全部既有插件。
 */
class Converter {
	/**
	 * 把一个 Milky 事件转换为标准 OneBot11 事件
	 * @param array $event Milky 事件
	 * @return array|null 无法转换（如未知事件）时返回 null
	 */
	public static function toOneBot(array $event): ?array {
		$type = (string)($event['event_type'] ?? '');
		$time = (int)($event['time'] ?? time());
		$self = (int)($event['self_id'] ?? 0);
		$data = (array)($event['data'] ?? []);

		return match ($type) {
			'message_receive'          => self::message($data, $time, $self),
			'message_recall'           => self::recall($data, $time, $self),
			'group_member_increase'    => self::groupMemberIncrease($data, $time, $self),
			'group_member_decrease'    => self::groupMemberDecrease($data, $time, $self),
			'group_admin_change'       => self::groupAdmin($data, $time, $self),
			'group_mute'               => self::groupMute($data, $time, $self),
			'group_whole_mute'         => self::groupWholeMute($data, $time, $self),
			'friend_request'           => self::friendRequest($data, $time, $self),
			'group_join_request'       => self::groupJoinRequest($data, $time, $self, 'add'),
			'group_invited_join_request' => self::groupJoinRequest($data, $time, $self, 'invite'),
			'group_invitation'         => self::groupInvitation($data, $time, $self),
			'friend_nudge'             => self::friendNudge($data, $time, $self),
			'group_nudge'              => self::groupNudge($data, $time, $self),
			'group_message_reaction'   => self::groupReaction($data, $time, $self),
			'group_file_upload'        => self::groupFileUpload($data, $time, $self),
			'friend_file_upload'       => self::friendFileUpload($data, $time, $self),
			'group_name_change'        => self::groupNameChange($data, $time, $self),
			'group_essence_message_change' => self::essenceChange($data, $time, $self),
			'group_disband'            => self::groupDisband($data, $time, $self),
			'peer_pin_change'          => self::peerPinChange($data, $time, $self),
			'bot_offline'              => self::offline($data, $time, $self),
			default                    => null,
		};
	}

	// ---------- 消息 ----------

	private static function message(array $d, int $time, int $self): ?array {
		$scene  = (string)($d['message_scene'] ?? '');
		$peer   = (int)($d['peer_id'] ?? 0);
		$sender = (int)($d['sender_id'] ?? 0);
		$seq    = $d['message_seq'] ?? 0;
		$segments = self::segmentsToIncoming((array)($d['segments'] ?? []));
		$raw    = self::segmentsToCQ($segments);
		$msgTime = (int)($d['time'] ?? $time);

		$base = [
			'post_type'     => 'message',
			'time'          => $msgTime,
			'self_id'       => $self,
			'message_id'    => $seq,          // Milky 无 message_id，用 message_seq 顶替
			'message_seq'   => $seq,
			'user_id'       => $sender,
			'message'       => $segments,
			'raw_message'   => $raw,
			'font'          => 0,
			'post_format'   => 'array',
			'protocol'      => 'milky',
			'message_scene' => $scene,
			'peer_id'       => $peer,
		];

		if ($scene === 'group') {
			$group  = (array)($d['group'] ?? []);
			$member = (array)($d['group_member'] ?? []);
			return array_merge($base, [
				'message_type' => 'group',
				'group_id'     => $peer,
				'sub_type'     => 'normal',
				'sender'       => [
					'user_id'  => $sender,
					'nickname' => $member['nickname'] ?? ($member['card'] ?? ''),
					'card'     => $member['card'] ?? '',
					'role'     => $member['role'] ?? 'member',
					'title'    => $member['title'] ?? '',
					'level'    => $member['level'] ?? 0,
				],
				'group_info'   => [
					'group_id'   => $peer,
					'group_name' => $group['group_name'] ?? '',
					'member_count' => $group['member_count'] ?? 0,
				],
			]);
		}

		// friend / temp
		$friend = (array)($d['friend'] ?? []);
		$out = array_merge($base, [
			'message_type' => 'private',
			'sub_type'     => ($scene === 'temp' ? 'group' : 'friend'),
			'sender'       => [
				'user_id'  => $sender,
				'nickname' => $friend['nickname'] ?? ($friend['remark'] ?? ''),
				'card'     => '',
			],
		]);
		if ($scene === 'temp' && isset($d['group']['group_id'])) {
			$out['group_id'] = (int)$d['group']['group_id'];
		}
		return $out;
	}

	/** Milky IncomingSegment → OneBot11 消息段 */
	public static function segmentsToIncoming(array $segments): array {
		$result = [];
		foreach ($segments as $seg) {
			$converted = self::segmentToIncoming((array)$seg);
			if ($converted !== null) {
				$result[] = $converted;
			}
		}
		// OneBot 要求消息段数组非空，至少给一个空文本
		if ($result === []) {
			$result[] = ['type' => 'text', 'data' => ['text' => '']];
		}
		return $result;
	}

	public static function segmentToIncoming(array $seg): ?array {
		$type = (string)($seg['type'] ?? '');
		$data = (array)($seg['data'] ?? []);
		return match ($type) {
			'text'        => ['type' => 'text', 'data' => ['text' => (string)($data['text'] ?? '')]],
			'mention'     => ['type' => 'at', 'data' => ['qq' => (string)($data['user_id'] ?? 0), 'name' => (string)($data['name'] ?? '')]],
			'mention_all' => ['type' => 'at', 'data' => ['qq' => 'all', 'name' => '全体成员']],
			'face'        => ['type' => 'face', 'data' => ['id' => (string)($data['face_id'] ?? ''), 'is_large' => (bool)($data['is_large'] ?? false)]],
			'reply'       => ['type' => 'reply', 'data' => [
				'id'     => (string)($data['message_seq'] ?? ''),
				'qq'     => (string)($data['sender_id'] ?? ''),
				'text'   => self::segmentsToCQ(self::segmentsToIncoming((array)($data['segments'] ?? []))),
				'time'   => (int)($data['time'] ?? 0),
			]],
			'image'       => ['type' => 'image', 'data' => [
				'file'    => (string)($data['resource_id'] ?? ''),
				'url'     => (string)($data['temp_url'] ?? ''),
				'summary' => (string)($data['summary'] ?? ''),
				'sub_type'=> (int)($data['sub_type'] ?? 0),
				'width'   => (int)($data['width'] ?? 0),
				'height'  => (int)($data['height'] ?? 0),
			]],
			'record'      => ['type' => 'record', 'data' => ['file' => (string)($data['resource_id'] ?? ''), 'url' => (string)($data['temp_url'] ?? ''), 'duration' => (int)($data['duration'] ?? 0)]],
			'video'       => ['type' => 'video', 'data' => ['file' => (string)($data['resource_id'] ?? ''), 'url' => (string)($data['temp_url'] ?? ''), 'width' => (int)($data['width'] ?? 0), 'height' => (int)($data['height'] ?? 0), 'duration' => (int)($data['duration'] ?? 0)]],
			'file'        => ['type' => 'file', 'data' => ['file' => (string)($data['file_id'] ?? ''), 'file_id' => (string)($data['file_id'] ?? ''), 'name' => (string)($data['file_name'] ?? ''), 'size' => (int)($data['file_size'] ?? 0), 'hash' => (string)($data['file_hash'] ?? '')]],
			'forward'     => ['type' => 'forward', 'data' => ['id' => (string)($data['forward_id'] ?? ''), 'title' => (string)($data['title'] ?? ''), 'summary' => (string)($data['summary'] ?? '')]],
			'market_face' => ['type' => 'mface', 'data' => ['package_id' => (string)($data['emoji_package_id'] ?? ''), 'emoji_id' => (string)($data['emoji_id'] ?? ''), 'key' => (string)($data['key'] ?? ''), 'summary' => (string)($data['summary'] ?? ''), 'url' => (string)($data['url'] ?? '')]],
			'light_app'   => ['type' => 'json', 'data' => ['data' => (string)($data['json_payload'] ?? '')]],
			'xml'         => ['type' => 'xml', 'data' => ['data' => (string)($data['xml_payload'] ?? '')]],
			'markdown'    => ['type' => 'markdown', 'data' => ['content' => (string)($data['content'] ?? '')]],
			default       => ['type' => 'text', 'data' => ['text' => '[不支持的消息类型:' . $type . ']']], // 兼容：未知类型降级为文本
		};
	}

	/** OneBot11 消息段 → Milky OutgoingSegment（发送用） */
	public static function segmentToOutgoing(array $seg): ?array {
		$type = (string)($seg['type'] ?? '');
		$data = (array)($seg['data'] ?? []);
		$file = static fn(string $key) => (string)($data[$key] ?? ($data['file'] ?? ($data['url'] ?? '')));

		return match ($type) {
			'text'    => ['type' => 'text', 'data' => ['text' => (string)($data['text'] ?? '')]],
			'at'      => ((string)($data['qq'] ?? '') === 'all')
				? ['type' => 'mention_all', 'data' => new \stdClass()]
				: ['type' => 'mention', 'data' => ['user_id' => (int)($data['qq'] ?? 0)]],
			'face'    => ['type' => 'face', 'data' => ['face_id' => (string)($data['id'] ?? ''), 'is_large' => (bool)($data['is_large'] ?? false)]],
			'reply'   => ['type' => 'reply', 'data' => ['message_seq' => (int)($data['id'] ?? ($data['message_seq'] ?? 0))]],
			'image'   => ['type' => 'image', 'data' => ['uri' => $file('file'), 'sub_type' => (int)($data['sub_type'] ?? 0), 'summary' => (string)($data['summary'] ?? '')]],
			'record'  => ['type' => 'record', 'data' => ['uri' => $file('file')]],
			'video'   => ['type' => 'video', 'data' => ['uri' => $file('file'), 'thumb_uri' => (string)($data['thumb'] ?? '')]],
			'json'    => ['type' => 'light_app', 'data' => ['json_payload' => (string)($data['data'] ?? json_encode($data, JSON_UNESCAPED_UNICODE))]],
			'xml'     => ['type' => 'light_app', 'data' => ['json_payload' => (string)($data['data'] ?? '')]],
			'markdown'=> ['type' => 'markdown', 'data' => ['content' => (string)($data['content'] ?? '')]],
			'forward' => ['type' => 'forward', 'data' => ['messages' => [], 'title' => (string)($data['title'] ?? '聊天记录'), 'preview' => [], 'summary' => '', 'prompt' => '[聊天记录]']],
			default   => null,
		};
	}

	/** 消息段数组 → CQ 码字符串 */
	public static function segmentsToCQ(array $segments): string {
		$cq = '';
		foreach ($segments as $seg) {
			$type = $seg['type'] ?? 'text';
			$data = (array)($seg['data'] ?? []);
			if ($type === 'text') {
				$cq .= self::escape((string)($data['text'] ?? ''));
				continue;
			}
			$pairs = [];
			foreach ($data as $k => $v) {
				if (is_array($v) || is_object($v)) continue;
				$pairs[] = $k . '=' . self::escape((string)$v);
			}
			$cq .= '[CQ:' . $type . ($pairs ? ',' . implode(',', $pairs) : '') . ']';
		}
		return $cq;
	}

	public static function escape(string $text): string {
		return str_replace(['&', '[', ']', ','], ['&amp;', '&#91;', '&#93;', '&#44;'], $text);
	}

	// ---------- 通知 / 请求 / 元事件 ----------

	private static function recall(array $d, int $time, int $self): array {
		$scene = (string)($d['message_scene'] ?? '');
		$isGroup = $scene === 'group';
		return [
			'post_type'   => 'notice',
			'notice_type' => $isGroup ? 'group_recall' : 'friend_recall',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => $isGroup ? (int)($d['peer_id'] ?? 0) : null,
			'user_id'     => (int)($d['sender_id'] ?? 0),
			'operator_id' => (int)($d['operator_id'] ?? 0),
			'message_id'  => $d['message_seq'] ?? 0,
			'message_seq' => $d['message_seq'] ?? 0,
		];
	}

	private static function groupMemberIncrease(array $d, int $time, int $self): array {
		$operator = (int)($d['operator_id'] ?? 0);
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_increase',
			'sub_type'    => $operator > 0 ? 'approve' : 'invite',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['user_id'] ?? 0),
			'operator_id' => $operator,
			'invitor_id'  => (int)($d['invitor_id'] ?? 0),
		];
	}

	private static function groupMemberDecrease(array $d, int $time, int $self): array {
		$operator = (int)($d['operator_id'] ?? 0);
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_decrease',
			'sub_type'    => $operator > 0 ? 'kick' : 'leave',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['user_id'] ?? 0),
			'operator_id' => $operator,
		];
	}

	private static function groupAdmin(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_admin',
			'sub_type'    => !empty($d['is_set']) ? 'set' : 'unset',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['user_id'] ?? 0),
			'operator_id' => (int)($d['operator_id'] ?? 0),
		];
	}

	private static function groupMute(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_ban',
			'sub_type'    => (int)($d['duration'] ?? 0) > 0 ? 'ban' : 'lift_ban',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['user_id'] ?? 0),
			'operator_id' => (int)($d['operator_id'] ?? 0),
			'duration'    => (int)($d['duration'] ?? 0),
		];
	}

	private static function groupWholeMute(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_ban',
			'sub_type'    => !empty($d['is_mute']) ? 'ban' : 'lift_ban',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => 0,
			'operator_id' => (int)($d['operator_id'] ?? 0),
			'duration'    => 0,
		];
	}

	private static function friendRequest(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'request',
			'request_type' => 'friend',
			'time'         => $time,
			'self_id'      => $self,
			'user_id'      => (int)($d['initiator_id'] ?? 0),
			'comment'      => (string)($d['comment'] ?? ''),
			'flag'         => 'milky_friend_' . ($d['initiator_id'] ?? 0),
		];
	}

	private static function groupJoinRequest(array $d, int $time, int $self, string $subType): array {
		return [
			'post_type'    => 'request',
			'request_type' => 'group',
			'sub_type'     => $subType,
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (int)($d['group_id'] ?? 0),
			'user_id'      => (int)($d['initiator_id'] ?? ($d['target_user_id'] ?? 0)),
			'comment'      => (string)($d['comment'] ?? ''),
			'flag'         => 'milky_group_' . ($d['notification_seq'] ?? 0),
			'notification_seq' => $d['notification_seq'] ?? 0,
			'invitation_seq'   => $d['invitation_seq'] ?? 0,
			'source_group_id'  => (int)($d['source_group_id'] ?? 0),
		];
	}

	private static function groupInvitation(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'request',
			'request_type' => 'group',
			'sub_type'     => 'invite',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (int)($d['group_id'] ?? 0),
			'user_id'      => (int)($d['initiator_id'] ?? 0),
			'flag'         => 'milky_invitation_' . ($d['invitation_seq'] ?? 0),
			'notification_seq' => $d['invitation_seq'] ?? 0,
		];
	}

	private static function friendNudge(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'poke',
			'sub_type'    => 'poke',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => null,
			'user_id'     => (int)($d['user_id'] ?? 0),
			'target_id'   => (int)($d['user_id'] ?? 0),
			'is_self_send'    => (bool)($d['is_self_send'] ?? false),
			'is_self_receive' => (bool)($d['is_self_receive'] ?? false),
		];
	}

	private static function groupNudge(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'poke',
			'sub_type'    => 'poke',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['sender_id'] ?? 0),
			'target_id'   => (int)($d['receiver_id'] ?? 0),
		];
	}

	private static function groupReaction(array $d, int $time, int $self): array {
		return [
			'post_type'      => 'notice',
			'notice_type'    => 'group_msg_emoji_like',
			'time'           => $time,
			'self_id'        => $self,
			'group_id'       => (int)($d['group_id'] ?? 0),
			'user_id'        => (int)($d['user_id'] ?? 0),
			'message_id'     => $d['message_seq'] ?? 0,
			'emoji_id'       => (string)($d['face_id'] ?? ''),
			'is_add'         => (bool)($d['is_add'] ?? false),
			'reaction_type'  => $d['reaction_type'] ?? '',
		];
	}

	private static function groupFileUpload(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_upload',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'user_id'     => (int)($d['user_id'] ?? 0),
			'file'        => [
				'id'    => (string)($d['file_id'] ?? ''),
				'name'  => (string)($d['file_name'] ?? ''),
				'size'  => (int)($d['file_size'] ?? 0),
			],
		];
	}

	private static function friendFileUpload(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'offline_file',
			'time'        => $time,
			'self_id'     => $self,
			'user_id'     => (int)($d['user_id'] ?? 0),
			'file'        => [
				'id'   => (string)($d['file_id'] ?? ''),
				'name' => (string)($d['file_name'] ?? ''),
				'size' => (int)($d['file_size'] ?? 0),
			],
		];
	}

	private static function groupNameChange(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'notice',
			'notice_type'  => 'group_name_change',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (int)($d['group_id'] ?? 0),
			'operator_id'  => (int)($d['operator_id'] ?? 0),
			'new_group_name' => (string)($d['new_group_name'] ?? ''),
		];
	}

	private static function essenceChange(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'essence',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (int)($d['group_id'] ?? 0),
			'message_id'  => $d['message_seq'] ?? 0,
			'operator_id' => (int)($d['operator_id'] ?? 0),
			'is_set'      => (bool)($d['is_set'] ?? false),
		];
	}

	/** 群解散（Milky 独有，OneBot11 无对应类型，用 notice/group_disband 承载） */
	private static function groupDisband(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'notice',
			'notice_type'  => 'group_disband',
			'sub_type'     => 'disband',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (int)($d['group_id'] ?? 0),
			'operator_id'  => (int)($d['operator_id'] ?? 0),
		];
	}

	/** 会话置顶变更（Milky 独有） */
	private static function peerPinChange(array $d, int $time, int $self): array {
		return [
			'post_type'     => 'notice',
			'notice_type'   => 'peer_pin_change',
			'sub_type'      => 'update',
			'time'          => $time,
			'self_id'       => $self,
			'message_scene' => (string)($d['message_scene'] ?? ''),
			'peer_id'       => (int)($d['peer_id'] ?? 0),
			'is_pinned'     => (bool)($d['is_pinned'] ?? false),
		];
	}

	private static function offline(array $d, int $time, int $self): array {
		return [
			'post_type'      => 'meta_event',
			'meta_event_type'=> 'lifecycle',
			'sub_type'       => 'disable',
			'time'           => $time,
			'self_id'        => $self,
			'reason'         => (string)($d['reason'] ?? ''),
		];
	}
}
