<?php
namespace Core\Bot;

use Core\Fs;

/**
 * 可订阅项目录（唯一真源）。
 *
 * 以前「有哪些通知类型」是写死在 Subscription::noticeGroups() 里的常量，配置侧只能
 * 在 Config.ini 里从固定清单挑，结果是：协议新加一种事件 -> 面板上没有勾选框 ->
 * 用户永远收不到，只能改代码。这里把目录从「写死的常量」升级为「运行时可注册的注册表」：
 *
 *   1. builtin  —— 内置清单（OneBot 11 / Milky / QQ 官方三大协议的全部已知类型）
 *   2. plugin   —— 插件用类注释 @subscribe <type> <label> 声明，注册表自动收录
 *   3. custom   —— 面板里手动添加的类型，持久化到 runtime/subscription-custom.json
 *
 * Config.ini 里只保留「用户选中了哪些」，清单本身不再依赖 ini。
 */
final class SubscriptionCatalog
{
    /** @var array<string, array{title:string, types:array<string,string>, source:string}> */
    private static array $groups = [];

    /** @var array<string, array{label:string, group:string}> */
    private static array $custom = [];

    private static bool $booted = false;

    private static string $customPath = '';

    /** 内置清单：漏一个 = 面板上没有勾选框，测试会扫转换器字面量兜底 */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
        self::$groups = [
            'group_member' => ['title' => '群成员', 'source' => 'builtin', 'types' => [
                'group_increase' => '成员增加（含机器人入群）',
                'group_decrease' => '成员减少（退群 / 被踢）',
                'group_admin'    => '管理员变动',
                'group_ban'      => '禁言 / 解除禁言',
                'group_card'     => '群名片变更',
                'group_title'    => '群头衔变更',
            ]],
            'group_message' => ['title' => '群消息', 'source' => 'builtin', 'types' => [
                'group_recall'         => '消息撤回',
                'group_upload'         => '群文件上传',
                'group_msg_emoji_like' => '消息表情表态',
                'group_lucky_king'     => '运气王',
                'honor'                => '群荣誉变更',
                'notify'               => '戳一戳 / 提醒（OneBot 标准写法）',
                'poke'                 => '戳一戳（Milky 转换写法）',
            ]],
            'group_state' => ['title' => '群状态', 'source' => 'builtin', 'types' => [
                'group_name_change' => '群名变更',
                'group_disband'     => '群解散',
                'peer_pin_change'   => '群待办 / 置顶变更',
                'essence'           => '群精华消息变更',
            ]],
            'friend' => ['title' => '好友与私聊', 'source' => 'builtin', 'types' => [
                'friend_add'                => '好友添加',
                'friend_del'                => '好友删除',
                'friend_recall'             => '私聊消息撤回',
                'offline_file'              => '私聊文件上传',
                'private_msg_receive'       => '私聊消息接收开关变更（QQ 官方）',
                'friend_recall_placeholder' => '好友删除（旧版兼容键）',
            ]],
            'guild' => ['title' => '频道（QQ 官方）', 'source' => 'builtin', 'types' => [
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
            ]],
            'system' => ['title' => '系统', 'source' => 'builtin', 'types' => [
                'group_msg_receive'        => '群消息接收开关变更（QQ 官方）',
                'subscribe_message_status' => '订阅消息授权状态（QQ 官方）',
                'interaction'              => '互动事件 / 按钮回调（QQ 官方）',
                'client_status'            => '客户端上下线',
                'bot_offline'              => '机器人离线（Milky）',
                'unknown_event'            => '未知事件（协议新增，兜底入口）',
            ]],
        ];
        self::loadCustom();
    }

    /** 自定义类型的持久化文件（测试可改路径） */
    public static function path(?string $path = null): string
    {
        if ($path !== null) {
            self::$customPath = $path;
            self::$booted = false;
            self::$custom = [];
            return $path;
        }
        if (self::$customPath === '') {
            self::$customPath = (defined('__root__') ? __root__ : getcwd()) . '/runtime/subscription-custom.json';
        }
        return self::$customPath;
    }

    private static function loadCustom(): void
    {
        self::$custom = [];
        $path = self::path();
        if (!is_file($path)) {
            return;
        }
        $raw = Fs::read($path);
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }
        if (!is_array($data) || !is_array($data['types'] ?? null)) {
            return;
        }
        foreach ($data['types'] as $type => $item) {
            if (!is_string($type) || !preg_match('/^[a-z][a-z0-9_]{2,63}$/', $type)) {
                continue;
            }
            if (!is_array($item)) {
                continue;
            }
            self::$custom[$type] = [
                'label' => trim((string)($item['label'] ?? '')) ?: $type,
                'group' => trim((string)($item['group'] ?? '')) ?: '自定义',
            ];
        }
    }

    private static function persist(): bool
    {
        $path = self::path();
        Fs::ensureDir(dirname($path));
        return Fs::write($path, json_encode(
            ['types' => self::$custom],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        ));
    }

    /**
     * 收录插件声明的订阅（@subscribe type label）。
     * @param array<string, array<string, mixed>> $plugins Registry::plugins() 的结果
     */
    public static function absorb(array $plugins): void
    {
        self::boot();
        foreach ($plugins as $meta) {
            foreach ((array)($meta['subscribes'] ?? []) as $item) {
                $type = strtolower(trim((string)($item['type'] ?? '')));
                if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $type)) {
                    continue;
                }
                $group = trim((string)($item['group'] ?? '')) ?: '插件';
                $label = trim((string)($item['label'] ?? '')) ?: $type;
                self::register($group, $type, $label, 'plugin');
            }
        }
    }

    /** 注册一个可订阅项；同名以先注册的为准 */
    public static function register(string $group, string $type, string $label, string $source = 'plugin'): void
    {
        self::boot();
        $key = self::groupKey($group);
        if (!isset(self::$groups[$key])) {
            self::$groups[$key] = ['title' => $group, 'source' => $source, 'types' => []];
        }
        if (!isset(self::$groups[$key]['types'][$type])) {
            self::$groups[$key]['types'][$type] = $label;
        }
    }

    /** 面板「添加订阅」：写入自定义类型并落盘 */
    public static function add(string $type, string $label, string $group = '自定义'): array
    {
        self::boot();
        $type = strtolower(trim($type));
        $label = trim($label) ?: $type;
        $group = trim($group) ?: '自定义';
        if (!preg_match('/^[a-z][a-z0-9_]{2,63}$/', $type)) {
            return ['ok' => false, 'msg' => '类型名只能是小写字母开头、含小写字母/数字/下划线，2-64 字符'];
        }
        if (isset(self::flat()[$type])) {
            return ['ok' => false, 'msg' => '该订阅类型已存在：' . $type];
        }
        self::$custom[$type] = ['label' => $label, 'group' => $group];
        if (!self::persist()) {
            unset(self::$custom[$type]);
            return ['ok' => false, 'msg' => '写入 runtime/subscription-custom.json 失败，请检查目录权限'];
        }
        self::register($group, $type, $label, 'custom');
        return ['ok' => true, 'msg' => '已添加：' . $type];
    }

    /** 面板「删除订阅」：只能删自定义类型 */
    public static function remove(string $type): array
    {
        self::boot();
        $type = strtolower(trim($type));
        if (!isset(self::$custom[$type])) {
            return ['ok' => false, 'msg' => '只能删除面板里添加的自定义类型：' . $type];
        }
        $backup = self::$custom;
        unset(self::$custom[$type]);
        if (!self::persist()) {
            self::$custom = $backup;
            return ['ok' => false, 'msg' => '写入失败，请检查目录权限'];
        }
        foreach (self::$groups as $key => $group) {
            unset(self::$groups[$key]['types'][$type]);
            if ($group['source'] === 'custom' && $group['types'] === []) {
                unset(self::$groups[$key]);
            }
        }
        return ['ok' => true, 'msg' => '已删除：' . $type];
    }

    /** @return array<int, array{id:string,title:string,source:string,types:array<string,string>}> */
    public static function groups(): array
    {
        self::boot();
        $withCustom = self::$groups;
        if (self::$custom !== []) {
            $byGroup = [];
            foreach (self::$custom as $type => $item) {
                $byGroup[$item['group']][$type] = $item['label'];
            }
            foreach ($byGroup as $title => $types) {
                $key = self::groupKey($title);
                $withCustom[$key] = [
                    'title'  => $title,
                    'source' => 'custom',
                    'types'  => ($withCustom[$key]['types'] ?? []) + $types,
                ];
            }
        }
        $out = [];
        foreach ($withCustom as $id => $group) {
            if ($group['types'] === []) {
                continue;
            }
            $out[] = ['id' => $id] + $group;
        }
        return $out;
    }

    /** @return array<string, string> */
    public static function flat(): array
    {
        $flat = [];
        foreach (self::groups() as $group) {
            foreach ($group['types'] as $type => $label) {
                $flat[$type] = $label;
            }
        }
        return $flat;
    }

    /** @return array<int, string> */
    public static function types(): array
    {
        return array_keys(self::flat());
    }

    public static function has(string $type): bool
    {
        return isset(self::flat()[$type]);
    }

    /** 内置 + 自定义 + 插件分别有多少项，面板上展示来源 */
    public static function stats(): array
    {
        self::boot();
        $stats = ['builtin' => 0, 'plugin' => 0, 'custom' => 0];
        foreach (self::groups() as $group) {
            $stats[$group['source']] = ($stats[$group['source']] ?? 0) + count($group['types']);
        }
        return $stats;
    }

    private static function groupKey(string $title): string
    {
        return 'x_' . substr(md5($title), 0, 8);
    }
}
