<?php
namespace Core;

/**
 * 统一配置内核。
 *
 * 替代历史上并存的 `Utils\Env` 与 `Protocol\Core\Config` 两套解析器：
 * 两者都直接 `parse_ini_file()`，各自缓存一份，且对「带点号的小节名」
 * （`[protocol.onebot]`）的处理方式不同，导致同一个键在不同调用点读到不同值。
 *
 * 本类只做一件事：**把 Config.ini 解析成一棵统一的树**，然后所有读取都走这棵树。
 *
 *   [app]           debug = true          → Core\Config::get('app.debug')
 *   [protocol]      default = http        → Core\Config::get('protocol.default')
 *   [protocol.qqbot] app_id = 123         → Core\Config::get('protocol.qqbot.app_id')
 *                                      也可写 Core\Config::get('qqbot.app_id')
 *
 * 小节名会被拆成路径段，因此 `[protocol.qqbot]` 与「在 [protocol] 里写 qqbot.app_id」
 * 得到完全相同的树。这正是历史上两套解析器互相打架的根因。
 */
final class Config {
    private static ?array $tree = null;
    private static ?string $path = null;
    /** 最近一次解析过程中的告警（例如被忽略的行） */
    private static array $warnings = [];

    /** 指定配置文件路径（测试用；传 null 恢复默认） */
    public static function path(?string $path = null): string {
        if ($path !== null) {
            self::$path = $path;
            self::$tree = null;
        }
        if (self::$path === null) {
            $root = defined('__root__') ? __root__ : dirname(__DIR__);
            self::$path = $root . '/Config.ini';
        }
        return self::$path;
    }

    /** 强制重新解析 */
    public static function refresh(): void {
        self::$tree = null;
        self::$warnings = [];
        self::tree();
    }

    public static function warnings(): array {
        self::tree();
        return self::$warnings;
    }

    /**
     * 读取配置项。
     * @param string $key 点号路径，如 app.debug / protocol.qqbot.api / qqbot.api
     * @param mixed $default 未配置时返回的默认值
     */
    public static function get(string $key, mixed $default = null): mixed {
        $tree = self::tree();
        $segments = array_values(array_filter(explode('.', trim($key)), fn($v) => $v !== ''));
        if ($segments === []) {
            return $default;
        }

        // 1. 完整路径查找
        $value = self::dig($tree, $segments);
        if ($value !== null) {
            return self::normalize($value, $default);
        }

        // 2. 兼容「protocol. 前缀可省略」的历史写法
        if (($segments[0] ?? '') !== 'protocol') {
            $value = self::dig($tree, array_merge(['protocol'], $segments));
            if ($value !== null) {
                return self::normalize($value, $default);
            }
        }

        return $default;
    }

    /** 读取整个小节（不存在返回空数组） */
    public static function section(string $name): array {
        $value = self::dig(self::tree(), array_values(array_filter(explode('.', trim($name)), fn($v) => $v !== '')));
        return is_array($value) ? $value : [];
    }

