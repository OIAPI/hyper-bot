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

	public function handles(string $action): bool {
		return true;
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		$mapped = $this->mapAction($action, $params);
		if ($mapped === null) {
			return $this->fail(-404, "Milky 不支持该操作：{$action}");
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
				$body = [
					'message_scene' => 'group',
					'peer_id'       => (int)($p['group_id'] ?? 0),
					'limit'         => (int)($p['count'] ?? 20),
				];
				if ((int)($p['message_id'] ?? 0) > 0) $body['start_message_seq'] = (int)$p['message_id'];
				return ['get_history_messages', $body, fn($d) => ['messages' => $d->messages ?? [], 'next_message_seq' => $d->next_message_seq ?? null]];

			case 'get_friend_msg_history':
				$body = [
					'message_scene' => 'friend',
					'peer_id'       => (int)($p['user_id'] ?? 0),
					'limit'         => (int)($p['count'] ?? 20),
				];
				if ((int)($p['message_id'] ?? 0) > 0) $body['start_message_seq'] = (int)$p['message_id'];
				return ['get_history_messages', $body, fn($d) => ['messages' => $d->messages ?? [], 'next_message_seq' => $d->next_message_seq ?? null]];

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
				return ['get_user_profile', ['user_id' => (int)($p['user_id'] ?? 0)], fn($d) => $d];
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

			// ================= 机器人 =================
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
