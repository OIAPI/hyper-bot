<?php
namespace Protocol\QqBot;

use Protocol\Core\Adapter as BaseAdapter;
use Utils\CoroutineCurl;
use Utils\Logger;

/**
 * QQ 官方机器人协议适配器
 *
 * OneBot11 action → 官方 OpenAPI：
 *  - send_msg / send_group_msg / send_private_msg → POST /v2/groups/{group_openid}/messages 或 /v2/users/{user_openid}/messages
 *  - delete_msg → DELETE /v2/groups|users/{id}/messages/{message_id}
 *  - get_group_member_list → GET /v2/groups/{group_openid}/members
 *  - interaction 回调 → PUT /interactions/{interaction_id}
 *
 * 被动回复：自动从 EventClient 的上下文取 msg_id/msg_seq（相同的 msg_id+msg_seq 重复发送会失败）。
 */
class Adapter extends BaseAdapter {
	protected string $name = 'qqbot';
	protected string $title = 'QQ 官方机器人 (QQ Bot API v2)';

	/** 官方确实未开放能力（WebUI 能力矩阵用） */
	public const UNSUPPORTED = [
		'get_group_list'         => '官方未提供群列表接口',
		'get_friend_list'        => '官方未提供好友列表接口',
		'get_friend_msg_history' => '官方未提供历史消息接口',
		'get_group_msg_history'  => '官方未提供历史消息接口',
		'set_group_whole_ban'    => '官方不支持全员禁言',
	];

	public static function unsupportedList(): array {
		$out = [];
		foreach (self::UNSUPPORTED as $action => $reason) {
			$out[] = ['action' => $action, 'reason' => $reason];
		}
		return $out;
	}

	public function handles(string $action): bool {
		return true;
	}

