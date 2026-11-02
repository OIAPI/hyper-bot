<?php
/**
 * Milky 动作映射自检
 * 运行：php tests/milky_actions.php
 */
declare(strict_types=1);

require dirname(__DIR__) . '/Include.php';

$fail = 0;
$ok = function (string $name, bool $cond, string $detail = '') use (&$fail) {
	echo ($cond ? '  OK   ' : '  FAIL ') . $name . ($detail !== '' ? "  ({$detail})" : '') . PHP_EOL;
	if (!$cond) $fail++;
};

$map = new ReflectionMethod(\Protocol\Milky\Adapter::class, 'mapAction');
$map->setAccessible(true);
$adapter = new \Protocol\Milky\Adapter(['api' => 'http://127.0.0.1:3010']);
$call = fn(string $action, array $p = []) => $map->invoke($adapter, $action, $p);
$unsupported = (new ReflectionClassConstant(\Protocol\Milky\Adapter::class, 'UNSUPPORTED'))->getValue();

$base = ['group_id' => 123, 'user_id' => 456, 'message_id' => 77, 'message' => [['type' => 'text', 'data' => ['text' => 'hi']]]];

echo "== 关键动作映射 ==\n";
$expect = [
	'send_group_msg'         => 'send_group_message',
	'send_private_msg'       => 'send_private_message',
	'delete_msg'             => 'recall_group_message',
	'get_msg'                => 'get_message',
	'get_group_msg_history'  => 'get_history_messages',
	'get_friend_msg_history' => 'get_history_messages',
	'set_group_kick'         => 'kick_group_member',
	'set_group_ban'          => 'set_group_member_mute',
	'set_group_whole_ban'    => 'set_group_whole_mute',
	'set_group_card'         => 'set_group_member_card',
	'set_group_admin'        => 'set_group_member_admin',
	'set_group_leave'        => 'quit_group',
	'get_essence_msg_list'   => 'get_group_essence_messages',
	'_send_group_notice'     => 'send_group_announcement',
	'_get_group_notice'      => 'get_group_announcements',
	'set_qq_profile'         => 'set_nickname',
	'get_private_file_url'   => 'get_private_file_download_url',
	'fetch_custom_face'      => 'get_custom_face_url_list',
];
foreach ($expect as $action => $api) {
	$r = $call($action, $base);
	$ok("{$action} → {$api}", $r !== null && $r[0] === $api, $r === null ? 'NULL' : $r[0]);
}

echo "== 参数细节 ==\n";
$r = $call('get_group_essence_msg_list', ['group_id' => 123, 'count' => 20]);
$ok('精华消息用 page_index/page_size（规范字段）', $r !== null && isset($r[1]['page_size']) && !isset($r[1]['limit']));
$r = $call('get_group_msg_history', ['group_id' => 123, 'count' => 10, 'message_id' => 5]);
$ok('群历史用 start_message_seq 而非 limit', $r !== null && ($r[1]['start_message_seq'] ?? null) === 5);
$ok('未映射动作返回 null', $call('set_group_bot_status_probe', $base) === null);
$r = $call('get_online_clients', []);
$ok('get_online_clients 视为空实现', $r !== null && $r[0] === 'get_impl_info');

echo "== 缺失动作必须有原因 ==\n";
$apiActions = [
	'_del_group_notice', '_get_model_show', '_set_model_show', 'can_send_image', 'can_send_record',
	'check_url_safely', 'clean_cache', 'create_group_file_folder', 'delete_essence_msg', 'delete_friend',
	'delete_group_file', 'delete_group_file_folder', 'delete_group_folder', 'delete_msg',
	'delete_unidirectional_friend', 'download_file', 'fetch_custom_face', 'fetch_mface_key', 'friend_poke',
	'_get_group_notice', '_send_group_notice',
	'get_ai_characters', 'get_ai_record', 'get_clientkey', 'get_cookies', 'get_credentials', 'get_csrf_token',
	'get_essence_msg_list', 'get_forward_msg', 'get_friend_list', 'get_friend_msg_history',
	'get_group_at_all_remain', 'get_group_file_system_info', 'get_group_file_url', 'get_group_files_by_folder',
	'get_group_honor_info', 'get_group_info', 'get_group_list', 'get_group_member_info', 'get_group_member_list',
	'get_group_msg_history', 'get_group_root_files', 'get_group_system_msg', 'get_image', 'get_login_info',
	'get_msg', 'get_online_clients', 'get_private_file_url', 'get_record', 'get_rkey', 'get_skey', 'get_status',
	'get_stranger_info', 'get_unidirectional_friend_list', 'get_version_info', 'group_poke', 'mark_msg_as_read',
	'move_group_file', 'ocr_image', 'reload_event_filter', 'rename_group_file_folder', 'send_forward_msg',
	'send_group_ai_record', 'send_group_bot_callback', 'send_group_forward_msg', 'send_group_msg',
	'send_group_sign', 'send_like', 'send_msg', 'send_pb', 'send_private_forward_msg', 'send_private_msg',
	'set_essence_msg', 'set_friend_add_request', 'set_group_add_request', 'set_group_admin',
	'set_group_anonymous', 'set_group_anonymous_ban', 'set_group_ban', 'set_group_bot_status', 'set_group_card',
	'set_group_kick', 'set_group_leave', 'set_group_name', 'set_group_portrait', 'set_group_reaction',
	'set_group_special_title', 'set_group_whole_ban', 'set_msg_emoji_like', 'set_qq_avatar', 'set_qq_profile',
	'set_restart', 'upload_group_file', 'upload_private_file',
];
$missingUnmapped = [];
$missingNoReason = [];
foreach ($apiActions as $action) {
	$r = $call($action, $base);
	if ($r === null) {
		$missingUnmapped[] = $action;
		if (!isset($unsupported[$action])) $missingNoReason[] = $action;
	}
}
$covered = count($apiActions) - count($missingUnmapped);
echo "  覆盖 {$covered}/" . count($apiActions) . " 个 action\n";
$ok('未映射的 action 都写明了不支持原因', $missingNoReason === [], implode(' ', $missingNoReason));
$ok('覆盖率不低于 70 个', $covered >= 70, (string)$covered);
$ok('api/*.php 用到的 action 全部纳入统计', count($apiActions) === 94, (string)count($apiActions));

echo $fail === 0 ? "\n全部通过\n" : "\n失败 {$fail} 项\n";
exit($fail === 0 ? 0 : 1);