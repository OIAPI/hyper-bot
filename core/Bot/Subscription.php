<?php
namespace Core\Bot;

use Core\Config;
use Core\Log\Logger;

/**
 * 消息订阅（v2）：把 v1 散落在三处的过滤规则收敛为一个对象。
 *
 * v1 的订阅其实分散在三个地方，且语义各不相干：
 *   1. Config.ini [type]        message/notice/request/meta_event 是否处理（EventDispatcher）
 *   2. Config.ini [message] type group/protected/all（Trigger\Message 校验必填字段）
 *   3. Config.ini [notice] {type} = 1（Trigger\Notice 逐类型开关）
 *
 * 后果：想「只收群消息、且只要入群通知」要改三个小节，还得知道 protected 是私聊别名。
 * v2 把它们合成一个 Subscription，读旧键（向后兼容）+ 写新键（[subscription]）。
 *
 * 保留订阅的理由（回答「为什么保留消息订阅」）：
 *   - 它不是协议层的东西，而是**业务路由**的第一层过滤：一个群里可能同时接了
 *     多个协议端，而业务只关心其中一部分事件。订阅让「不去解密/不落库/不跑插件」
 *     发生在最便宜的位置；
 *   - 它是可观测的：每条被丢弃的事件都能给出明确原因（见 rejectReason）；
 *   - 新协议（QQ 官方 / Milky）会产生大量业务不关心的事件（如心跳、成员变更），
 *     订阅是唯一的止损点。
 */
final class Subscription
{
    public function __construct(
        public bool $message = true,
        public bool $notice = true,
        public bool $request = true,
        public bool $metaEvent = false,
        /** group | private | all */
        public string $messageScope = 'group',
        /** 空数组 = 不接收任何通知（与 v1 语义一致：notice 必须显式 =1 才开） */
        public array $noticeTypes = [],
        public bool $ignoreSelf = true,
        public bool $ignoreRobots = true,
        /** 机器人 ID 前缀（v1 的 '3889'，官方 QQ 机器人用它区分） */
        public string $robotPrefix = '3889',
    ) {}

    /** 从配置构建：优先读 [subscription]（v2），缺失时回退 v1 的三个小节 */
    public static function fromConfig(): self
    {
        $hasV2 = Config::get('subscription') !== null;
        // 全新部署（三套键一个都没写）= 全开：订阅不再是「必须先手改 ini 才有东西进来」。
        $fresh = !$hasV2 && !Config::has('type') && !Config::has('notice');

        $bool = static function (string $v2Key, string $v1Key, bool $default) use ($hasV2): bool {
            if ($hasV2) {
                $value = Config::get($v2Key, null);
                if ($value !== null) {
                    return Config::bool($v2Key, $default);
                }
            }
            return Config::bool($v1Key, $default);
        };

        $message = $bool('subscription.message', 'type.message', true);
        $notice  = $bool('subscription.notice', 'type.notice', true);
        $request = $bool('subscription.request', 'type.request', true);
        $meta    = $bool('subscription.meta_event', 'type.meta_event', $fresh);

        // 消息范围：group / private / all（v1 的 protected 是私聊别名）
        $scope = '';
        if ($hasV2) {
            $scope = Config::string('subscription.message_scope', '');
        }
        if ($scope === '') {
            $scope = $fresh ? 'all' : Config::string('message.type', 'group');
        }
        $scope = strtolower(trim($scope));
        if ($scope === 'protected') {
            $scope = 'private';
        }
        if (!in_array($scope, ['group', 'private', 'all'], true)) {
            $scope = 'group';
        }

        // 通知类型白名单：v2 用逗号分隔，v1 用 notice.{type} = 1
        //
        // 关键区分：v2 的「键存在但为空」= 用户主动取消全部通知，必须保持空；
        // 只有「键根本不存在」才回退 v1。否则在 WebUI 清空勾选、重启后会被 v1 的
        // [notice] 老键复活，用户看到的现象就是「关了又自己开了」。
        // 用 has() 而不是 get()：get() 把空串归一成默认值，无法区分「显式清空」。
        $hasV2NoticeTypes = $hasV2 && Config::has('subscription.notice_types');
        $noticeTypes = $hasV2NoticeTypes ? Config::list('subscription.notice_types', []) : [];
        if (!$hasV2NoticeTypes) {
            foreach (Config::section('notice') as $type => $value) {
                if (!is_scalar($value)) {
                    continue;
                }
                if ((is_numeric($value) && (int)$value === 1) || $value === true) {
                    $noticeTypes[] = strtolower((string)$type);
                }
            }
            // 全新部署或 v1 没写过 [notice]：默认全选，避免「装了却什么都收不到」
            if ($noticeTypes === [] && $fresh) {
                $noticeTypes = SubscriptionCatalog::types();
            }
        }
        $noticeTypes = array_values(array_unique(array_filter(
            array_map(static fn($v) => strtolower(trim((string)$v)), $noticeTypes)
        )));

        return new self(
            message: $message,
            notice: $notice,
            request: $request,
            metaEvent: $meta,
            messageScope: $scope,
            noticeTypes: $noticeTypes,
            ignoreSelf: $bool('subscription.ignore_self', 'type.ignore_self', true),
            ignoreRobots: $bool('subscription.ignore_robots', 'type.ignore_robots', true),
            robotPrefix: Config::string('subscription.robot_prefix', '3889'),
        );
    }

