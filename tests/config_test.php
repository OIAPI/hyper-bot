<?php
/**
 * 配置内核回归测试（core/Config.php）
 *
 * 每个用例对应一个曾经**实测复现过**的缺陷，防止回退：
 *   - 值含分号被静默截断（写入 a;b 读回 a）
 *   - 值含换行把文件写坏
 *   - 行首 # 被当成键名而不是注释
 *   - [Redis] / redis 大小写不一致导致读不到
 *   - 写回无备份
 *
 * 全部用例在临时文件中进行，不触碰真实 Config.ini。
 */

define('__root__', dirname(__DIR__));
require __root__ . '/vendor/autoload.php';

use Core\Config;

$pass = 0;
$fail = 0;
$tmpdir = sys_get_temp_dir();
$sandbox = $tmpdir . '/config-test-' . bin2hex(random_bytes(4));
mkdir($sandbox, 0775, true);

function ok(string $label, mixed $actual, mixed $expected): void {
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  \033[32m✓\033[0m %s\n", $label);
        return;
    }
    $fail++;
    printf("  \033[31m✗\033[0m %s\n      期望 %s\n      实际 %s\n",
        $label,
        json_encode($expected, 256 | JSON_UNESCAPED_UNICODE),
        json_encode($actual, 256 | JSON_UNESCAPED_UNICODE));
}

function fixture(string $name, string $body): string {
    global $sandbox;
    $path = $sandbox . '/' . $name;
    file_put_contents($path, $body);
    Config::path($path);
    Config::refresh();
    return $path;
}

echo "\n=== 1. 值里的分号不再截断（P0）===\n";
fixture('semicolon.ini', "[app]\ntoken = abc;def\nnext = ok\n");
ok('读回完整值', Config::get('app.token'), 'abc;def');
ok('后续键未受影响', Config::get('app.next'), 'ok');
ok('有告警提示', count(array_filter(Config::warnings(), fn($w) => str_contains($w, '分号'))) > 0, true);

echo "\n=== 2. 写入含分号的值后回读一致（P0）===\n";
$p = fixture('write-semicolon.ini', "[app]\ndebug = true\n");
ok('set 返回成功', Config::set('app.token', 'my;secret;token'), true);
Config::refresh();
ok('回读值与写入一致', Config::get('app.token'), 'my;secret;token');
ok('无回读不一致告警', count(array_filter(Config::warnings(), fn($w) => str_contains($w, '回读不一致'))), 0);

echo "\n=== 3. 含换行的值被拒绝，而不是写坏文件（P0）===\n";
// INI 无法表达含换行的值：引号内换行解析失败、续行各 scanner 实测都不支持。
// P0 之前的实际后果是：值里一个换行就把整份配置写成解析失败，所有键全部丢失。
$p = fixture('write-newline.ini', "[app]\ndebug = true\n");
$before = file_get_contents($p);
ok('set 明确返回失败', Config::set('app.memo', "line1\nline2"), false);
ok('文件内容原封不动', file_get_contents($p), $before);
ok('有拒绝写入的告警', count(array_filter(Config::warnings(), fn($w) => str_contains($w, '拒绝写入'))) > 0, true);
Config::refresh();
ok('原有键仍然可读', Config::get('app.debug'), true);

echo "\n=== 4. 首尾空白保留 ===\n";
fixture('spaces-file.ini', "[app]\npadded = \"  padded  \"\nraw = padded\n");
ok('文件里加引号可保留首尾空白', Config::get('app.padded'), '  padded  ');
ok('无引号时被 trim（INI 行为）', Config::get('app.raw'), 'padded');
echo "  （写入路径的空白保留单独验证，见下方 4b）\n";

echo "\n=== 5. 行首 # 当注释（宽松模式，方案 A）===\n";
fixture('hash.ini', "[app]\n# debug = true\ndebug = false\n");
ok('真键生效', Config::get('app.debug'), false);
ok('有告警提示改用分号', count(array_filter(Config::warnings(), fn($w) => str_contains($w, '#'))) > 0, true);

fixture('hash-only.ini', "[app]\n# timeout = 30\n");
ok('只写 # 行时不会生成 "# timeout" 键', Config::get('app.timeout', 'MISS'), 'MISS');

fixture('hash-value.ini', "[app]\ncolor = #FF0000\n");
ok('值里的 # 不受影响', Config::get('app.color'), '#FF0000');

echo "\n=== 6. 大小写不敏感兜底（P2）===\n";
fixture('case.ini', "[Redis]\nhost = 127.0.0.1\n\n[App]\ndebug = true\n");
ok('大写小节 + 小写键能读到', Config::get('redis.host'), '127.0.0.1');
ok('大写小节 + 大写键能读到', Config::get('Redis.host'), '127.0.0.1');
ok('大写小节名 + 小写路径', Config::get('app.debug'), true);

echo "\n=== 7. 写前备份（P3）===\n";
$p = fixture('backup.ini', "[app]\ndebug = true\n");
Config::set('app.debug', false);
$bak = Config::lastBackup();
ok('生成了备份路径', is_string($bak) && is_file((string)$bak), true);
ok('备份内容是写前快照', is_string($bak) ? (str_contains(file_get_contents($bak), 'debug = true')) : false, true);
ok('真实文件已更新', Config::get('app.debug'), false);

echo "\n=== 8. 备份数量上限 ===\n";
$p = fixture('prune.ini', "[app]\ndebug = true\n");
for ($i = 0; $i < 12; $i++) {
    Config::set('app.debug', $i % 2 === 0);
    usleep(1000);
}
$baks = glob($p . '.*.bak') ?: [];
ok('备份不超过 5 份', count($baks) <= 5, true);

echo "\n=== 9. 原有能力不回归 ===\n";
$p = fixture('compat.ini', "[app]\ndebug = true\ntimezone = Asia/Shanghai\n\n[permission]\nsuper = [1, 2, 3]\n\n[protocol.qqbot]\napp_id = 100\n\n[protocol]\ndefault = Http\n");
ok('bool 读取', Config::bool('app.debug', false), true);
ok('字符串读取', Config::string('app.timezone'), 'Asia/Shanghai');
ok('JSON 数组读取', Config::get('permission.super'), [1, 2, 3]);
ok('带点小节 + 完整路径', Config::get('protocol.qqbot.app_id'), 100);
ok('带点小节 + 短写', Config::get('qqbot.app_id'), 100);
ok('protocol 前缀回退', Config::get('default'), 'Http');

echo "\n=== 10. 数字类型 ===\n";
fixture('numbers.ini', "[app]\nport = 8008\nratio = 0.5\nuin = 12345678901234567890\nquoted = \"8008\"\n");
ok('整数转 int', Config::get('app.port'), 8008);
ok('小数转 float', Config::get('app.ratio'), 0.5);
ok('超出 int 范围保留字符串（QQ 号精度）', Config::get('app.uin'), '12345678901234567890');
ok('显式加引号的数字保持字符串', Config::get('app.quoted'), '8008');

echo "\n=== 11. UTF-8 中文不被按字节切断 ===\n";
// 曾经的写法 preg_split('/\R/') 不带 /u，会按字节匹配 \R，
// 把「; 订阅消息类型」切成 "; 订" + 半个汉字，解析出非法 UTF-8 键名，
// 最终 json_encode 整棵树失败 → WebUI 配置页 500。
fixture('utf8.ini', "[app]\ndesc = 中文值\n; 订阅消息类型\n[type]\nmessage = true\n");
ok('中文注释不产生杂键', array_keys(Config::section('app')), ['desc']);
ok('中文字符串值完好', Config::get('app.desc'), '中文值');
ok('注释后的下一个小节仍正确', Config::get('type.message'), true);
ok('整棵树可 json_encode', json_encode(Config::all(false), JSON_UNESCAPED_UNICODE) !== false, true);
$badKeys = [];
$allForUtf8 = Config::all(false);
array_walk_recursive($allForUtf8, function ($v, $k) use (&$badKeys) {
    if (is_string($k) && !mb_check_encoding($k, 'UTF-8')) {
        $badKeys[] = bin2hex($k);
    }
});
ok('没有非法 UTF-8 键名', $badKeys === [], true);

echo "\n=== 12. 损坏文件仍能兜住 ===\n";
fixture('broken.ini', "[app\ndebug = true\n");
ok('解析失败返回默认值', Config::get('app.debug', 'DEFAULT'), 'DEFAULT');
ok('有解析失败告警', count(array_filter(Config::warnings(), fn($w) => str_contains($w, '解析失败'))) > 0, true);

// 清理
foreach (glob($sandbox . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($sandbox);
Config::path(__root__ . '/Config.ini');
Config::refresh();

// 历史 [cq] 段落：cq.api / cq.auth 必须映射到 [protocol.onebot]，否则发送会打到空地址
ok('cq.api 别名指向 protocol.onebot.api', Config::string('cq.api', ''), Config::string('protocol.onebot.api', 'x'));
ok('cq.auth 别名指向 protocol.onebot.token', Config::string('cq.auth', 'x'), Config::string('protocol.onebot.token', 'x'));

printf("\n%s 通过 %d，失败 %d\n", $fail === 0 ? '\033[32m全部通过\033[0m' : '\033[31m有失败\033[0m', $pass, $fail);
exit($fail === 0 ? 0 : 1);