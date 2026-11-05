<?php
namespace Protocol\QqBot;

use Utils\Logger;
use Protocol\Core\Config;

/**
 * QQ 官方机器人（QQ Bot API v2）→ OneBot11 事件转换器
 *
 * 说明：
 *  - 官方使用 OpenID 标识用户/群，这些 ID 是字符串，本框架原样放入 user_id / group_id，
 *    既有插件若按数字处理需要留意（见 docs/README-protocol.md）。
 *  - 官方消息事件无 message_id 概念（id 字段为 ROBOT1.0_xxx 字符串），这里保留在 message_id 字段。
 *  - 被动回复所需的 msg_id + msg_seq 由 Adapter 负责维护。
 */
class Converter {
	/** 生成稳定的 int64 伪 ID（用于需要数字 ID 的场景，如权限表键名） */
	public static function hashId(string $openid): int {
		$hash = crc32($openid);
		return $hash >= 0 ? $hash : $hash + 4294967296;
	}

	/**
	 * 把 gateway / webhook payload（op=0 Dispatch）转换为 OneBot11 事件
	 * @param array $payload {"op":0,"s":1,"t":"...","d":{...},"id":"..."}
	 * @return array|null
	 */
	public static function toOneBot(array $payload, ?int $selfId = null): ?array {
		$t    = (string)($payload['t'] ?? '');
		$d    = (array)($payload['d'] ?? []);
		$time = time();
		$self = $selfId;
		if ($self === null || $self === 0) {
			[$hit, $accountSelf] = \Core\Bot\AccountContext::get('qqbot.self_id');
			$self = (int)(($hit && (string)$accountSelf !== '') ? $accountSelf : Config::string('qqbot.self_id', '0'));
			if (!$self) {
				[$hitApp, $accountApp] = \Core\Bot\AccountContext::get('qqbot.app_id');
				$appId = ($hitApp && (string)$accountApp !== '') ? (string)$accountApp : (string)Config::string('qqbot.app_id', '');
				$self = self::hashId($appId);
			}
		}

		return match ($t) {
			'GROUP_AT_MESSAGE_CREATE', 'GROUP_MESSAGE_CREATE' => self::groupMessage($d, $time, $self, $t),
			'C2C_MESSAGE_CREATE'                              => self::c2cMessage($d, $time, $self),
			'AT_MESSAGE_CREATE', 'MESSAGE_CREATE', 'DIRECT_MESSAGE_CREATE' => self::channelMessage($d, $time, $self, $t),
			'GROUP_ADD_ROBOT'                                 => self::botJoinGroup($d, $time, $self),
			'GROUP_DEL_ROBOT'                                 => self::botLeaveGroup($d, $time, $self),
			'GROUP_MEMBER_ADD'                                => self::groupIncrease($d, $time, $self),
			'GROUP_MEMBER_REMOVE'                             => self::groupDecrease($d, $time, $self),
			'C2C_MSG_RECEIVE'                                 => self::msgReceiveSwitch($d, $time, $self, 'private', true),
			'C2C_MSG_REJECT'                                  => self::msgReceiveSwitch($d, $time, $self, 'private', false),
			'GROUP_MSG_RECEIVE'                               => self::msgReceiveSwitch($d, $time, $self, 'group', true),
			'GROUP_MSG_REJECT'                                => self::msgReceiveSwitch($d, $time, $self, 'group', false),
			'SUBSCRIBE_MESSAGE_STATUS'                        => self::subscribeStatus($d, $time, $self),
			'GROUP_JOIN_REQUEST'                              => self::groupJoinRequest($d, $time, $self),
			'FRIEND_ADD'                                      => self::friendAdd($d, $time, $self),
			'FRIEND_DEL'                                      => self::friendDel($d, $time, $self),
			'INTERACTION_CREATE'                              => self::interaction($d, $time, $self),
			'MESSAGE_REACTION_ADD'                            => self::reaction($d, $time, $self, true),
			'MESSAGE_REACTION_REMOVE'                         => self::reaction($d, $time, $self, false),
			'MESSAGE_AUDIT_PASS'                              => self::audit($d, $time, $self, true),
			'MESSAGE_AUDIT_REJECT'                            => self::audit($d, $time, $self, false),
			'MESSAGE_DELETE', 'PUBLIC_MESSAGE_DELETE'          => self::channelMessageDelete($d, $time, $self),
			'AUDIO_START', 'AUDIO_FINISH'                     => self::channelAudio($d, $time, $self, $t),
			'GUILD_MEMBER_ADD'                                => self::guildMember($d, $time, $self, 'guild_member_add'),
			'GUILD_MEMBER_UPDATE'                             => self::guildMember($d, $time, $self, 'guild_member_update'),
			'GUILD_MEMBER_REMOVE'                             => self::guildMember($d, $time, $self, 'guild_member_remove'),
			'READY'                                           => self::ready($d, $time, $self),
			'RESUMED'                                         => self::resumed($d, $time, $self),
			default                                           => self::generic($t, $d, $time, $self),
		};
	}