    /**
     * 判定是否接收该事件。
     * @return string|null null = 接收；非 null = 拒收原因（用于日志/WebUI 可视化）
     */
    public function rejectReason(Event $event): ?string
    {
        $post = $event->postType();
        if ($post === '') {
            return '缺少 post_type';
        }

        // 1. 触发类型开关
        $enabled = match ($post) {
            'message'    => $this->message,
            'notice'     => $this->notice,
            'request'    => $this->request,
            'meta_event' => $this->metaEvent,
            default      => false,
        };
        if (!$enabled) {
            return "类型 {$post} 未订阅";
        }

        // 2. 自己不处理自己发的消息
        if ($this->ignoreSelf && $event->isSelf()) {
            return '忽略机器人自身消息';
        }

        // 3. 官方机器人（ID 前缀）
        if ($this->ignoreRobots && $this->robotPrefix !== '') {
            if (str_starts_with($event->userId(), $this->robotPrefix)) {
                return '忽略官方机器人消息';
            }
        }

        // 4. 消息范围
        if ($post === 'message') {
            $type = $event->messageType();
            if ($type === '') {
                return '缺少 message_type';
            }
            // 频道（guild）在订阅语义里并入群聊：都是「一个群/频道里的人在说话」，
            // 否则选「仅群聊」时 QQ 官方频道的消息会被整体丢掉，用户却看不出原因。
            $scopeType = $type === 'guild' ? 'group' : $type;
            if ($this->messageScope === 'group' && $scopeType !== 'group') {
                return "消息范围仅群聊，当前 {$type}";
            }
            if ($this->messageScope === 'private' && $scopeType !== 'private') {
                return "消息范围仅私聊，当前 {$type}";
            }
            if ($event->rawMessage() === '') {
                return '缺少消息内容 raw_message';
            }
            // 群消息必须有 group_id；私聊不允许带 group_id（v1 的校验语义）
            if ($type === 'group' && $event->groupId() === '') {
                return '群消息缺少 group_id';
            }
        }

        // 5. 通知类型白名单
        if ($post === 'notice') {
            $noticeType = $event->noticeType();
            if ($noticeType === '') {
                return '缺少 notice_type';
            }
            if ($this->noticeTypes === []) {
                return '未启用任何通知类型';
            }
            if (!in_array($noticeType, $this->noticeTypes, true)) {
                return "通知类型 {$noticeType} 未启用";
            }
        }

        // 6. 请求类型
        if ($post === 'request' && $event->requestType() === '') {
            return '缺少 request_type';
        }

        return null;
    }

    public function allows(Event $event): bool
    {
        return $this->rejectReason($event) === null;
    }

    /** WebUI 展示/编辑用的结构 */
    public function toArray(): array
    {
        return [
            'message'       => $this->message,
            'notice'        => $this->notice,
            'request'       => $this->request,
            'meta_event'    => $this->metaEvent,
            'message_scope' => $this->messageScope,
            'notice_types'  => $this->noticeTypes,
            'ignore_self'   => $this->ignoreSelf,
            'ignore_robots' => $this->ignoreRobots,
            'robot_prefix'  => $this->robotPrefix,
            // 通知类型清单：catalog 用于兼容旧前端，groups 用于分区渲染
            'notice_catalog'  => self::noticeCatalog(),
            'notice_groups'   => SubscriptionCatalog::groups(),
            'notice_stats'    => SubscriptionCatalog::stats(),
            'notice_defaults' => SubscriptionCatalog::types(),
        ];
    }

    /**
     * 已知通知类型（OneBot11 标准 + Milky + QQ 官方机器人转换后的类型）。
     *
     * 这份目录必须覆盖三个转换器能产出的**全部** notice_type：
     * 漏一个，WebUI 里就没有对应勾选项，该通知在用户视角就是「永远收不到」。
     * tests/subscription_test.php 会扫描三个 Converter 的字面量并断言无遗漏。
     */
    public static function noticeCatalog(): array
    {
        $flat = [];
        foreach (self::noticeGroups() as $group) {
            foreach ($group['types'] as $key => $label) {
                $flat[$key] = $label;
            }
        }
        return $flat;
    }

    /** 分组后的目录：由 SubscriptionCatalog 提供（内置 + 插件 + 面板自定义） */
    public static function noticeGroups(): array
    {
        $groups = [];
        foreach (SubscriptionCatalog::groups() as $group) {
            $groups[] = ['title' => $group['title'], 'types' => $group['types']];
        }
        return $groups;
    }

