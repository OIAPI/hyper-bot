<?php
/**
 * 静态检查：所有 Class:: 与 new Class 的引用能否解析。
 *
 * 为什么需要它：swoole 架构的致命错误只有在装了 ext-swoole 的机器上才会暴露
 * （例如 HttpServer 里引用了已改名的 ProtocolOneBotWsHub），本地跑 php 架构的测试
 * 永远发现不了。这个脚本不需要 swoole，靠 tokenizer + 自动加载就能把这类符号抓出来。
 */
$root = dirname(__DIR__);
define('__root__', $root);
require $root . '/vendor/autoload.php';

$dirs = ['app', 'core', 'controller', 'protocol', 'utils', 'webui', 'public', 'bin', 'tests'];
$files = [];
foreach ($dirs as $dir) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f->isFile() && $f->getExtension() === 'php') {
            $files[] = $f->getPathname();
        }
    }
}

$projectRoots = ['App', 'Core', 'Controller', 'Protocol', 'Utils', 'Webui'];
$bad = [];
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    $tokens = token_get_all($src);
    $ns = '';
    $uses = [];
    $declared = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) continue;
        if ($t[0] === T_NAMESPACE) {
            $buf = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $x = $tokens[$j];
                if (is_array($x) && in_array($x[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $buf .= $x[1]; }
                elseif ($x === ';' || $x === '{') break;
            }
            $ns = trim($buf, '\\');
        }
        if ($t[0] === T_USE && $ns !== '') {
            $buf = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $x = $tokens[$j];
                if (is_array($x) && in_array($x[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $buf .= $x[1]; continue; }
                if (is_array($x) && $x[0] === T_AS) {
                    for ($k = $j + 1; $k < $n; $k++) {
                        $ak = $tokens[$k];
                        if (is_array($ak) && $ak[0] === T_WHITESPACE) { continue; }
                        if (is_array($ak) && $ak[0] === T_STRING) { $uses[$ak[1]] = trim($buf, '\\'); }
                        break;
                    }
                    $buf = '';
                    break;
                }
                if ($x === ';' || $x === ',' || $x === '{') {
                    if ($buf !== '') { $short = substr(strrchr('\\' . $buf, '\\'), 1); $uses[$short] = trim($buf, '\\'); }
                    $buf = '';
                    if ($x === ';' || $x === '{') break;
                }
            }
        }
        if ($t[0] === T_CLASS || $t[0] === T_INTERFACE || $t[0] === T_TRAIT) {
            for ($j = $i + 1; $j < $n; $j++) { if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) { $declared[] = $tokens[$j][1]; break; } }
        }
    }

    foreach ($tokens as $idx => $t) {
        if (!is_array($t) || $t[0] !== T_STRING) continue;
        $prev = $tokens[$idx - 1] ?? null;
        $next = $tokens[$idx + 1] ?? null;
        $isNew = is_array($prev) && $prev[0] === T_NEW;
        $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;
        if (!$isNew && !$isStatic) continue;
        $name = $t[1];
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) continue;
        if (in_array($name, $declared, true)) continue;
        // PHP 类名不区分大小写：视图/插件里写 utils:: / cache:: 也能命中 Utils\Utils
    $lower = array_change_key_case($uses, CASE_LOWER);
    $fqcn = $lower[strtolower($name)] ?? ($ns !== '' ? $ns . '\\' . $name : $name);
        if (str_starts_with($fqcn, '\\')) $fqcn = substr($fqcn, 1);
        $top = explode('\\', $fqcn)[0];
        if (!in_array($top, $projectRoots, true)) continue;
        $rel = str_replace($root . '/', '', $file);
        if (!class_exists($fqcn) && !interface_exists($fqcn) && !trait_exists($fqcn)) {
            $bad[] = $rel . ' => ' . $fqcn . ' [' . basename($file) . ']';
            continue;
        }
        if (!$isStatic) continue;

        // 静态语法调用实例方法：PHP 允许语法通过但运行期报
        // "Non-static method X::y() cannot be called statically"
        for ($k = $idx + 2; $k < $n; $k++) {
            $mk = $tokens[$k];
            if (is_array($mk) && $mk[0] === T_WHITESPACE) { continue; }
            if (is_array($mk) && $mk[0] === T_STRING) {
                $method = $mk[1];
                if (strtolower($method) === 'class') break;
                // 类常量（PermRule::OWNER / AuthMiddleware::COOKIE）不是方法调用
                if (defined($fqcn . '::' . $method)) break;
                try {
                    if (!method_exists($fqcn, $method)) {
                        if (!method_exists($fqcn, '__callStatic')) {
                            $bad[] = $rel . ' => ' . $fqcn . '::' . $method . '() 方法不存在 [' . basename($file) . ']';
                        }
                    } else {
                        $rm = new \ReflectionMethod($fqcn, $method);
                        if (!$rm->isStatic() && !method_exists($fqcn, '__callStatic')) {
                            $bad[] = $rel . ' => ' . $fqcn . '::' . $method . '() 是实例方法，不能静态调用 [' . basename($file) . ']';
                        }
                    }
                } catch (\Throwable) {
                    // 反射失败（内部类等）不当作错误
                }
            }
            break;
        }
    }
}
$bad = array_values(array_unique($bad));
if ($bad === []) {
    echo "全部通过（类引用全部可解析）\n";
    exit(0);
}
echo "无法解析 " . count($bad) . " 处类引用：\n";
foreach ($bad as $line) {
    echo "  - " . $line . "\n";
}
exit(1);