	// ---------------- 消息 ----------------

	private static function groupMessage(array $d, int $time, int $self, string $eventName): array {
		$groupOpenid = (string)($d['group_openid'] ?? '');
		$author      = (array)($d['author'] ?? []);
		$userId      = (string)($author['member_openid'] ?? ($author['id'] ?? ''));
		$msgs        = self::buildMessage($d);

		return [
			'post_type'     => 'message',
			'message_type'  => 'group',
			'sub_type'      => ($eventName === 'GROUP_AT_MESSAGE_CREATE' ? 'normal' : 'normal'),
			'time'          => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'       => $self,
			'group_id'      => $groupOpenid,
			'group_openid'  => $groupOpenid,
			'user_id'       => $userId,
			'message_id'    => (string)($d['id'] ?? ''),
			'message'       => $msgs,
			'raw_message'   => self::segmentsToCQ($msgs),
			'font'          => 0,
			'protocol'      => 'qqbot',
			'sender'        => [
				'user_id' => $userId,
				'nickname'=> (string)($author['username'] ?? ''),
				'card'    => '',
				'role'    => self::mapRole((string)($author['member_role'] ?? 'member')),
			],
			'mention'       => array_map(
				fn($m) => (string)((array)$m)['member_openid'] ?? ((array)$m)['id'] ?? '',
				(array)($d['mentions'] ?? [])
			),
		];
	}

	private static function c2cMessage(array $d, int $time, int $self): array {
		$author = (array)($d['author'] ?? []);
		$userId = (string)($author['user_openid'] ?? ($author['id'] ?? ''));
		$msgs   = self::buildMessage($d);

		return [
			'post_type'    => 'message',
			'message_type' => 'private',
			'sub_type'     => 'friend',
			'time'         => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'      => $self,
			'user_id'      => $userId,
			'message_id'   => (string)($d['id'] ?? ''),
			'message'      => $msgs,
			'raw_message'  => self::segmentsToCQ($msgs),
			'font'         => 0,
			'protocol'     => 'qqbot',
			'sender'       => [
				'user_id' => $userId,
				'nickname'=> (string)($author['username'] ?? ''),
				'card'    => '',
			],
		];
	}