    /** 旧的写死清单（仅作参考，实际以 SubscriptionCatalog 为准） */
    private static function legacyNoticeGroups(): array
    {
        return [
            [
                'title' => '群成员',
                'types' => [
                    'group_increase' => '成员增加（含机器人入群）',
                    'group_decrease' => '成员减少（退群 / 被踢）',
                    'group_admin'    => '管理员变动',
                    'group_ban'      => '禁言 / 解除禁言',
                    'group_card'     => '群名片变更',
                    'group_title'    => '群头衔变更',
                ],
            ],
            [
                'title' => '群消息',
                'types' => [
                    'group_recall'        => '消息撤回',
                    'group_upload'        => '群文件上传',
                    'group_msg_emoji_like'=> '消息表情表态',
                    'group_lucky_king'    => '运气王',
                    'honor'               => '群荣誉变更',
                    'notify'              => '戳一戳 / 提醒（OneBot 标准写法）',
                    'poke'                => '戳一戳（Milky 转换写法）',
                ],
            ],
            [
                'title' => '群状态',
                'types' => [
                    'group_name_change' => '群名变更',
                    'group_disband'     => '群解散',
                    'peer_pin_change'   => '群待办 / 置顶变更',
                    'essence'           => '群精华消息变更',
                ],
            ],
            [
                'title' => '好友与私聊',
                'types' => [
                    'friend_add'      => '好友添加',
                    'friend_del'      => '好友删除',
                    'friend_recall'   => '私聊消息撤回',
                    'offline_file'    => '私聊文件上传',
                    'private_msg_receive' => '私聊消息接收开关变更（QQ 官方）',
                    // 早期版本把好友删除写成了这个（语义错但已落库），保留以便老配置可读
                    'friend_recall_placeholder' => '好友删除（旧版兼容键）',
                ],
            ],
            [
                'title' => '频道（QQ 官方）',
                'types' => [
                    'message_reaction_add'    => '频道表情表态',
                    'message_reaction_remove' => '频道表情取消',
                    'message_audit_pass'      => '频道消息审核通过',
                    'message_audit_reject'    => '频道消息审核拒绝',
                    'guild_member_add'        => '频道成员加入',
                    'guild_member_update'     => '频道成员变更',
                    'guild_member_remove'     => '频道成员离开',
                    'guild_create'            => '频道服务器创建',
                    'guild_update'            => '频道服务器更新',
                    'guild_delete'            => '频道服务器删除',
                    'channel_create'          => '子频道创建',
                    'channel_update'          => '子频道更新',
                    'channel_delete'          => '子频道删除',
                    'forum_event'             => '论坛事件（帖子 / 回复）',
                    'guild_event'             => '其它频道生命周期事件',
                ],
            ],
            [
                'title' => '系统',
                'types' => [
                    'group_msg_receive'   => '群消息接收开关变更（QQ 官方）',
                    'subscribe_message_status' => '订阅消息授权状态（QQ 官方）',
                    'interaction'         => '互动事件 / 按钮回调（QQ 官方）',
                    'client_status'       => '客户端上下线',
                    'bot_offline'         => '机器人离线（Milky）',
                    'unknown_event'       => '未知事件（协议新增，兜底入口）',
                ],
            ],
        ];
    }

    /** 写回配置（WebUI 保存）。只写 [subscription] 新键，不动 v1 键，避免破坏老部署。 */
    public function save(array $input): bool
    {
        $map = [
            'message'       => ['subscription.message', 'bool'],
            'notice'        => ['subscription.notice', 'bool'],
            'request'       => ['subscription.request', 'bool'],
            'meta_event'    => ['subscription.meta_event', 'bool'],
            'message_scope' => ['subscription.message_scope', 'string'],
            'ignore_self'   => ['subscription.ignore_self', 'bool'],
            'ignore_robots' => ['subscription.ignore_robots', 'bool'],
        ];

        $ok = true;
        foreach ($map as $field => [$key, $type]) {
            if (!array_key_exists($field, $input)) {
                continue;
            }
            $value = $input[$field];
            if ($type === 'bool') {
                $value = (bool)$value;
            } elseif ($type === 'string') {
                $value = strtolower(trim((string)$value));
                if ($field === 'message_scope' && !in_array($value, ['group', 'private', 'all'], true)) {
                    return false;
                }
            }
            $ok = Config::set($key, $value) && $ok;
        }

        if (array_key_exists('notice_types', $input)) {
            $types = $input['notice_types'];
            if (is_string($types)) {
                $types = preg_split('/[,，\s]+/u', $types) ?: [];
            }
            $types = array_values(array_filter(array_map(
                static fn($v) => strtolower(trim((string)$v)),
                (array)$types
            )));
            $ok = Config::set('subscription.notice_types', implode(',', $types)) && $ok;
        }

        if (array_key_exists('robot_prefix', $input)) {
            $ok = Config::set('subscription.robot_prefix', trim((string)$input['robot_prefix'])) && $ok;
        }

        return $ok;
    }
}
