<?php
namespace Core\Bot;

use Core\Config;
use Core\Fs;

/**
 * 机器人账号（多账号配置）。
 *
 * 一个账号 = 一条「协议标准 + 协议端地址 + 自身账号」的组合。同一个协议可以有多个账号
 * （例如两个 OneBot 连接的 QQ 号），不同协议当然也可以同时存在。
 *
 * 落盘 runtime/accounts.json；Config.ini 只作为首次启动时的种子，之后以本文件为准。
 * 账号 id 稳定不变，事件与出站 API 都靠它定位到具体账号。
 */
final class Accounts
{
    /** 支持的协议标准 */
    public const PROTOCOLS = [
        'onebot' => 'OneBot 11',
        'milky'  => 'Milky',
        'qqbot'  => 'QQ 官方机器人',
    ];

    /** QQ 官方机器人固定域名：生产 / 沙箱（不允许自定义） */
    public const QQ_PROD_API = 'https://api.bot.qq.com';
    public const QQ_SANDBOX_API = 'https://sandbox.api.sgroup.qq.com';

    private static string $path = '';
    /** @var array<int, array<string, mixed>>|null */
    private static ?array $cache = null;

    public static function path(?string $path = null): string
    {
        if ($path !== null) {
            self::$path = $path;
            self::$cache = null;
            return $path;
        }
        if (self::$path === '') {
            self::$path = (defined('__root__') ? __root__ : getcwd()) . '/runtime/accounts.json';
        }
        return self::$path;
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $rows = self::load();
        if ($rows === []) {
            $rows = self::seed();
            if ($rows !== []) {
                self::persist($rows);
            }
        }
        self::$cache = $rows;
        return $rows;
    }

    public static function reload(): void
    {
        self::$cache = null;
    }

    /** @return array<int, array<string, mixed>> */
    public static function enabled(): array
    {
        return array_values(array_filter(self::all(), static fn(array $a): bool => (bool)($a['enable'] ?? true)));
    }

