<?php
namespace Core\Bot;

use Core\App;
use Core\Store\Store;

/**
 * QQ 成员权限（机器人使用权限，与后台登录账号无关）。
 *
 * 存储键沿用 v1 的 memberPermission.<QQ>，这样插件里 BaseApp::getUserPermission()
 * 与 comparePermission() 不需要改一行就能读到面板里配好的值。
 */
final class MemberPermissions
{
    /** 面板可选的等级（顺序 = 从高到低） */
    public const LEVELS = [
        'super'   => '主人（最高权限）',
        'admin'   => '管理员',
        'editor'  => '编辑',
        'default' => '普通成员',
    ];

    private static function store(): Store
    {
        return App::boot()->store;
    }

    /** @return array<int, array{qq:string, level:string, label:string}> */
    public static function all(): array
    {
        $rows = [];
        foreach (self::store()->keys('memberPermission.') as $key) {
            $qq = substr($key, strlen('memberPermission.'));
            if ($qq === '' || !ctype_digit($qq)) {
                continue;
            }
            $level = strtolower(trim((string)self::store()->get($key)));
            $rows[] = [
                'qq'    => $qq,
                'level' => $level,
                'label' => self::LEVELS[$level] ?? $level,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp($a['qq'], $b['qq']));
        return $rows;
    }

    public static function get(string $qq): ?string
    {
        $value = self::store()->get('memberPermission.' . $qq);
        return $value === null ? null : strtolower(trim((string)$value));
    }

    /** @return array{ok:bool,msg:string} */
    public static function set(string $qq, string $level): array
    {
        $qq = trim($qq);
        $level = strtolower(trim($level));
        if (!ctype_digit($qq) || strlen($qq) < 5 || strlen($qq) > 12) {
            return ['ok' => false, 'msg' => 'QQ 号必须是 5-12 位数字'];
        }
        if (!isset(self::LEVELS[$level])) {
            return ['ok' => false, 'msg' => '权限等级只能是 ' . implode(' / ', array_keys(self::LEVELS))];
        }
        self::store()->set('memberPermission.' . $qq, $level);
        return ['ok' => true, 'msg' => '已设置为 ' . self::LEVELS[$level]];
    }

    public static function remove(string $qq): bool
    {
        return self::store()->del('memberPermission.' . $qq) > 0;
    }

    /** 面板顶部的统计 */
    public static function stats(): array
    {
        $rows = self::all();
        $byLevel = [];
        foreach ($rows as $row) {
            $byLevel[$row['level']] = ($byLevel[$row['level']] ?? 0) + 1;
        }
        return ['total' => count($rows), 'by_level' => $byLevel];
    }
}
