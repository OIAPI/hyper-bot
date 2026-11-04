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
    $depth = 0;
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t)) {
            // 花括号深度：只解析文件顶层的 use，避开类里的 trait use
            if ($t === '{') {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
            }
            continue;
        }
        if ($t[0] === T_NAMESPACE) {
            $buf = '';
            for ($j = $i + 1; $j < $n; $j++) {
                $x = $tokens[$j];
                if (is_array($x) && in_array($x[0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) { $buf .= $x[1]; }
                elseif ($x === ';' || $x === '{') break;
            }
            $ns = trim($buf, '\\');
        }
        if ($t[0] === T_USE && $depth === 0) {
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
        // 完全限定名（\Core\Extension\Hooks）也是 T_NAME_FULLY_QUALIFIED，不能只看 T_STRING
        if (!is_array($t) || !in_array($t[0], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) continue;
        $prev = $tokens[$idx - 1] ?? null;
        $next = $tokens[$idx + 1] ?? null;
        $isNew = is_array($prev) && $prev[0] === T_NEW;
        $isStatic = is_array($next) && $next[0] === T_DOUBLE_COLON;
        if (!$isNew && !$isStatic) continue;
        $fully = $t[0] === T_NAME_FULLY_QUALIFIED || str_starts_with($t[1], '\\');
        $name = ltrim($t[1], '\\');
        if (in_array(strtolower($name), ['self', 'static', 'parent'], true)) continue;
        if (!$fully && in_array($name, $declared, true)) continue;
        // PHP 类名不区分大小写：视图/插件里写 utils:: / cache:: 也能命中 Utils\Utils
        $lower = array_change_key_case($uses, CASE_LOWER);
        $fqcn = $fully ? $name : ($lower[strtolower($name)] ?? ($ns !== '' ? $ns . '\\' . $name : $name));
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

// ---- 2) $this->method() 是否有对应方法（注释里的调用不算）----
// 这类错误只在运行到那一行时才炸（例如插件 /restart 调了 v2 已删除的 initGroupInfoIfNeed）。
$methodBad = [];
foreach ($files as $file) {
    $src = (string)file_get_contents($file);
    if (!preg_match('/namespace\s+([^;]+);/', $src, $nm)) continue;
    if (!preg_match('/(?:class|abstract class|final class)\s+(\w+)/', $src, $cm)) continue;
    $cls = trim($nm[1]) . '\\' . $cm[1];
    if (!class_exists($cls) || method_exists($cls, '__call')) continue;

    // 去掉注释再找调用，避免被注释掉的旧代码误报
    $clean = '';
    foreach (token_get_all($src) as $t) {
        $clean .= is_array($t) ? (in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? "\n" : $t[1]) : $t;
    }
    preg_match_all('/\$this->(\w+)\s*\(/', $clean, $mm);
    foreach (array_unique($mm[1]) as $name) {
        if (in_array($name, ['__construct', '__destruct', '__call', '__get'], true)) continue;
        if (!method_exists($cls, $name)) {
            $methodBad[] = str_replace($root . '/', '', $file) . ' -> ' . $name . '()';
        }
    }
}
$methodBad = array_values(array_unique($methodBad));

if ($bad === [] && $methodBad === []) {
    echo "全部通过（类引用可解析、this 方法都存在）\n";
    exit(0);
}
if ($bad !== []) {
    echo "无法解析 " . count($bad) . " 处类引用：\n";
    foreach ($bad as $line) {
        echo "  - " . $line . "\n";
    }
}
if ($methodBad !== []) {
    echo "调用了不存在的 $this 方法 " . count($methodBad) . " 处：\n";
    foreach ($methodBad as $line) {
        echo "  - " . $line . "\n";
    }
}
exit(1);