    /** @return array<string, mixed>|null */
    public static function get(string $id): ?array
    {
        foreach (self::all() as $row) {
            if ((string)($row['id'] ?? '') === $id) {
                return $row;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    /**
     * 取某协议用于「主动调用」的账号：
     *  - 给了 $id 就按 id 取（协议必须匹配）
     *  - 没给 id 且该协议恰好只有一个启用账号时返回它，多个账号返回 null（调用方需显式指定）
     */
    public static function forProtocol(string $protocol, string $id = ''): ?array
    {
        $protocol = strtolower($protocol);
        if ($id !== '') {
            $account = self::get($id);
            return $account !== null && strtolower((string)($account['protocol'] ?? '')) === $protocol ? $account : null;
        }
        $list = array_values(array_filter(
            self::enabled(),
            static fn(array $row): bool => strtolower((string)($row['protocol'] ?? '')) === $protocol
        ));
        return count($list) === 1 ? $list[0] : null;
    }

    /** 某协议下启用的账号数量（用于「多账号必须指定」的判断） */
    public static function countForProtocol(string $protocol): int
    {
        return count(self::idsForProtocol($protocol));
    }

    /** 某协议下第一个启用的账号（网关/WebHook 没有上下文时用它取凭据） */
    public static function firstEnabled(string $protocol): ?array
    {
        $protocol = strtolower($protocol);
        foreach (self::enabled() as $row) {
            if (strtolower((string)($row['protocol'] ?? '')) === $protocol) {
                return $row;
            }
        }
        return null;
    }

    /** 某协议下启用的账号 id 列表 */
    public static function idsForProtocol(string $protocol): array
    {
        $protocol = strtolower($protocol);
        $ids = [];
        foreach (self::enabled() as $row) {
            if (strtolower((string)($row['protocol'] ?? '')) === $protocol) {
                $ids[] = (string)$row['id'];
            }
        }
        return $ids;
    }

    public static function bySelfId(string $selfId): ?array
    {
        $selfId = trim($selfId);
        if ($selfId === '') {
            return null;
        }
        foreach (self::all() as $row) {
            if ((string)($row['self_id'] ?? '') === $selfId) {
                return $row;
            }
        }
        return null;
    }

    /**
     * 新建或更新一个账号。id 缺省时按 协议-selfid 生成。
     * @param array<string, mixed> $data
     * @return array{ok:bool,msg:string,data:array<string,mixed>|null}
     */
    public static function upsert(array $data): array
    {
        $protocol = strtolower(trim((string)($data['protocol'] ?? '')));
        if (!isset(self::PROTOCOLS[$protocol])) {
            return self::fail('不支持的协议：' . $protocol . '（可选 onebot / milky / qqbot）');
        }

        $isQqbot = $protocol === 'qqbot';
        $selfId = trim((string)($data['self_id'] ?? ''));
        $appId = $isQqbot ? trim((string)($data['app_id'] ?? '')) : '';
        $clientSecret = $isQqbot ? trim((string)($data['client_secret'] ?? '')) : '';
        $sandbox = $isQqbot && (bool)($data['sandbox'] ?? false);
        // 官方机器人没有 QQ 号：没填 self_id 时用 AppID 做账号标识（事件/回包都按它路由）
        if ($isQqbot && $selfId === '' && $appId !== '') {
            $selfId = $appId;
        }
        if ($selfId !== '' && preg_match('/^[A-Za-z0-9_\-:.]{1,64}$/', $selfId) !== 1) {
            return self::fail('账号（self_id）只能包含字母、数字、下划线、短横线、冒号或点，最长 64 字符');
        }
        if ($isQqbot && $appId !== '' && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $appId) !== 1) {
            return self::fail('AppID 只能包含字母、数字、下划线、短横线，最长 64 字符');
        }

        // QQ 官方机器人的 API 固定为生产/沙箱域名，不接受自定义地址
        $api = $isQqbot ? '' : trim((string)($data['api'] ?? ''));
        if (!$isQqbot && $api !== '' && preg_match('#^https?://#i', $api) !== 1) {
            return self::fail('API 地址必须以 http:// 或 https:// 开头');
        }

        $id = trim((string)($data['id'] ?? ''));
        if ($id === '') {
            $id = $protocol . '-' . ($selfId !== '' ? $selfId : substr(sha1((string)microtime(true)), 0, 8));
        }
        if (preg_match('/^[a-z0-9][a-z0-9_\-]{0,63}$/', $id) !== 1) {
            return self::fail('账号 id 只能是小写字母、数字、短横线、下划线，最长 64 字符');
        }

        $rows = self::all();
        $existing = null;
        foreach ($rows as $i => $row) {
            if ((string)$row['id'] === $id) {
                $existing = $i;
                break;
            }
        }
        if ($existing === null && $selfId !== '') {
            foreach ($rows as $row) {
                if (strtolower((string)$row['protocol']) === $protocol && (string)($row['self_id'] ?? '') === $selfId) {
                    return self::fail('同协议下已存在该账号：' . $selfId);
                }
            }
        }

        // 更新时，未提交的字段沿用原值：面板只改一个开关不会把 token / api 清空
        if ($existing !== null) {
            $prev = $rows[$existing];
            foreach (['name', 'self_id', 'enable', 'api', 'token', 'forward_url', 'forward_token', 'remark', 'app_id', 'client_secret', 'sandbox'] as $key) {
                if (!array_key_exists($key, $data)) {
                    $data[$key] = $prev[$key] ?? '';
                }
            }
            $selfId = trim((string)($data['self_id'] ?? ''));
            $api = $isQqbot ? '' : trim((string)($data['api'] ?? ''));
            if ($isQqbot) {
                $appId = trim((string)($data['app_id'] ?? ''));
                $clientSecret = trim((string)($data['client_secret'] ?? ''));
                $sandbox = (bool)($data['sandbox'] ?? false);
            }
        }
        if ($isQqbot) {
            if ($appId === '') {
                return self::fail('官方机器人需要 AppID');
            }
            // 新建必须填 AppSecret；老数据（迁移前只有 Config.ini）允许先空着，
            // 协议层会继续回落到 Config.ini 的 qqbot.client_secret
            if ($clientSecret === '' && $existing === null) {
                return self::fail('官方机器人需要 AppSecret');
            }
            $api = $sandbox ? self::QQ_SANDBOX_API : self::QQ_PROD_API;
        }

        $name = trim((string)($data['name'] ?? ''));
        $entry = [
            'id'            => $id,
            'name'          => $name !== '' ? $name : ($protocol . ' ' . ($selfId !== '' ? $selfId : $id)),
            'protocol'      => $protocol,
            'self_id'       => $selfId,
            'enable'        => (bool)($data['enable'] ?? true),
            'api'           => $api,
            'token'         => (string)($data['token'] ?? ''),
            'forward_url'   => trim((string)($data['forward_url'] ?? '')),
            'forward_token' => (string)($data['forward_token'] ?? ''),
            'app_id'        => $appId,
            'client_secret' => $clientSecret,
            'sandbox'       => $sandbox,
            'remark'        => trim((string)($data['remark'] ?? '')),
            'updated_at'    => date('c'),
        ];
        if ($existing !== null) {
            $entry['created_at'] = (string)($rows[$existing]['created_at'] ?? date('c'));
            $rows[$existing] = $entry;
        } else {
            $entry['created_at'] = date('c');
            $rows[] = $entry;
        }

        if (!self::persist($rows)) {
            return self::fail('写入失败：runtime 目录不可写');
        }
        self::$cache = $rows;
        return ['ok' => true, 'msg' => $existing !== null ? '已更新账号 ' . $id : '已添加账号 ' . $id, 'data' => $entry];
    }

    /** @return array{ok:bool,msg:string} */
    public static function remove(string $id): array
    {
        $rows = self::all();
        $kept = [];
        $found = false;
        foreach ($rows as $row) {
            if ((string)$row['id'] === $id) {
                $found = true;
                continue;
            }
            $kept[] = $row;
        }
        if (!$found) {
            return ['ok' => false, 'msg' => '账号不存在：' . $id];
        }
        if ($kept === []) {
            return ['ok' => false, 'msg' => '至少要保留一个账号'];
        }
        if (!self::persist($kept)) {
            return ['ok' => false, 'msg' => '写入失败：runtime 目录不可写'];
        }
        self::$cache = $kept;
        return ['ok' => true, 'msg' => '已删除账号 ' . $id];
    }

    /**
     * 给面板用的公开结构：抹掉 token，只保留是否已配置。
     * @return array<int, array<string, mixed>>
     */
    public static function publicList(): array
    {
        $out = [];
        foreach (self::all() as $row) {
            $out[] = self::publicRow($row);
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public static function publicRow(array $row): array
    {
        $row['token_set'] = (string)($row['token'] ?? '') !== '';
        $row['forward_token_set'] = (string)($row['forward_token'] ?? '') !== '';
        $row['client_secret_set'] = (string)($row['client_secret'] ?? '') !== '';
        unset($row['token'], $row['forward_token'], $row['client_secret']);
        $row['protocol_label'] = self::PROTOCOLS[$row['protocol'] ?? ''] ?? (string)($row['protocol'] ?? '');
        return $row;
    }

    /** 首次启动：把 Config.ini 里旧的三协议配置迁移成账号 */
    /** @return array<int, array<string, mixed>> */
    private static function seed(): array
    {
        $rows = [];
        $now = date('c');

        $onebotEnable = Config::bool('protocol.onebot.enable', false);
        if ($onebotEnable) {
            $self = trim(Config::string('protocol.onebot.self_id', ''));
            $rows[] = [
                'id'            => 'onebot-' . ($self !== '' ? $self : 'default'),
                'name'          => 'OneBot ' . ($self !== '' ? $self : '默认'),
                'protocol'      => 'onebot',
                'self_id'       => $self,
                'enable'        => true,
                'api'           => Config::string('protocol.onebot.api', ''),
                'token'         => Config::string('protocol.onebot.token', ''),
                'forward_url'   => Config::string('protocol.onebot.forward.url', ''),
                'forward_token' => Config::string('protocol.onebot.forward.token', ''),
                'remark'        => '由 Config.ini 迁移',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (Config::bool('protocol.milky.enable', false)) {
            $self = trim(Config::string('protocol.milky.self_id', ''));
            $rows[] = [
                'id'            => 'milky-' . ($self !== '' ? $self : 'default'),
                'name'          => 'Milky ' . ($self !== '' ? $self : '默认'),
                'protocol'      => 'milky',
                'self_id'       => $self,
                'enable'        => true,
                'api'           => Config::string('protocol.milky.api', ''),
                'token'         => Config::string('protocol.milky.token', ''),
                'forward_url'   => '',
                'forward_token' => '',
                'remark'        => '由 Config.ini 迁移',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        if (Config::bool('protocol.qqbot.enable', false)) {
            $self = trim(Config::string('protocol.qqbot.self_id', ''));
            $rows[] = [
                'id'            => 'qqbot-' . ($self !== '' ? $self : 'default'),
                'name'          => 'QQ 官方 ' . ($self !== '' ? $self : '默认'),
                'protocol'      => 'qqbot',
                'self_id'       => $self !== '' ? $self : trim(Config::string('protocol.qqbot.app_id', '')),
                'enable'        => true,
                'api'           => self::QQ_PROD_API,
                'token'         => '',
                'forward_url'   => '',
                'forward_token' => '',
                'app_id'        => trim(Config::string('protocol.qqbot.app_id', '')),
                'client_secret' => Config::string('protocol.qqbot.client_secret', ''),
                'sandbox'       => false,
                'remark'        => '由 Config.ini 迁移',
                'created_at'    => $now,
                'updated_at'    => $now,
            ];
        }

        return $rows;
    }

    /** @return array<int, array<string, mixed>> */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        try {
            $data = json_decode(Fs::read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        $list = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];
        $rows = [];
        foreach ($list as $row) {
            if (!is_array($row) || !isset($row['id'], $row['protocol'])) {
                continue;
            }
            $normalized = [
                'id'            => (string)$row['id'],
                'name'          => (string)($row['name'] ?? $row['id']),
                'protocol'      => strtolower((string)$row['protocol']),
                'self_id'       => (string)($row['self_id'] ?? ''),
                'enable'        => (bool)($row['enable'] ?? true),
                'api'           => (string)($row['api'] ?? ''),
                'token'         => (string)($row['token'] ?? ''),
                'forward_url'   => (string)($row['forward_url'] ?? ''),
                'forward_token' => (string)($row['forward_token'] ?? ''),
                'app_id'        => (string)($row['app_id'] ?? ''),
                'client_secret' => (string)($row['client_secret'] ?? ''),
                'sandbox'       => (bool)($row['sandbox'] ?? str_contains(strtolower((string)($row['api'] ?? '')), 'sandbox')),
                'remark'        => (string)($row['remark'] ?? ''),
                'created_at'    => (string)($row['created_at'] ?? ''),
                'updated_at'    => (string)($row['updated_at'] ?? ''),
            ];
            // 老版本只有 Config.ini 的 qqbot 配置：读出来补到账号上，
            // 面板能看到 AppID，协议层也能按账号走（保存后落盘）
            if ($normalized['protocol'] === 'qqbot') {
                if ($normalized['app_id'] === '') {
                    $normalized['app_id'] = trim(Config::string('protocol.qqbot.app_id', ''));
                }
                if ($normalized['self_id'] === '') {
                    $normalized['self_id'] = $normalized['app_id'];
                }
                if ($normalized['client_secret'] === '') {
                    $normalized['client_secret'] = Config::string('protocol.qqbot.client_secret', '');
                }
                // API 域名固定：老数据里自定义的地址一律归一化（沙箱只认 sandbox 标记）
                $normalized['api'] = $normalized['sandbox'] ? self::QQ_SANDBOX_API : self::QQ_PROD_API;
            }
            $rows[] = $normalized;
        }
        return $rows;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private static function persist(array $rows): bool
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir) && !Fs::ensureDir($dir)) {
            return false;
        }
        $json = json_encode(['accounts' => array_values($rows)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) {
            return false;
        }
        return Fs::write($path, $json);
    }

    /** @return array{ok:false,msg:string,data:null} */
    private static function fail(string $msg): array
    {
        return ['ok' => false, 'msg' => $msg, 'data' => null];
    }
}