	public function call(string $action, array $params = [], string $method = 'POST'): object {
		try {
			return match ($action) {
				'send_msg', 'send_group_msg', 'send_private_msg' => $this->sendMessage($action, $params),
				'delete_msg', 'recall_msg'          => $this->recall($params),
				'get_login_info'                    => $this->getLoginInfo(),
				'interaction_reply'                 => $this->interactionReply($params),

				// 群信息与成员
				'get_group_info'                    => $this->getGroupInfo($params),
				'get_group_member_list'             => $this->getGroupMembers($params),
				'get_group_member_info'             => $this->getGroupMemberInfo($params),
				'set_group_kick'                    => $this->removeMembers($params),
				'get_group_bot_state'               => $this->requestOk('GET', '/v2/groups/' . urlencode($this->groupOpenid($params)) . '/bot_state'),
				'get_group_restrict_chat_setting'   => $this->requestOk('GET', '/v2/groups/' . urlencode($this->groupOpenid($params)) . '/restrict_chat_setting'),
				'set_group_ban'                     => $this->muteMember($params),

				// 入群申请
				'get_group_system_msg'              => $this->joinRequestList($params),
				'set_group_add_request'             => $this->approveJoinRequest($params),

				// 黑名单
				'get_group_member_blacklist'        => $this->requestOk('GET', '/v2/groups/' . urlencode($this->groupOpenid($params)) . '/member_blacklist', [
					'cursor' => (string)($params['cursor'] ?? ''),
					'limit'  => (int)($params['limit'] ?? 20),
				]),
				'set_group_member_blacklist'        => $this->blacklistOp($params),

				// 菜单 / 面板 / 分享链接
				'get_menu'                          => $this->requestOk('GET', '/v2/menu'),
				'set_menu'                          => $this->requestOk('PUT', '/v2/menu', ['menu' => $params['menu'] ?? new \stdClass()]),
				'get_panels'                        => $this->requestOk('GET', '/v2/panels'),
				'create_panel'                      => $this->requestOk('POST', '/v2/panels', $params),
				'generate_url_link'                 => $this->requestOk('POST', '/v2/generate_url_link', ['callback_data' => (string)($params['callback_data'] ?? '')]),

				// 官方确实未开放的能力
				'get_group_list'                    => $this->fail(-501, 'QQ 官方机器人不支持获取群列表'),
				'get_friend_list'                   => $this->fail(-501, 'QQ 官方机器人不支持获取好友列表（官方未提供该接口）'),
				'get_friend_msg_history'            => $this->fail(-501, 'QQ 官方机器人不支持拉取历史消息'),
				'get_group_msg_history'             => $this->fail(-501, 'QQ 官方机器人不支持拉取历史消息'),
				'set_group_whole_ban'               => $this->fail(-501, 'QQ 官方机器人不支持全员禁言'),
				default                             => $this->fail(-404, "QQ 官方机器人不支持该操作：{$action}"),
			};
		} catch (\Throwable $e) {
			Logger::error("[QqBot] 调用 {$action} 异常：" . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
			return $this->fail(-1, $e->getMessage());
		}
	}

	// ---------------- 发消息 ----------------

	private function sendMessage(string $action, array $p): object {
		$isGroup = $this->isGroup($action, $p);
		$target  = (string)($isGroup ? ($p['group_id'] ?? ($p['group_openid'] ?? '')) : ($p['user_id'] ?? ($p['user_openid'] ?? '')));
		if ($target === '') {
			return $this->fail(-400, '缺少目标 ID（group_openid / user_openid）');
		}

		$segments = $this->normalizeSegments($p['message'] ?? '');
		[$msgType, $extra] = $this->buildMessageBody($segments, $isGroup, $p);

		// 被动回复上下文（msg_id + msg_seq）
		$passive = false;
		$ctx = EventClient::passiveContext(($isGroup ? 'group:' : 'user:') . $target);
		if ($ctx !== null && empty($extra['msg_id'])) {
			$extra['msg_id']  = $ctx['msg_id'];
			$extra['msg_seq'] = $ctx['msg_seq'];
			$passive = true;
		}

		$path = $isGroup
			? '/v2/groups/' . urlencode($target) . '/messages'
			: '/v2/users/' . urlencode($target) . '/messages';

		$result = $this->request('POST', $path, array_merge(['msg_type' => $msgType], $extra));
		if (($result['code'] ?? 0) !== 0 && isset($result['code'])) {
			$code = (int)$result['code'];
			$msg  = (string)($result['message'] ?? '发送失败');
			Logger::warning("[QqBot] 消息发送失败 | {$path} | passive=" . ($passive ? '1' : '0') . " | code={$code} | {$msg}");
			return $this->fail($code, $msg);
		}
		Logger::debug("[QqBot] 消息已发送 | {$path} | passive=" . ($passive ? '1' : '0') . " | id=" . (string)($result['id'] ?? ''));
		return $this->ok([
			'message_id' => $result['id'] ?? '',
			'timestamp'  => $result['timestamp'] ?? '',
		]);
	}

	/** 组装消息体：文本 / markdown / 富媒体 */
	private function buildMessageBody(array $segments, bool $isGroup, array $p): array {
		$text = '';
		$markdown = '';
		$media = null;
		$keyboard = $p['keyboard'] ?? null;

		foreach ($segments as $seg) {
			$type = (string)($seg['type'] ?? 'text');
			$data = (array)($seg['data'] ?? []);
			switch ($type) {
				case 'text':
					$text .= (string)($data['text'] ?? '');
					break;
				case 'at':
					$qq = (string)($data['qq'] ?? '');
					$text .= $qq === 'all' ? '<@all>' : '<@!' . $qq . '>';
					break;
				case 'markdown':
					$markdown .= (string)($data['content'] ?? '');
					break;
				case 'keyboard':
					$keyboard = $data['content'] ?? ($data['rows'] ?? $keyboard);
					break;
				default:
					// 富媒体：官方要求先上传再以 msg_type=7 发送
					$media ??= ['segment' => $seg];
			}
		}

		if ($media !== null) {
			$fileInfo = $this->uploadMedia($media['segment'], $isGroup, $p);
			if ($fileInfo !== null) {
				$body = ['media' => ['file_info' => $fileInfo]];
				if ($keyboard !== null) $body['keyboard'] = $keyboard;
				return [7, $body];
			}
			Logger::warning('[QqBot] 富媒体上传失败，降级为文本发送');
		}

		if ($markdown !== '') {
			$body = ['markdown' => ['content' => $markdown]];
			if ($keyboard !== null) $body['keyboard'] = is_array($keyboard) && isset($keyboard['rows']) ? ['content' => ['rows' => $keyboard['rows']]] : $keyboard;
			return [2, $body];
		}

		$body = ['content' => $text !== '' ? $text : '（空消息）'];
		if ($keyboard !== null) {
			$body['keyboard'] = is_array($keyboard) && isset($keyboard['rows']) ? ['content' => ['rows' => $keyboard['rows']]] : $keyboard;
		}
		return [0, $body];
	}

	/** 上传富媒体，返回 file_info */
	private function uploadMedia(array $seg, bool $isGroup, array $p): ?string {
		$type = (string)($seg['type'] ?? '');
		$data = (array)($seg['data'] ?? []);
		$file = (string)($data['file'] ?? ($data['url'] ?? ''));
		if ($file === '') return null;

		$fileType = match ($type) {
			'image'  => 1,
			'video'  => 2,
			'record' => 3,
			default  => 4,
		};

		$target = (string)($isGroup ? ($p['group_id'] ?? ($p['group_openid'] ?? '')) : ($p['user_id'] ?? ($p['user_openid'] ?? '')));
		if ($target === '') return null;

		$body = ['file_type' => $fileType, 'srv_send_msg' => false];
		if (str_starts_with($file, 'http://') || str_starts_with($file, 'https://')) {
			$body['url'] = $file;
		} elseif (str_starts_with($file, 'base64://')) {
			$body['file_data'] = substr($file, 9);
		} elseif (is_file($file) && filesize($file) <= 5 * 1024 * 1024) {
			$body['file_data'] = base64_encode((string)file_get_contents($file));
		} else {
			Logger::warning('[QqBot] 富媒体仅支持 http(s) URL、base64:// 或 ≤5MB 本地文件：' . $file);
			return null;
		}
		$body['file_name'] = (string)($data['name'] ?? basename(parse_url($file, PHP_URL_PATH) ?: 'file'));

		$path = $isGroup
			? '/v2/groups/' . urlencode($target) . '/files'
			: '/v2/users/' . urlencode($target) . '/files';
		$result = $this->request('POST', $path, $body, 10);
		return $result['file_info'] ?? null;
	}

	// ---------------- 撤回 / 成员 / 互动 ----------------

	private function recall(array $p): object {
		$messageId = (string)($p['message_id'] ?? '');
		if ($messageId === '') return $this->fail(-400, '缺少 message_id');
		$isGroup = !empty($p['group_id']);
		$target  = (string)($p['group_id'] ?? ($p['user_id'] ?? ''));
		$path = $isGroup
			? '/v2/groups/' . urlencode($target) . '/messages/' . urlencode($messageId)
			: '/v2/users/' . urlencode($target) . '/messages/' . urlencode($messageId);
		$result = $this->request('DELETE', $path);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '撤回失败'));
		}
		return $this->ok(new \stdClass());
	}

	/** 群 OpenID（兼容 group_id / group_openid 两种写法） */
	private function groupOpenid(array $p): string {
		$id = (string)($p['group_id'] ?? ($p['group_openid'] ?? ($p['group'] ?? '')));
		if ($id === '') {
			throw new \InvalidArgumentException('缺少 group_openid');
		}
		return $id;
	}

	/** 通用请求：成功返回 data，失败返回标准 failed 结构 */
	private function requestOk(string $method, string $path, array $body = []): object {
		$result = $this->request($method, $path, $body);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '请求失败'));
		}
		return $this->ok($result);
	}

	private function getGroupInfo(array $p): object {
		$result = $this->request('GET', '/v2/groups/' . urlencode($this->groupOpenid($p)) . '/info');
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '获取群信息失败'));
		}
		return $this->ok([
			'group_id'        => (string)($result['group_openid'] ?? ''),
			'group_name'      => (string)($result['group_name'] ?? ''),
			'group_memo'      => (string)($result['group_finger_memo'] ?? ''),
			'group_class_text'=> (string)($result['group_class_text'] ?? ''),
			'member_count'    => (int)($result['group_member_num'] ?? 0),
			'max_member_count'=> (int)($result['group_member_num'] ?? 0),
		]);
	}

	private function getGroupMemberInfo(array $p): object {
		$group = $this->groupOpenid($p);
		$member = (string)($p['user_id'] ?? ($p['member_openid'] ?? ''));
		if ($member === '') return $this->fail(-400, '缺少 member_openid');
		$result = $this->request('GET', '/v2/groups/' . urlencode($group) . '/members/' . urlencode($member));
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '获取成员信息失败'));
		}
		return $this->ok($this->mapMember($result));
	}

	/** 群成员批量移除（单次最多 20 个） */
	private function removeMembers(array $p): object {
		$group = $this->groupOpenid($p);
		$openids = [];
		if (!empty($p['user_id'])) $openids[] = (string)$p['user_id'];
		foreach ((array)($p['user_ids'] ?? []) as $id) $openids[] = (string)$id;
		$openids = array_values(array_unique(array_filter($openids, fn($v) => $v !== '')));
		if ($openids === []) return $this->fail(-400, '缺少待移除成员 openid');
		if (count($openids) > 20) return $this->fail(-400, '单次最多移除 20 个成员');
		$result = $this->request('POST', '/v2/groups/' . urlencode($group) . '/batch_remove_members', [
			'member_openids'           => $openids,
			'add_to_member_blacklist'  => (bool)($p['reject_add_request'] ?? false),
		]);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '移除成员失败'));
		}
		return $this->ok(['result' => $result['remove_members_result'] ?? 'success']);
	}

	/** 群成员禁言：OneBot 的 duration 单位是秒，官方要求 RFC3339 到期时间 */
	private function muteMember(array $p): object {
		$group = $this->groupOpenid($p);
		$member = (string)($p['user_id'] ?? '');
		if ($member === '') return $this->fail(-400, '缺少 member_openid');
		$duration = (int)($p['duration'] ?? 0);
		$expire = $duration > 0 ? date('c', time() + min($duration, 2592000)) : '';
		$result = $this->request('POST', '/v2/groups/' . urlencode($group) . '/restrict_chat_setting', [
			'members' => [[
				'op'             => $duration > 0 ? 'add' : 'del',
				'member_openid'  => $member,
				'mute_expire_at' => $expire,
			]],
		]);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '设置禁言失败'));
		}
		return $this->ok(new \stdClass());
	}

	private function joinRequestList(array $p): object {
		$group = $this->groupOpenid($p);
		$result = $this->request('GET', '/v2/groups/' . urlencode($group) . '/join_request_list', [
			'cursor' => (string)($p['cursor'] ?? ''),
			'limit'  => (int)($p['limit'] ?? ($p['count'] ?? 20)),
		]);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '获取入群申请失败'));
		}
		$list = array_map(function ($item) {
			$item = (array)$item;
			return [
				'request_id'  => (string)($item['join_request_id'] ?? ''),
				'user_id'     => (string)($item['member_openid'] ?? ''),
				'nickname'    => (string)($item['username'] ?? ''),
				'comment'     => (string)($item['risk_tips'] ?? ''),
				'flag'        => (string)($item['join_request_id'] ?? ''),
				'time'        => (string)($item['apply_at'] ?? ''),
			];
		}, (array)($result['list'] ?? []));
		return $this->ok(['requests' => $list, 'next_cursor' => (string)($result['next_cursor'] ?? '')]);
	}

	private function approveJoinRequest(array $p): object {
		$group  = $this->groupOpenid($p);
		$member = (string)($p['user_id'] ?? ($p['member_openid'] ?? ''));
		if ($member === '') return $this->fail(-400, '缺少 member_openid');
		$approve = filter_var($p['approve'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
		$subType = strtolower((string)($p['sub_type'] ?? 'add'));
		$body = [
			'op'                  => $approve ? 'approve' : 'decline',
			'join_request_id'     => (string)($p['flag'] ?? ($p['join_request_id'] ?? '')),
		];
		if (!$approve) {
			$body['reject_reason'] = (string)($p['reason'] ?? '');
		}
		$result = $this->request('POST', '/v2/groups/' . urlencode($group) . '/approval_join_request/' . urlencode($member), $body);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '审批失败'));
		}
		return $this->ok(new \stdClass());
	}

	private function blacklistOp(array $p): object {
		$group = $this->groupOpenid($p);
		$openids = [];
		if (!empty($p['user_id'])) $openids[] = (string)$p['user_id'];
		foreach ((array)($p['user_ids'] ?? []) as $id) $openids[] = (string)$id;
		$openids = array_values(array_unique(array_filter($openids, fn($v) => $v !== '')));
		if ($openids === []) return $this->fail(-400, '缺少 member_openid');
		$result = $this->request('POST', '/v2/groups/' . urlencode($group) . '/member_blacklist', [
			'op'            => !empty($p['approve']) ? 'add' : 'del',
			'member_openids'=> $openids,
		]);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '黑名单操作失败'));
		}
		return $this->ok(new \stdClass());
	}

	/** 官方成员实体 → OneBot 成员结构 */
	private function mapMember(mixed $m): array {
		$m = (array)$m;
		return [
			'user_id'  => (string)($m['member_openid'] ?? ($m['user_openid'] ?? ($m['openid'] ?? ''))),
			'nickname' => (string)($m['username'] ?? ''),
			'card'     => (string)($m['username'] ?? ''),
			'role'     => $this->mapMemberRole((string)($m['member_role'] ?? 'member')),
			'join_time'=> (string)($m['joined_at'] ?? ''),
			'is_bot'   => (bool)($m['bot'] ?? false),
		];
	}

	private function mapMemberRole(string $role): string {
		return match (strtolower($role)) {
			'owner' => 'owner',
			'admin' => 'admin',
			default => 'member',
		};
	}

	private function getGroupMembers(array $p): object {
		$groupOpenid = $this->groupOpenid($p);
		$result = $this->request('GET', '/v2/groups/' . urlencode($groupOpenid) . '/members', [
			'limit'  => (int)($p['limit'] ?? 30),
			'cursor' => (string)($p['cursor'] ?? ''),
		]);
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '获取成员失败'));
		}
		$members = array_map(fn($m) => $this->mapMember($m), (array)($result['members'] ?? []));
		return $this->ok(['members' => $members, 'next_cursor' => (string)($result['next_cursor'] ?? '')]);
	}

	private function getLoginInfo(): object {
		$result = $this->request('GET', '/users/@me');
		if (isset($result['code']) && (int)$result['code'] !== 0) {
			return $this->fail((int)$result['code'], (string)($result['message'] ?? '获取账号信息失败'));
		}
		return $this->ok([
			'user_id'  => (string)($result['id'] ?? ''),
			'nickname' => (string)($result['username'] ?? ''),
		]);
	}

	private function interactionReply(array $p): object {
		$id = (string)($p['interaction_id'] ?? ($p['id'] ?? ''));
		if ($id === '') return $this->fail(-400, '缺少 interaction_id');
		$result = $this->request('PUT', '/interactions/' . urlencode($id), ['code' => (int)($p['code'] ?? 0)]);
		if (isset($result['code']) && (int)$result['code'] !== 0 && isset($result['message'])) {
			return $this->fail((int)$result['code'], (string)$result['message']);
		}
		return $this->ok(new \stdClass());
	}

	// ---------------- HTTP ----------------

	/**
	 * 调用官方 OpenAPI
	 * @return array 解析后的 JSON；网络/HTTP 错误返回 ['code' => -1, 'message' => ...]
	 */
	private function request(string $method, string $path, array $body = [], int $timeout = 20): array {
		$token = Token::get();
		if ($token === null) {
			return ['code' => -1, 'message' => '无法获取 AccessToken'];
		}
		$url = Token::apiBase() . $path;
		$curl = new CoroutineCurl();
		$curl->timeout($timeout);
		$curl->addHeaders('Authorization', 'QQBot ' . $token);
		$curl->addHeaders('Content-Type', 'application/json');

		try {
			switch (strtoupper($method)) {
				case 'GET':
					$curl->get($url, $body ? http_build_query($body, '', '&', PHP_QUERY_RFC3986) : '');
					break;
				case 'DELETE':
					$curl->addHeaders('X-HTTP-Method-Override', 'DELETE');
					$curl->delete($url, $body ? json_encode($body, JSON_UNESCAPED_UNICODE) : '{}');
					break;
				case 'PUT':
					$curl->put($url, json_encode($body, JSON_UNESCAPED_UNICODE));
					break;
				default:
					$curl->post($url, json_encode($body, JSON_UNESCAPED_UNICODE));
					break;
			}
		} catch (\Throwable $e) {
			return ['code' => -1, 'message' => '网络异常：' . $e->getMessage()];
		}

		$raw = (string)$curl->result;
		$json = json_decode($raw, true);
		if (!is_array($json)) {
			$json = [];
		}
		$httpCode = (int)($curl->info['http_code'] ?? 0);
		if ($httpCode >= 400 && !isset($json['code'])) {
			// 官方错误体通常是 {"code":...,"message":...}
			$json['code'] = $httpCode;
			$json['message'] = $json['message'] ?? ('HTTP ' . $httpCode . '：' . substr($raw, 0, 200));
		}
		if ($httpCode !== 0 && $httpCode < 400) {
			unset($json['code']); // 成功响应里没有 code 字段，避免误判
		}
		Logger::debug("[QqBot] {$method} {$path} | HTTP {$httpCode} | " . substr($raw, 0, 200));
		return $json;
	}

	// ---------------- 工具 ----------------

	private function isGroup(string $action, array $p): bool {
		if ($action === 'send_group_msg') return true;
		if ($action === 'send_private_msg') return false;
		if (isset($p['message_type'])) {
			$type = strtolower((string)$p['message_type']);
			if ($type === 'private') return false;
			if ($type === 'group') return true;
		}
		return !empty($p['group_id']) || !empty($p['group_openid']);
	}

	/** 把 OneBot message（数组或 CQ 码字符串）统一成消息段数组 */
	private function normalizeSegments(mixed $message): array {
		if (is_string($message)) {
			if ($message === '') return [];
			$segments = [];
			$parts = preg_split('/\\[CQ:(.+?)\\]/s', $message, -1, PREG_SPLIT_DELIM_CAPTURE);
			foreach ((array)$parts as $i => $part) {
				if ($i % 2 === 1) {
					$items = explode(',', $part);
					$type  = array_shift($items);
					$data  = [];
					foreach ($items as $item) {
						$kv = explode('=', $item, 2);
						if (count($kv) === 2) {
							$data[$kv[0]] = str_replace(['&#91;', '&#93;', '&#44;', '&amp;'], ['[', ']', ',', '&'], $kv[1]);
						}
					}
					$segments[] = ['type' => $type, 'data' => $data];
				} elseif (trim((string)$part) !== '') {
					$segments[] = ['type' => 'text', 'data' => ['text' => str_replace(['&#91;', '&#93;', '&#44;', '&amp;'], ['[', ']', ',', '&'], (string)$part)]];
				}
			}
			return $segments;
		}
		$segments = [];
		foreach ((array)$message as $seg) {
			$segments[] = (array)$seg;
		}
		return $segments;
	}

	public function meta(): array {
		$meta = parent::meta();
		$meta['api']      = Token::apiBase();
		$meta['appid']    = Token::appId();
		$meta['gateway']  = EventClient::status();
		return $meta;
	}
}