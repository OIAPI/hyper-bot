<?php
namespace Webui;

use Core\Config;

/**
 * WebUI 账号体系。
 *
 * 刻意不引入数据库：账号存成一个 JSON 文件（`runtime/webui/users.json`），
 * 密码用 `password_hash`（bcrypt）保存。这样在「没有 Redis、没有 MySQL」的
 * 单机部署里也能直接跑起来，同时不牺牲密码存储的基本安全性。
 *
 * 首次启动时自动创建 admin 账号：
 *  - 若 Config.ini 里配置了 `webui.password`，直接用它；
 *  - 否则生成随机密码，写入 `runtime/webui/initial-password.txt`（0600），
 *    并在启动日志里提示，避免出现「默认口令 admin/admin」这种常见漏洞。
 */
final class Auth {
    private static ?array $users = null;
    private static ?string $lastGeneratedPassword = null;
    private static bool $lastPasswordSaved = false;

    public static function dir(): string {
        $root = defined('__runtime__') ? __runtime__ : dirname(__DIR__) . '/runtime';
        $dir = $root . '/webui';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    public static function usersFile(): string {
        return self::dir() . '/users.json';
    }

    /** @return array<string, array{password:string, role:string, created:int}> */
    public static function users(): array {
        if (self::$users !== null) {
            return self::$users;
        }
        $file = self::usersFile();
        if (is_file($file)) {
            $decoded = json_decode((string)@file_get_contents($file), true);
            if (is_array($decoded)) {
                return self::$users = $decoded;
            }
        }
        return self::$users = [];
    }

    /** 首次启动初始化；返回新建的账号信息或 null */
    public static function bootstrap(): ?array {
        if (self::users() !== []) {
            return null;
        }

        $password = Config::string('webui.password', '');
        $generated = false;
        if ($password === '') {
            $password = self::randomPassword();
            $generated = true;
            $file = self::dir() . '/initial-password.txt';
            $saved = \Core\Fs::write($file, $password . "\n");
            if ($saved) {
                @chmod($file, 0600);
            }
            // 文件写不进去时不能把密码吞掉，否则用户永远登不进来
            self::$lastGeneratedPassword = $password;
            self::$lastPasswordSaved = $saved;
        }

        $user = [
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role'     => 'owner',
            'created'  => time(),
        ];
        self::$users = ['admin' => $user];
        self::save();

        return [
            'username'    => 'admin',
            'generated'   => $generated,
            'password'    => $generated ? self::$lastGeneratedPassword : null,
            'password_saved' => self::$lastPasswordSaved,
            'password_file'  => $generated && self::$lastPasswordSaved ? self::dir() . '/initial-password.txt' : null,
        ];
    }

    public static function save(): bool {
        $file = self::usersFile();
        $ok = \Core\Fs::write($file, (string)json_encode(self::$users, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if ($ok) {
            @chmod($file, 0600);
        }
        return $ok;
    }

    public static function verify(string $username, string $password): ?array {
        $users = self::users();
        $record = $users[$username] ?? null;
        if (!is_array($record)) {
            // 恒定时间：即使账号不存在也跑一次 hash，避免通过响应时间枚举账号
            password_verify($password, '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin');
            return null;
        }
        if (!password_verify($password, (string)($record['password'] ?? ''))) {
            return null;
        }
        return ['username' => $username, 'role' => (string)($record['role'] ?? 'admin')];
    }

    public static function changePassword(string $username, string $newPassword): bool {
        $users = self::users();
        if (!isset($users[$username])) {
            return false;
        }
        $users[$username]['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
        self::$users = $users;
        return self::save();
    }

    /**
     * 命令行/后台设置密码（可直接建号，用于忘记密码的兜底）。
     *
     * 为什么要有它：面板密码是 bcrypt 存在 runtime/webui/users.json 里的，
     * 一旦这台机器上的文件被清掉或换了 runtime 目录，默认账号就变成随机密码，
     * 用户只能靠这个命令救回来，不必手动删文件。
     *
     * @return string|null 失败原因；成功返回 null
     */
    public static function setPassword(string $username, string $password, string $role = ''): ?string {
        $username = trim($username);
        if ($username === '') {
            return '用户名不能为空';
        }
        if (strlen($password) < 8) {
            return '密码至少 8 位';
        }
        if (!preg_match('/^[A-Za-z0-9_.-]{2,32}$/', $username)) {
            return '用户名只能包含字母、数字、下划线、点和短横线（2-32 位）';
        }
        $users = self::users();
        $exists = isset($users[$username]);
        if ($role === '') {
            $role = (string)($users[$username]['role'] ?? ($users === [] ? 'owner' : 'admin'));
        }
        $record = [
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role'     => self::normalizeRole($role),
            'created'  => (int)($users[$username]['created'] ?? time()),
        ];
        // 保留第三方绑定，免得改密码把人家的 GitHub 登录弄丢
        if ($exists && is_array($users[$username]['bindings'] ?? null)) {
            $record['bindings'] = $users[$username]['bindings'];
        }
        $users[$username] = $record;
        self::$users = $users;
        return self::save() ? null : '保存失败（runtime/webui 不可写）';
    }

    // ------------------------------------------------------- 第三方登录绑定

    /**
     * 某个账号已绑定的第三方身份。
     * @return array<string, array{sub:string,label:string,bound_at:int}>
     */
    public static function bindings(string $username): array {
        $record = self::users()[$username] ?? null;
        if (!is_array($record) || !is_array($record['bindings'] ?? null)) {
            return [];
        }
        $out = [];
        foreach ($record['bindings'] as $provider => $row) {
            if (!is_array($row) || (string)($row['sub'] ?? '') === '') {
                continue;
            }
            $out[(string)$provider] = [
                'sub'      => (string)$row['sub'],
                'label'    => (string)($row['label'] ?? ''),
                'bound_at' => (int)($row['bound_at'] ?? 0),
            ];
        }
        return $out;
    }

    /** 反查：某个第三方身份绑到了哪个账号 */
    public static function userByOauth(string $provider, string $sub): ?string {
        if ($sub === '') {
            return null;
        }
        foreach (self::users() as $username => $record) {
            $bindings = is_array($record['bindings'] ?? null) ? $record['bindings'] : [];
            if (isset($bindings[$provider]['sub']) && (string)$bindings[$provider]['sub'] === $sub) {
                return (string)$username;
            }
        }
        return null;
    }

    /**
     * 绑定第三方身份。
     * @return string|null 失败原因；成功返回 null
     */
    public static function bindOauth(string $username, string $provider, string $sub, string $label = ''): ?string {
        $users = self::users();
        if (!isset($users[$username])) {
            return '账号不存在';
        }
        if ($sub === '') {
            return '第三方返回的身份为空';
        }
        $owner = self::userByOauth($provider, $sub);
        if ($owner !== null && $owner !== $username) {
            return '该 ' . $provider . ' 身份已绑定到账号 ' . $owner;
        }
        $bindings = is_array($users[$username]['bindings'] ?? null) ? $users[$username]['bindings'] : [];
        $bindings[$provider] = [
            'sub'      => $sub,
            'label'    => $label,
            'bound_at' => time(),
        ];
        $users[$username]['bindings'] = $bindings;
        self::$users = $users;
        return self::save() ? null : '保存失败（runtime/webui 不可写）';
    }

    /** 解绑；返回是否真的删掉了一条绑定 */
    public static function unbindOauth(string $username, string $provider): bool {
        $users = self::users();
        if (!isset($users[$username]['bindings'][$provider])) {
            return false;
        }
        unset($users[$username]['bindings'][$provider]);
        self::$users = $users;
        return self::save();
    }

    // ------------------------------------------------------------------ RBAC

    /** 角色从高到低：主人 > 管理员 > 只读 */
    public const ROLES = ['owner', 'admin', 'viewer'];

    /**
     * 能力表（RBAC-lite）。
     *
     * 为什么不做完整 RBAC（角色-权限-资源三张表）：这个面板只有三种人、
     * 十来个能力点，做成可配置表只会让「谁能改什么」变得不可读。
     * 能力 -> 允许角色写死在这里，新增能力只改这一处。
     */
    public const CAPABILITIES = [
        'view'           => ['owner', 'admin', 'viewer'],
        'plugin.install' => ['owner', 'admin'],
        'plugin.reload'  => ['owner', 'admin'],
        'settings.write' => ['owner', 'admin'],
        'protocol.write' => ['owner', 'admin'],
        'group.write'    => ['owner', 'admin'],
        'system.cache'   => ['owner', 'admin'],
        'runtime.write'  => ['owner'],
        'user.manage'    => ['owner'],
    ];

    public static function normalizeRole(string $role): string {
        $role = strtolower(trim($role));
        // 历史数据里只有 admin；未知值一律降级为只读，避免越权
        return in_array($role, self::ROLES, true) ? $role : 'viewer';
    }

    /** @return list<string> */
    public static function permissions(string $role): array {
        $role = self::normalizeRole($role);
        $out = [];
        foreach (self::CAPABILITIES as $cap => $roles) {
            if (in_array($role, $roles, true)) {
                $out[] = $cap;
            }
        }
        return $out;
    }

    public static function can(string $role, string $capability): bool {
        $roles = self::CAPABILITIES[$capability] ?? [];
        return in_array(self::normalizeRole($role), $roles, true);
    }

    /**
     * 保证系统里始终有一个 owner。
     *
     * 老版本把首个账号存成 admin，升级后若没人能做「用户管理」，
     * 面板会永久锁死在半权限状态——所以这里做一次就地提升。
     */
    public static function ensureOwner(): void {
        $users = self::users();
        if ($users === []) {
            return;
        }
        foreach ($users as $record) {
            if (self::normalizeRole((string)($record['role'] ?? '')) === 'owner') {
                return;
            }
        }
        $first = null;
        $firstAt = PHP_INT_MAX;
        foreach ($users as $name => $record) {
            $created = (int)($record['created'] ?? 0);
            if ($created < $firstAt) {
                $firstAt = $created;
                $first = (string)$name;
            }
        }
        if ($first === null) {
            return;
        }
        $users[$first]['role'] = 'owner';
        self::$users = $users;
        self::save();
    }

    /** @return list<array{username:string,role:string,created:int}> */
    public static function listUsers(): array {
        $out = [];
        foreach (self::users() as $name => $record) {
            $out[] = [
                'username' => (string)$name,
                'role'     => self::normalizeRole((string)($record['role'] ?? 'viewer')),
                'created'  => (int)($record['created'] ?? 0),
            ];
        }
        usort($out, static fn(array $a, array $b): int => $a['created'] <=> $b['created']);
        return $out;
    }

    /** 创建账号；成功返回 null，失败返回错误文案 */
    public static function createUser(string $username, string $password, string $role): ?string {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9_.-]{3,32}$/', $username)) {
            return '用户名只能用字母、数字、下划线、点和横线，长度 3-32';
        }
        if (strlen($password) < 8) {
            return '密码至少 8 位';
        }
        $users = self::users();
        if (isset($users[$username])) {
            return '用户名已存在';
        }
        $users[$username] = [
            'password' => password_hash($password, PASSWORD_BCRYPT),
            'role'     => self::normalizeRole($role),
            'created'  => time(),
        ];
        self::$users = $users;
        return self::save() ? null : '账号保存失败（runtime/webui 不可写）';
    }

    public static function setRole(string $username, string $role): ?string {
        $users = self::users();
        if (!isset($users[$username])) {
            return '账号不存在';
        }
        $users[$username]['role'] = self::normalizeRole($role);
        self::$users = $users;
        return self::save() ? null : '账号保存失败';
    }

    public static function deleteUser(string $username): ?string {
        $users = self::users();
        if (!isset($users[$username])) {
            return '账号不存在';
        }
        $owners = array_filter($users, static fn(array $u): bool => self::normalizeRole((string)($u['role'] ?? '')) === 'owner');
        if (count($owners) <= 1 && isset($owners[$username])) {
            return '不能删除唯一的主人账号';
        }
        unset($users[$username]);
        self::$users = $users;
        return self::save() ? null : '账号保存失败';
    }

    public static function isOnlyOwner(string $username): bool {
        $users = self::users();
        $owners = array_filter($users, static fn(array $u): bool => self::normalizeRole((string)($u['role'] ?? '')) === 'owner');
        return count($owners) === 1 && isset($owners[$username]);
    }

    /** 测试注入：直接设置账号表（不落盘） */
    public static function setUsersForTest(array $users): void {
        self::$users = $users;
    }

    /** 是否仍处于「未初始化」状态 */
    public static function needsSetup(): bool {
        return self::users() === [];
    }

    public static function randomPassword(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}