    public static function bool(string $key, bool $default = false): bool {
        $value = self::get($key, $default);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (int)$value !== 0;
        }
        if (is_string($value)) {
            $v = strtolower(trim($value));
            if (in_array($v, ['1', 'true', 'on', 'yes', 'y'], true)) return true;
            if (in_array($v, ['0', 'false', 'off', 'no', 'n', ''], true)) return false;
        }
        return (bool)$value;
    }

    public static function int(string $key, int $default = 0): int {
        $value = self::get($key, $default);
        return is_numeric($value) ? (int)$value : $default;
    }

    public static function float(string $key, float $default = 0.0): float {
        $value = self::get($key, $default);
        return is_numeric($value) ? (float)$value : $default;
    }

    public static function string(string $key, string $default = ''): string {
        $value = self::get($key, $default);
        if (is_scalar($value)) {
            return (string)$value;
        }
        return $default;
    }

    /** 逗号/空格/换行分隔的字符串 → 数组；已是数组则原样返回 */
    public static function list(string $key, array $default = []): array {
        $value = self::get($key, null);
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_scalar($value)) {
            return $default;
        }
        $parts = preg_split('/[,，\s]+/u', trim((string)$value)) ?: [];
        return array_values(array_filter($parts, fn($v) => $v !== ''));
    }

    public static function array(string $key, array $default = []): array {
        $value = self::get($key, $default);
        return is_array($value) ? $value : $default;
    }

    /** 供 WebUI 读取的整棵树（脱敏后） */
    public static function all(bool $maskSecrets = true): array {
        $tree = self::tree();
        return $maskSecrets ? self::mask($tree) : $tree;
    }

    /** 写入配置项（WebUI 配置页使用），成功返回 true */
    public static function set(string $key, mixed $value): bool {
        $path = self::path();
        if (!is_writable($path)) {
            return false;
        }
        $segments = array_values(array_filter(explode('.', trim($key)), fn($v) => $v !== ''));
        if ($segments === []) {
            return false;
        }
        $inFile = self::fileKey($segments);
        $current = file_get_contents($path);
        if ($current === false) {
            return false;
        }
        $encoded = self::encodeValue($value);
        $pattern = '/^(' . preg_quote($inFile, '/') . '[ \t]*[=:][ \t]*).*$/m';
        $replaced = preg_replace($pattern, '1' . str_replace(['\\', '$'], ['\\\\', '\\$'], $encoded), $current, 1, $count);
        if ($replaced === null) {
            return false;
        }
        if ($count === 0) {
            // 键不存在：追加到对应小节末尾
            $replaced = self::appendKey($current, $segments, $inFile, $encoded);
            if ($replaced === null) {
                return false;
            }
        }
        if (file_put_contents($path, $replaced) === false) {
            return false;
        }
        self::refresh();
        return true;
    }

    // ---------------- 内部实现 ----------------

    private static function tree(): array {
        if (self::$tree !== null) {
            return self::$tree;
        }
        $path = self::path();
        self::$warnings = [];
        if (!is_readable($path)) {
            self::$tree = [];
            self::$warnings[] = "配置文件不可读：{$path}";
            return self::$tree;
        }

        $raw = parse_ini_file($path, true, INI_SCANNER_RAW);
        if ($raw === false) {
            self::$tree = [];
            self::$warnings[] = "配置文件解析失败：{$path}";
            return self::$tree;
        }

        $tree = [];
        foreach ($raw as $section => $items) {
            // 无小节（顶层键）
            if (!is_array($items)) {
                self::assign($tree, explode('.', (string)$section), self::cast($items));
                continue;
            }
            $sectionPath = array_values(array_filter(explode('.', (string)$section), fn($v) => $v !== ''));
            foreach ($items as $key => $value) {
                $keyPath = array_values(array_filter(explode('.', (string)$key), fn($v) => $v !== ''));
                self::assign($tree, array_merge($sectionPath, $keyPath), self::cast($value));
            }
        }

        self::$tree = $tree;
        return self::$tree;
    }

    private static function assign(array &$tree, array $segments, mixed $value): void {
        if ($segments === []) {
            return;
        }
        $node = &$tree;
        foreach ($segments as $i => $segment) {
            if ($i === count($segments) - 1) {
                $node[$segment] = $value;
                return;
            }
            if (!isset($node[$segment]) || !is_array($node[$segment])) {
                $node[$segment] = [];
            }
            $node = &$node[$segment];
        }
    }

    private static function dig(array $tree, array $segments): mixed {
        $node = $tree;
        foreach ($segments as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        return $node;
    }

    /** INI_SCANNER_RAW 不做类型转换，这里统一处理 */
    private static function cast(mixed $value): mixed {
        if (!is_string($value)) {
            return $value;
        }
        $v = trim($value);
        // 显式带引号视为字符串
        $len = strlen($v);
        if ($len >= 2 && (($v[0] === '"' && $v[$len - 1] === '"') || ($v[0] === "'" && $v[$len - 1] === "'"))) {
            return substr($v, 1, -1);
        }
        if ($v === '') {
            return '';
        }
        if (strcasecmp($v, 'true') === 0 || strcasecmp($v, 'on') === 0 || strcasecmp($v, 'yes') === 0) {
            return true;
        }
        if (strcasecmp($v, 'false') === 0 || strcasecmp($v, 'off') === 0 || strcasecmp($v, 'no') === 0) {
            return false;
        }
        if (strcasecmp($v, 'null') === 0 || strcasecmp($v, 'none') === 0) {
            return null;
        }
        // JSON 数组/对象（如 permission.super = [1,2,3]）
        if ($len >= 2 && (($v[0] === '[' && $v[$len - 1] === ']') || ($v[0] === '{' && $v[$len - 1] === '}'))) {
            $decoded = json_decode($v, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }
        return $v;
    }

    private static function normalize(mixed $value, mixed $default): mixed {
        // 空字符串表示「未填写」，返回默认值（保留历史语义）
        if ($value === '') {
            return $default;
        }
        return $value;
    }

    /** 敏感键脱敏 */
    private static function mask(array $tree): array {
        $secretKeys = ['token', 'secret', 'auth', 'password', 'access_token', 'client_secret', 'key'];
        foreach ($tree as $k => $v) {
            if (is_array($v)) {
                $tree[$k] = self::mask($v);
                continue;
            }
            if (is_string($v) && $v !== '' && in_array(strtolower((string)$k), $secretKeys, true)) {
                $tree[$k] = strlen($v) <= 6 ? '***' : substr($v, 0, 3) . str_repeat('*', 6) . substr($v, -2);
            }
        }
        return $tree;
    }

    /**
     * 把逻辑键还原成 Config.ini 里实际书写的键。
     * protocol.qqbot.api → 小节 [protocol.qqbot] 里的 api
     */
    private static function fileKey(array $segments): string {
        $sections = self::sectionNames();
        foreach ($sections as $section) {
            $sectionPath = array_values(array_filter(explode('.', $section), fn($v) => $v !== ''));
            $count = count($sectionPath);
            if ($count === 0 || $count >= count($segments)) {
                continue;
            }
            if (array_slice($segments, 0, $count) === $sectionPath) {
                return implode('.', array_slice($segments, $count));
            }
        }
        // 没有匹配的小节：退化为最后一段
        return (string)end($segments);
    }

    private static function sectionNames(): array {
        $path = self::path();
        if (!is_readable($path)) {
            return [];
        }
        $names = [];
        foreach (explode("\n", (string)file_get_contents($path)) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '[' && str_ends_with($line, ']')) {
                $names[] = trim(substr($line, 1, -1));
            }
        }
        // 长小节优先，避免 [protocol] 抢走 [protocol.qqbot]
        usort($names, fn($a, $b) => strlen($b) - strlen($a));
        return $names;
    }

    private static function appendKey(string $content, array $segments, string $inFile, string $encoded): ?string {
        $section = '';
        $rest = $segments;
        foreach (self::sectionNames() as $candidate) {
            $candidatePath = array_values(array_filter(explode('.', $candidate), fn($v) => $v !== ''));
            $count = count($candidatePath);
            if ($count > 0 && $count < count($segments) && array_slice($segments, 0, $count) === $candidatePath) {
                $section = $candidate;
                $rest = array_slice($segments, $count);
                break;
            }
        }
        $line = $inFile . ' = ' . $encoded;
        if ($section === '') {
            return $content . "\n" . $line . "\n";
        }
        $pattern = '/^\[' . preg_quote($section, '/') . '\][ \t]*$/m';
        if (!preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return $content . "\n[" . $section . "]\n" . $line . "\n";
        }
        $offset = $m[0][1] + strlen($m[0][0]);
        return substr($content, 0, $offset) . "\n" . $line . substr($content, $offset);
    }

    private static function encodeValue(mixed $value): string {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return '';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
        }
        return (string)$value;
    }
}