	private static function channelMessage(array $d, int $time, int $self, string $eventName): array {
		$author = (array)($d['author'] ?? []);
		$userId = (string)($author['id'] ?? ($author['member_openid'] ?? ''));
		$channelId = (string)($d['channel_id'] ?? '');
		$guildId   = (string)($d['guild_id'] ?? '');
		$content   = (string)($d['content'] ?? '');
		$segments  = $content === '' ? [] : [['type' => 'text', 'data' => ['text' => $content]]];

		return [
			'post_type'    => 'message',
			'message_type' => 'guild',
			'sub_type'     => 'channel',
			'time'         => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'      => $self,
			'guild_id'     => $guildId,
			'channel_id'   => $channelId,
			'group_id'     => $guildId,
			'user_id'      => $userId,
			'message_id'   => (string)($d['id'] ?? ''),
			'message'      => $segments,
			'raw_message'  => self::segmentsToCQ($segments),
			'protocol'     => 'qqbot',
			'event_name'   => $eventName,
			'sender'       => [
				'user_id' => $userId,
				'nickname'=> (string)($author['username'] ?? ''),
				'card'    => '',
			],
		];
	}

	/**
	 * 构建消息段
	 *  - message_type=0 纯文本
	 *  - message_type=3 卡片消息（ark_data）
	 *  - message_type=103 引用消息（msg_elements）
	 *  - attachments[] 追加为 image/record/video/file 段
	 */
	private static function buildMessage(array $d): array {
		$segments = [];
		$content = (string)($d['content'] ?? '');
		if ($content !== '') {
			$segments[] = ['type' => 'text', 'data' => ['text' => $content]];
		}

		foreach ((array)($d['attachments'] ?? []) as $att) {
			$att = (array)$att;
			$contentType = (string)($att['content_type'] ?? '');
			$url = (string)($att['url'] ?? '');
			$seg = match (true) {
				str_starts_with($contentType, 'image/') => ['type' => 'image', 'data' => ['file' => $url, 'url' => $url, 'width' => (int)($att['width'] ?? 0), 'height' => (int)($att['height'] ?? 0)]],
				$contentType === 'voice'                => ['type' => 'record', 'data' => ['file' => (string)($att['voice_wav_url'] ?? $url), 'url' => (string)($att['voice_wav_url'] ?? $url), 'asr' => (string)($att['asr_refer_text'] ?? '')]],
				$contentType === 'video/mp4'            => ['type' => 'video', 'data' => ['file' => $url, 'url' => $url]],
				default                                  => ['type' => 'file', 'data' => ['file' => $url, 'name' => (string)($att['filename'] ?? ''), 'size' => (int)($att['size'] ?? 0)]],
			};
			$segments[] = $seg;
		}

		$ark = (array)($d['ark_data'] ?? []);
		if ($ark !== []) {
			$segments[] = ['type' => 'json', 'data' => ['data' => json_encode($ark, JSON_UNESCAPED_UNICODE), 'ark_name' => (string)($ark['ark_name'] ?? '')]];
		}

		foreach ((array)($d['msg_elements'] ?? []) as $element) {
			$element = (array)$element;
			$text = (string)($element['content'] ?? '');
			if ($text !== '') {
				$segments[] = ['type' => 'text', 'data' => ['text' => $text]];
			}
		}

		if ($segments === []) {
			$segments[] = ['type' => 'text', 'data' => ['text' => '']];
		}
		return $segments;
	}

	/** 消息段 → CQ 码 */
	public static function segmentsToCQ(array $segments): string {
		$cq = '';
		foreach ($segments as $seg) {
			$type = $seg['type'] ?? 'text';
			$data = (array)($seg['data'] ?? []);
			if ($type === 'text') {
				$cq .= str_replace(['&', '[', ']', ','], ['&amp;', '&#91;', '&#93;', '&#44;'], (string)($data['text'] ?? ''));
				continue;
			}
			$pairs = [];
			foreach ($data as $k => $v) {
				if (is_array($v) || is_object($v)) continue;
				$pairs[] = $k . '=' . str_replace(['&', '[', ']', ','], ['&amp;', '&#91;', '&#93;', '&#44;'], (string)$v);
			}
			$cq .= '[CQ:' . $type . ($pairs ? ',' . implode(',', $pairs) : '') . ']';
		}
		return $cq;
	}

	// ---------------- 通知 / 请求 / 元事件 ----------------

