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
            'role'     => 'admin',
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

    /** 测试注入：直接设置账号表（不落盘） */
    public static function setUsersForTest(array $users): void {
        self::$users = $users;
    }

    /** 是否仍处于「未初始化」状态 */
    public static function needsSetup(): bool {
        return self::users() === [];
    }

    private static function randomPassword(): string {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $out;
    }
}