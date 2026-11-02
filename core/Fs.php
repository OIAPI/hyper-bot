<?php
namespace Core;

/**
 * 文件读写工具。
 *
 * 为什么需要它：Android 的 /storage/emulated（FUSE）**不支持 flock**，
 * `file_put_contents($f, $data, LOCK_EX)` 会直接返回 false 且不写任何内容。
 * 这类失败是静默的 —— 日志、密码文件、配置写入都会"看起来成功但文件是空的"。
 *
 * 因此统一走这里：先尝试带锁写入，失败则退回无锁写入，
 * 二者都失败才报错。同时提供原子写（临时文件 + rename）。
 */
final class Fs {
    /** 追加写入（带锁不可用时自动降级） */
    public static function append(string $path, string $data): bool {
        self::ensureDir(dirname($path));
        $result = @file_put_contents($path, $data, FILE_APPEND | LOCK_EX);
        if ($result === false) {
            $result = @file_put_contents($path, $data, FILE_APPEND);
        }
        return $result !== false;
    }

    /** 覆盖写入（优先原子写） */
    public static function write(string $path, string $data): bool {
        self::ensureDir(dirname($path));
        $tmp = $path . '.tmp' . bin2hex(random_bytes(3));
        if (@file_put_contents($tmp, $data) !== false) {
            if (@rename($tmp, $path)) {
                return true;
            }
            @unlink($tmp);
        }
        $result = @file_put_contents($path, $data, LOCK_EX);
        if ($result === false) {
            $result = @file_put_contents($path, $data);
        }
        return $result !== false;
    }

    public static function read(string $path): string {
        return is_file($path) ? (string)@file_get_contents($path) : '';
    }

    public static function ensureDir(string $dir): bool {
        if (is_dir($dir)) {
            return true;
        }
        return @mkdir($dir, 0775, true) || is_dir($dir);
    }

    /** 该路径所在卷是否支持 flock */
    public static function supportsLock(string $dir): bool {
        $probe = rtrim($dir, '/') . '/.lock-probe-' . bin2hex(random_bytes(4));
        $ok = @file_put_contents($probe, 'x', LOCK_EX) !== false;
        @unlink($probe);
        return $ok;
    }
}