	/** 机器人自身入群（GROUP_ADD_ROBOT）：user_id 是机器人自己，operator 是邀请人 */
	private static function botJoinGroup(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'notice',
			'notice_type'  => 'group_increase',
			'sub_type'     => 'invite',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (string)($d['group_openid'] ?? ''),
			'group_openid' => (string)($d['group_openid'] ?? ''),
			'user_id'      => (string)$self,
			'operator_id'  => (string)($d['op_member_openid'] ?? ''),
		];
	}

	/** 机器人自身被移出群（GROUP_DEL_ROBOT） */
	private static function botLeaveGroup(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'notice',
			'notice_type'  => 'group_decrease',
			'sub_type'     => 'kick_me',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (string)($d['group_openid'] ?? ''),
			'group_openid' => (string)($d['group_openid'] ?? ''),
			'user_id'      => (string)$self,
			'operator_id'  => (string)($d['op_member_openid'] ?? ''),
		];
	}

	/** 消息接收开关（C2C_MSG_RECEIVE / GROUP_MSG_RECEIVE 等） */
	private static function msgReceiveSwitch(array $d, int $time, int $self, string $scene, bool $enabled): array {
		$groupOpenid = (string)($d['group_openid'] ?? '');
		return [
			'post_type'    => 'notice',
			'notice_type'  => $scene === 'group' ? 'group_msg_receive' : 'private_msg_receive',
			'sub_type'     => $enabled ? 'enable' : 'disable',
			'time'         => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'      => $self,
			'group_id'     => $groupOpenid,
			'group_openid' => $groupOpenid,
			'user_id'      => (string)($d['openid'] ?? ($d['op_member_openid'] ?? '')),
			'operator_id'  => (string)($d['op_member_openid'] ?? ''),
		];
	}

	/** 订阅消息授权状态变更 */
	private static function subscribeStatus(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'notice',
			'notice_type'  => 'subscribe_message_status',
			'sub_type'     => 'update',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (string)($d['group_openid'] ?? ''),
			'group_openid' => (string)($d['group_openid'] ?? ''),
			'user_id'      => (string)($d['openid'] ?? ''),
			'result'       => $d['result'] ?? [],
		];
	}

	private static function groupIncrease(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_increase',
			'sub_type'    => 'invite',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (string)($d['group_openid'] ?? ''),
			'group_openid'=> (string)($d['group_openid'] ?? ''),
			'user_id'     => (string)($d['member_openid'] ?? ($d['op_member_openid'] ?? '')),
			'operator_id' => (string)($d['op_member_openid'] ?? ''),
		];
	}

	private static function groupDecrease(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'group_decrease',
			'sub_type'    => 'kick',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (string)($d['group_openid'] ?? ''),
			'group_openid'=> (string)($d['group_openid'] ?? ''),
			'user_id'     => (string)($d['member_openid'] ?? ($d['op_member_openid'] ?? '')),
			'operator_id' => (string)($d['op_member_openid'] ?? ''),
		];
	}

	private static function groupJoinRequest(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'request',
			'request_type' => 'group',
			'sub_type'     => 'add',
			'time'         => $time,
			'self_id'      => $self,
			'group_id'     => (string)($d['group_openid'] ?? ''),
			'group_openid' => (string)($d['group_openid'] ?? ''),
			'user_id'      => (string)($d['member_openid'] ?? ''),
			'comment'      => (string)($d['verify_info'] ?? ''),
			'flag'         => (string)($d['join_request_id'] ?? ''),
			'join_request_id' => (string)($d['join_request_id'] ?? ''),
		];
	}

	private static function friendAdd(array $d, int $time, int $self): array {
		return [
			'post_type'    => 'request',
			'request_type' => 'friend',
			'time'         => $time,
			'self_id'      => $self,
			'user_id'      => (string)($d['openid'] ?? ''),
			'comment'      => (string)($d['scene_param'] ?? ''),
			'flag'         => (string)($d['openid'] ?? ''),
		];
	}

	private static function friendDel(array $d, int $time, int $self): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => 'friend_del',
			'time'        => $time,
			'self_id'     => $self,
			'user_id'     => (string)($d['openid'] ?? ''),
		];
	}

	private static function interaction(array $d, int $time, int $self): array {
		return [
			'post_type'      => 'notice',
			'notice_type'    => 'interaction',
			'time'           => $time,
			'self_id'        => $self,
			'interaction_id' => (string)($d['id'] ?? ''),
			'interaction_type' => (int)($d['type'] ?? 0),
			'group_openid'   => (string)($d['group_openid'] ?? ''),
			'user_openid'    => (string)($d['user_openid'] ?? ''),
			'data'           => $d['data'] ?? new \stdClass(),
			'raw'            => $d,
		];
	}

	private static function ready(array $d, int $time, int $self): array {
		return [
			'post_type'       => 'meta_event',
			'meta_event_type' => 'lifecycle',
			'sub_type'        => 'enable',
			'time'            => $time,
			'self_id'         => (int)($d['user']['id'] ?? $self),
			'session_id'      => (string)($d['session_id'] ?? ''),
			'user'            => $d['user'] ?? new \stdClass(),
		];
	}

	private static function resumed(array $d, int $time, int $self): array {
		return [
			'post_type'       => 'meta_event',
			'meta_event_type' => 'lifecycle',
			'sub_type'        => 'resume',
			'time'            => $time,
			'self_id'         => $self,
			'data'            => $d,
		];
	}

	// ---------------- 工具 ----------------

	private static function parseTime(mixed $timestamp, int $fallback): int {
		if (is_string($timestamp) && $timestamp !== '') {
			$ts = strtotime($timestamp);
			if ($ts !== false) return $ts;
		}
		if (is_numeric($timestamp)) return (int)$timestamp;
		return $fallback;
	}

	private static function mapRole(string $role): string {
		return match (strtolower($role)) {
			'owner'   => 'owner',
			'admin'   => 'admin',
			'member'  => 'member',
			default   => 'member',
		};
	}

	/** 提取 message_scene.ext 中的 msg_idx（用于去重与引用） */
	public static function sceneExt(array $d, string $key): ?string {
		$ext = (array)($d['message_scene']['ext'] ?? []);
		foreach ($ext as $item) {
			if (str_starts_with((string)$item, $key . '=')) {
				return substr((string)$item, strlen($key) + 1);
			}
		}
		return null;
	}
	// ---------------- 频道附加事件 ----------------

	/** 频道表情表态（MESSAGE_REACTION_ADD / REMOVE） */
	private static function reaction(array $d, int $time, int $self, bool $added): array {
		$emoji = (array)($d['emoji'] ?? []);
		return [
			'post_type'   => 'notice',
			'notice_type' => $added ? 'message_reaction_add' : 'message_reaction_remove',
			'sub_type'    => (string)($emoji['type'] ?? ''),
			'time'        => $time,
			'self_id'     => $self,
			'user_id'     => (string)($d['user_id'] ?? ''),
			'group_id'    => (string)($d['guild_id'] ?? ''),
			'guild_id'    => (string)($d['guild_id'] ?? ''),
			'channel_id'  => (string)($d['channel_id'] ?? ''),
			'message_id'  => (string)($d['target']['id'] ?? ''),
			'target_type' => (string)($d['target']['type'] ?? ''),
			'emoji_id'    => (string)($emoji['id'] ?? ''),
			'emoji_type'  => (int)($emoji['type'] ?? 0),
		];
	}

	/** 主动消息审核结果（MESSAGE_AUDIT_PASS / REJECT） */
	private static function audit(array $d, int $time, int $self, bool $passed): array {
		return [
			'post_type'   => 'notice',
			'notice_type' => $passed ? 'message_audit_pass' : 'message_audit_reject',
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (string)($d['guild_id'] ?? ''),
			'guild_id'    => (string)($d['guild_id'] ?? ''),
			'channel_id'  => (string)($d['channel_id'] ?? ''),
			'message_id'  => (string)($d['message_id'] ?? ''),
			'audit_id'    => (string)($d['audit_id'] ?? ''),
			'audit_time'  => $d['audit_time'] ?? null,
		];
	}

	/** 频道成员变更（GUILD_MEMBER_ADD / UPDATE / REMOVE） */
	private static function guildMember(array $d, int $time, int $self, string $type): array {
		$user = (array)($d['user'] ?? []);
		return [
			'post_type'   => 'notice',
			'notice_type' => $type,
			'time'        => $time,
			'self_id'     => $self,
			'group_id'    => (string)($d['guild_id'] ?? ''),
			'guild_id'    => (string)($d['guild_id'] ?? ''),
			'user_id'     => (string)($user['id'] ?? ''),
			'nickname'    => (string)($user['username'] ?? ''),
			'joined_at'   => $d['joined_at'] ?? null,
		];
	}

	/**
	 * 兜底：协议新增了我们还没显式适配的事件。
	 *
	 * 不丢事件，而是压成有限几个 notice_type（forum_event / guild_event / unknown_event），
	 * 原始事件名放在 event_name 里。这样订阅页能给出开关，
	 * 插件也能先接住新事件，而不用改核心代码。
	 */
	/** 频道消息删除（MESSAGE_DELETE / PUBLIC_MESSAGE_DELETE） */
	private static function channelMessageDelete(array $d, int $time, int $self): array {
		$operator = (string)($d['op_user']['id'] ?? '');
		return [
			'post_type'   => 'notice',
			'notice_type' => 'channel_message_delete',
			'sub_type'    => 'delete',
			'time'        => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'     => $self,
			'guild_id'    => (string)($d['guild_id'] ?? ''),
			'channel_id'  => (string)($d['channel_id'] ?? ''),
			'group_id'    => (string)($d['guild_id'] ?? ''),
			'user_id'     => $operator,
			'operator_id' => $operator,
			'message_id'  => (string)($d['message_id'] ?? ($d['id'] ?? '')),
		];
	}

	/** 频道语音（AUDIO_START / AUDIO_FINISH） */
	private static function channelAudio(array $d, int $time, int $self, string $eventName): array {
		$audio = (array)($d['audio'] ?? []);
		return [
			'post_type'   => 'notice',
			'notice_type' => 'channel_audio',
			'sub_type'    => str_ends_with($eventName, 'START') ? 'start' : 'finish',
			'time'        => self::parseTime($d['timestamp'] ?? null, $time),
			'self_id'     => $self,
			'guild_id'    => (string)($d['guild_id'] ?? ''),
			'channel_id'  => (string)($d['channel_id'] ?? ($audio['channel_id'] ?? '')),
			'group_id'    => (string)($d['guild_id'] ?? ''),
			'user_id'     => (string)($d['author']['id'] ?? ''),
			'url'         => (string)($audio['url'] ?? ''),
			'text'        => (string)($audio['text'] ?? ''),
		];
	}

	private static function generic(string $t, array $d, int $time, int $self): ?array {
		if ($t === '') {
			return null;
		}
		$noticeType = str_starts_with($t, 'FORUM_')
			? 'forum_event'
			: ((str_starts_with($t, 'GUILD_') || str_starts_with($t, 'CHANNEL_')) ? 'guild_event' : 'unknown_event');
		return [
			'post_type'   => 'notice',
			'notice_type' => $noticeType,
			'time'        => $time,
			'self_id'     => $self,
			'event_name'  => $t,
			'group_id'    => (string)($d['guild_id'] ?? ($d['group_openid'] ?? '')),
			'user_id'     => (string)($d['user_id'] ?? ($d['openid'] ?? '')),
			'raw'         => $d,
		];
	}
}
