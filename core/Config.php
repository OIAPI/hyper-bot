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
    /** 最近一次写回前的备份文件路径（失败为 null） */
    private static ?string $lastBackup = null;

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
     * 最近一次 {@see set()} 写回前生成的备份文件绝对路径。
     * 备份失败（或尚未写过）时为 null；WebUI 可据此提示用户如何回滚。
     */
    public static function lastBackup(): ?string {
        return self::$lastBackup;
    }

    /**
     * 追加一条告警（去重）。
     *
     * 为什么需要：历史上 warnings() 只在「整份文件解析失败」时才有内容，
     * 而真正坑人的是**局部静默损坏**——某个值被截断、某行被当成键名，
     * parse_ini_file 不报错，返回值看着也正常。这类问题必须让用户看见。
     */
    private static function warn(string $message): void {
        if (!in_array($message, self::$warnings, true)) {
            self::$warnings[] = $message;
        }
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

    /**
     * 写入配置项（WebUI 配置页使用），成功返回 true。
     *
     * 这里必须**按小节定位**后替换，不能只用键名做全文正则：
     * 1) 同名键会散落在多个小节（例如 `debug` 可能同时出现在 [app] 与 [http]），
     *    只按键名替换会改错小节；
     * 2) 替换串里不能用字面量数字做前缀（那会把 `debug = true` 变成 `1false`），
     *    必须用反向引用 \$1 保留「键 = 」前缀。
     */
    public static function set(string $key, mixed $value): bool {
        $path = self::path();
        if (!is_writable($path)) {
            return false;
        }
        $segments = array_values(array_filter(explode('.', trim($key)), fn($v) => $v !== ''));
        if ($segments === []) {
            return false;
        }
        $current = Fs::read($path);
        if ($current === '') {
            return false;
        }

        if (self::isInexpressible($value)) {
            self::warn("拒绝写入 {$key}：值里含有换行，INI 格式无法表达。"
                . "请改用不含换行的值，或写到独立的配置文件里。");
            return false;
        }

        $encoded = self::encodeValue($value);
        [$section, $rest] = self::splitSection($segments);
        $inFile = implode('.', $rest);
        if ($inFile === '') {
            return false;
        }

        // 必须带 /u，理由同 preprocess()：不带会把 UTF-8 中文按字节切断
        $lines = preg_split('/\R/u', $current);
        if ($lines === false) {
            return false;
        }

        $currentSection = '';
        $replacedLine = null;
        $sectionStart = null;
        $sectionEnd = count($lines);

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed !== '' && $trimmed[0] === '[' && str_ends_with($trimmed, ']')) {
                // 离开上一个小节前，记录它的结束位置
                if ($currentSection === $section && $sectionStart !== null && $sectionEnd === count($lines)) {
                    $sectionEnd = $index;
                }
                $currentSection = trim(substr($trimmed, 1, -1));
                if ($currentSection === $section && $sectionStart === null) {
                    $sectionStart = $index;
                }
                continue;
            }
            // 只在目标小节内匹配，避免改到别处的同名键
            if ($sectionStart === null || $currentSection !== $section) {
                continue;
            }
            // 被注释掉的键不算命中
            if ($trimmed === '' || $trimmed[0] === ';' || $trimmed[0] === '#') {
                continue;
            }
            if (preg_match('/^\s*' . preg_quote($inFile, '/') . '\s*[=:]\s*/', $line)) {
                $replacedLine = $encoded === '' ? $inFile . ' =' : $inFile . ' = ' . $encoded;
                $lines[$index] = $replacedLine;
                break;
            }
        }

        if ($replacedLine === null) {
            // 键不存在：追加到该小节末尾（小节不存在则在文件末尾新建）
            $lines = self::appendLine($lines, $section, $inFile . ' = ' . $encoded, $sectionStart, $sectionEnd);
        }

        $output = implode("\n", $lines);

        // 写前备份：WebUI 点一下存盘就可能改坏整个文件，没有备份就回不去。
        // 备份失败不阻断写入（只读卷等场景），但会记录下来供界面提示。
        self::$lastBackup = self::backup($path);

        if (!Fs::write($path, $output)) {
            return false;
        }
        self::refresh();

        // 回读校验：确认「写进去的」和「读回来的」一致。
        // 这正是值含分号那类 bug 的防线——encode 与 decode 两侧不对齐时，
        // 写进去 a;b 读回来 a，双方都不报错，只能靠回读发现。
        $readBack = self::get($key, self::UNSET);
        if ($readBack !== self::UNSET && !self::valueEquivalent($readBack, $value)) {
            self::warn("写入 {$key} 后回读不一致：写入 " . json_encode($value, 256)
                . '，读回 ' . json_encode($readBack, 256)
                . '。通常是值里含有 INI 特殊字符（分号 / 换行 / 首尾空白）。');
        }

        return true;
    }

    /** 表示「键不存在」的哨兵，替代 null（null 本身是合法值） */
    private const UNSET = "\0__config_unset__\0";

    /**
     * 写回前的备份。成功返回备份文件绝对路径，失败返回 null。
     * 文件名带时间戳，只保留最近 5 份，避免长期运行把磁盘写满。
     */
    private static function backup(string $path): ?string {
        $content = Fs::read($path);
        if ($content === '') {
            return null;
        }
        // 同一秒内只留一份：WebUI 保存订阅配置会连续 set 七八次，
        // 每次都写备份会让文件名撞车、且把好备份挤掉。
        $stamp = date('Ymd-His') . '-' . substr((string)microtime(true), -4);
        $file = $path . '.' . $stamp . '.bak';
        if (is_file($file) && file_get_contents($file) === $content) {
            return $file;
        }
        if (!Fs::write($file, $content)) {
            return null;
        }
        self::pruneBackups($path, 5);
        return $file;
    }

    /** 只保留最近 $keep 份备份 */
    private static function pruneBackups(string $path, int $keep): void {
        $files = glob($path . '.*.bak') ?: [];
        if (count($files) <= $keep) {
            return;
        }
        sort($files);
        foreach (array_slice($files, 0, count($files) - $keep) as $old) {
            @unlink($old);
        }
    }

    /**
     * 值比较：写入的原始值可能与读回的规范化值形态不同
     * （写 true 读回 true、写 'true' 读回 true、写 [1,2] 读回 [1,2]）。
     */
    private static function valueEquivalent(mixed $readBack, mixed $written): bool {
        if (is_array($written)) {
            return json_encode($readBack, 256) === json_encode($written, 256);
        }
        if (is_bool($written)) {
            $b = $readBack;
            if (is_string($b)) {
                $b = in_array(strtolower(trim($b)), ['1', 'true', 'on', 'yes', 'y'], true);
            }
            return (bool)$b === $written;
        }
        if ($written === null) {
            return $readBack === null || $readBack === '';
        }
        return (string)$readBack === (string)$written;
    }

    /**
     * 把 `app.debug` 拆成 `[小节, 小节内的键]`。
     * 有 `[protocol.qqbot]` 这类带点小节时，长小节优先匹配。
     */
    private static function splitSection(array $segments): array {
        foreach (self::sectionNames() as $candidate) {
            $candidatePath = array_values(array_filter(explode('.', $candidate), fn($v) => $v !== ''));
            $count = count($candidatePath);
            if ($count > 0 && $count < count($segments) && array_slice($segments, 0, $count) === $candidatePath) {
                return [$candidate, array_slice($segments, $count)];
            }
        }
        // 没有对应小节：按顶层小节处理
        return [$segments[0], array_slice($segments, 1)];
    }

    /** 在小节末尾插入一行；小节不存在时在文件末尾追加小节 */
    private static function appendLine(array $lines, string $section, string $line, ?int $sectionStart, int $sectionEnd): array {
        if ($sectionStart === null) {
            $lines[] = '';
            $lines[] = '[' . $section . ']';
            $lines[] = $line;
            return $lines;
        }
        array_splice($lines, $sectionEnd, 0, [$line]);
        return $lines;
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
            self::warn("配置文件不可读：{$path}");
            return self::$tree;
        }

        $prepared = self::preprocess(Fs::read($path));
        $raw = @parse_ini_string($prepared, true, INI_SCANNER_RAW);
        if ($raw === false) {
            self::$tree = [];
            self::warn("配置文件解析失败：{$path}（请检查小节名是否闭合、引号是否配对）");
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

    /**
     * 把「语法合法但语义坑人」的写法改写成 parse_ini 能正确理解的形式。
     *
     * 处理两件事（都在 INI 规范之外，但都是用户的真实笔误）：
     *
     * 1. 行首 # 当注释（宽松模式）。PHP 的 INI 解析器只认 ;，
     *    所以 `# debug = true` 会被解析成一个名为 "# debug" 的键——
     *    不报错，但用户以为自己写了注释，实际什么都没配置。
     *    这里把这类行改成 ; 注释，并留下告警提示。
     *
     * 2. 值里的 ; 不再截断。`token = abc;def` 在原始 INI 里只读到 abc，
     *    后半截静默丢失。WebUI 写入的 token / 密码经常含 ;，这会造成
     *    「存进去是错的、读回来也是错的，但两边都看不出问题」。
     *    这里把含 ; 的值整体包进双引号（INI 引号内的 ; 是普通字符）。
     *
     * 引号内的 ; 不动，否则会破坏用户自己写的带引号值。
     */
    private static function preprocess(string $source): string {
        if ($source === '') {
            return '';
        }
        // 必须带 /u：不带的话 \R 按字节匹配，UTF-8 中文会在这里被拦腰切断
        // （实测「; 订阅消息类型」被切成 "; 订" + 半个汉字，解析后产生非法 UTF-8 键名，
        //  进而让 json_encode 整棵树失败，WebUI 配置页直接 500）
        $lines = preg_split('/\R/u', $source) ?: [];
        $out = [];
        $continuing = false;

        foreach ($lines as $index => $line) {
            $no = $index + 1;
            $trimmed = ltrim($line);

            // 续行期间原样保留
            if ($continuing) {
                $out[] = $line;
                $continuing = self::isContinuation($line);
                continue;
            }

            if ($trimmed === '') {
                $out[] = $line;
                continue;
            }

            // 1) 行首 # 当注释
            if ($trimmed[0] === '#') {
                $out[] = ';' . substr($trimmed, 1);
                self::warn("第 {$no} 行以 # 开头：PHP 的 INI 只认 ; 作注释，"
                    . "# 会被当成键名的一部分。已按注释忽略，建议改用 ; 开头。");
                continue;
            }

            // 注释行（; 开头）原样保留，绝不参与「值里含 ;」的改写——
            // 否则 `; ===== 说明 =====` 这种注释会被当成「键 = 值」处理，
            // 在分号处截断后变成一行残缺的键，进而污染解析结果和 json 编码。
            if ($trimmed[0] === ';') {
                $out[] = $line;
                continue;
            }

            // 小节行原样保留
            if ($trimmed[0] === '[') {
                $out[] = $line;
                continue;
            }

            // 2) 值里含 ; 时整体加引号；引号值加保护标记
            $out[] = self::protectQuotedValue(self::quoteDangerousValue($line, $no));
        }

        return implode("\n", $out);
    }

    /**
     * 行尾反斜杠是否表示续行。
     *
     * 实测 PHP 8.5 的 RAW / NORMAL / TYPED 三种 scanner **都不支持续行**：
     * `k = a \\\n b` 一律解析成 `a \\`，后面的 b 被丢弃。因此这里恒返回 false，
     * 即不做任何续行特殊处理——避免 preprocess 以为"下一行是延续"而跳过了
     * 本该处理的注释/引号逻辑，反而制造新的差异。
     */
    private static function isContinuation(string $line): bool {
        return false;
    }

    /**
     * 若「键 = 值」里的值含未加引号的 ;，把值整体包成双引号；其余原样返回
     * （避免无谓地改变用户文件的可读性）。
     */
    private static function quoteDangerousValue(string $line, int $no): string {
        if (!preg_match('/^(\s*)([^=\[\]]+?)(\s*[=:]\s*)(.*)$/', $line, $m)) {
            return $line;
        }
        $lead  = $m[1];
        $key   = $m[2];
        $sep   = $m[3];
        $value = $m[4];
        if ($value === '') {
            return $line;
        }
        // 已被引号包住：不动
        if ($value[0] === '"' || $value[0] === "'") {
            return $line;
        }
        if (!str_contains($value, ';')) {
            return $line;
        }
        self::warn("第 {$no} 行「" . trim($key) . "」的值含分号：INI 会把 ; 之后当成注释丢弃，"
            . "已自动加双引号保护。若你本意是写注释，请另起一行用 ; 开头。");
        return $lead . $key . $sep . self::wrapQuoted($value);
    }

    /**
     * 引号值保护标记。
     *
     * parse_ini 的 RAW 模式会把双引号剥掉，于是 "  padded  " 和 未加引号的
     * 两个空格包着的值到 cast() 时已经分不清了，而后者按 INI 语义就该被 trim。
     * 这里在解析前给含首尾空白的引号值套一层私有标记，cast() 见到标记就原样取出。
     */
    private const QUOTED_MARK = "\x01";

    /**
     * 给配置文件里「用户自己写的」引号值加保护标记。
     * 只处理形如 `key = "  x  "` 且引号内含首尾空白的情形——没有空白就不需要保护，
     * 加了反而让文件内容变得不可读（标记字符会写进预处理结果、但不会落盘）。
     */
    private static function protectQuotedValue(string $line): string {
        if (!preg_match('/^(\s*)([^=\[\]]+?)(\s*[=:]\s*)(.*)$/', $line, $m)) {
            return $line;
        }
        [, $lead, $key, $sep, $value] = $m;
        if (strlen($value) < 2) {
            return $line;
        }
        $q = $value[0];
        if (($q !== '"' && $q !== "'") || substr($value, -1) !== $q) {
            return $line;
        }
        $inner = substr($value, 1, -1);
        // 空值、已带标记的值不需要保护
        if ($inner === '' || str_contains($inner, self::QUOTED_MARK)) {
            return $line;
        }
        // 需要打标记的两种引号值：
        //   a) 有首尾空白 —— 否则会被 trim 吃掉；
        //   b) 看着像数字/布尔字面量 —— 否则会被 cast() 转成 int/float/bool，
        //      而用户加引号的意图恰恰是"这就是个字符串"。
        $needsMark = $inner !== trim($inner) || self::looksLikeScalar($inner);
        if (!$needsMark) {
            return $line;
        }
        return $lead . $key . $sep . $q . self::QUOTED_MARK . $inner . self::QUOTED_MARK . $q;
    }

    /** 该字符串是否会被 cast() 当成非字符串类型处理 */
    private static function looksLikeScalar(string $v): bool {
        return (bool)preg_match(
            '/^(-?\d+|-?(\d+\.\d*|\.\d+)([eE][+-]?\d+)?|true|false|on|off|yes|no|null|none)$/i',
            $v
        );
    }

    /** 把字面量值包成「引号写法 + 保护标记」 */
    private static function wrapQuoted(string $value): string {
        $inner = str_replace('"', '\\"', $value);
        if ($value !== trim($value)) {
            $inner = self::QUOTED_MARK . $inner . self::QUOTED_MARK;
        }
        return '"' . $inner . '"';
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

    /**
     * 按路径取值。
     *
     * 键名查找**不区分大小写**：`[Redis]` 与 `[redis]`、`get("redis.host")` 与
     * `get("Redis.host")` 都命中同一条。
     *
     * 为什么需要兜底：历史上 Config.ini 里同时存在 `[Redis]`（大写）和
     * `get("redis.host")`（小写）这种组合，能读到只是因为调用点恰好没走前缀回退——
     * 换一个调用点、或换一套解析器就会 MISS，表现为「配置写了但没生效」。
     * 精确匹配优先（同名不同大小写的键仍各自独立），只有精确未命中时才退化为
     * 不区分大小写查找，因此不会改变已有文件的语义。
     */
    private static function dig(array $tree, array $segments): mixed {
        $node = $tree;
        foreach ($segments as $segment) {
            if (!is_array($node)) {
                return null;
            }
            if (array_key_exists($segment, $node)) {
                $node = $node[$segment];
                continue;
            }
            $actual = self::caseInsensitiveKey($node, $segment);
            if ($actual === null) {
                return null;
            }
            $node = $node[$actual];
        }
        return $node;
    }

    /** 在节点里找与 $segment 仅大小写不同的键；找不唯一则返回 null（避免猜错） */
    private static function caseInsensitiveKey(array $node, string $segment): ?string {
        $hit = null;
        foreach ($node as $key => $_) {
            if (is_string($key) && strcasecmp($key, $segment) === 0) {
                if ($hit !== null) {
                    return null;
                }
                $hit = $key;
            }
        }
        return $hit;
    }

    /** INI_SCANNER_RAW 不做类型转换，这里统一处理 */
    private static function cast(mixed $value): mixed {
        if (!is_string($value)) {
            return $value;
        }
        // 注意：INI_SCANNER_RAW **已经把值的引号剥掉了**，这里不能再去引号。
        // 实测 "x" 读回来是 x、'  padded  ' 读回来是带引号的原串（单引号不剥）。
        // 旧代码在这里 trim + 剥双引号，对带首尾空白的值会直接把内容吃掉
        // （"  padded  " → 剥引号后剩 2 个空格 → 再 trim → 'padded' 都不对）。
        // 引号包裹的值 = 字面量字符串，**原样返回，不 trim、不剥引号**。
        // parse_ini 在 RAW 模式下对双引号已剥引号（"x" → x），对单引号不剥
        // （'x' → 'x'）。这里统一处理两种情形：双引号已剥则直接 trim，
        // 未剥则去掉外层引号并保留内部内容。
        // RAW 模式下 parse_ini 已把双引号剥掉、单引号保留，因此这里无法靠
        // "是否带引号" 判断要不要 trim。带首尾空白的引号值由 preprocess()
        // 用占位符保护（见 QUOTED_MARK），到这一步时首尾是标记字符，不会入 trim。
        $raw = $value;
        if (str_starts_with($raw, self::QUOTED_MARK) && str_ends_with($raw, self::QUOTED_MARK)) {
            return substr($raw, strlen(self::QUOTED_MARK), -strlen(self::QUOTED_MARK));
        }
        $v = trim($value);
        $len = strlen($v);
        // 纯数字：转成 int / float。
        // 之前这里恒返回字符串，导致 `port = 8008` 读出来是 "8008"，
        // 而 Config::int() 靠 is_numeric 才勉强能用；直接 Config::get() 取到的
        // 类型与环境无关但和直觉不符。显式加引号的数字仍按字符串返回（上面已处理）。
        if (preg_match('/^-?\d+$/', $v)) {
            // 超出 int 范围（如 QQ 号 20 位）时保留字符串，避免精度丢失
            if ($v === (string)(int)$v) {
                return (int)$v;
            }
            return $v;
        }
        if (preg_match('/^-?(\d+\.\d*|\.\d+)([eE][+-]?\d+)?$/', $v)) {
            return (float)$v;
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

    /**
     * 把值编码成 Config.ini 里的一行值。
     *
     * 关键点：**含 `;`、首尾空白、换行的字符串必须加双引号**，否则
     *  - `;` 之后会被 INI 当成注释丢掉（写 a;b 读回 a）；
     *  - 首尾空白会被 trim 掉；
     *  - 换行会让一行值变成两行，第二行成为无法解析的野行（写坏了整个小节）。
     *
     * 加引号时只需转义双引号本身——实测 INI_SCANNER_RAW 下引号内的反斜杠
     * 是字面量，不做转义处理，多转义反而会让 `C:\\path` 读回 `C:\\\\path`。
     */
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
        $string = (string)$value;
        if (self::needsQuoting($string)) {
            return '"' . str_replace('"', '\\"', $string) . '"';
        }
        return $string;
    }

    /**
     * 该字符串值是否必须加引号才能被 INI 原样读回。
     *
     * 实测结论（PHP 8.5，三种 scanner 表现一致）：
     *  - 引号内的 `;` 是普通字符        → 加引号即可
     *  - 引号内的首尾空白 **会保留**      → 加引号即可
     *  - 引号内的反斜杠 **不做转义处理**  → 不要自作聪明地转义 \\
     *  - 引号内不能含真实换行（解析直接失败）
     */
    private static function needsQuoting(string $value): bool {
        if (str_contains($value, ';')) {
            return true;
        }
        if ($value !== trim($value)) {
            return true;
        }
        // 首字符是引号时也加引号，避免读回时被 parse_ini 当成引号语法吃掉
        $first = $value === '' ? '' : $value[0];
        return $first === '"' || $first === "'";
    }

    /**
     * INI 格式本身表达不了的字符串：含真实换行 / 回车。
     *
     * 引号内不能含换行（解析失败），续行在各 scanner 下实测都不支持，
     * 而把换行写成 \\n 只会得到字面量 `\\n` 两个字符、读回来不是换行。
     * 也就是说这类值**写进去必然读不回原样**——与其静默写坏整个小节
     * （P0 之前的实际行为：值里一个换行就让整份配置解析失败），
     * 不如在写入前就拒绝，并明确告诉调用方。
     */
    private static function isInexpressible(mixed $value): bool {
        if (!is_string($value)) {
            return false;
        }
        return str_contains($value, "\n") || str_contains($value, "\r");
    }
}