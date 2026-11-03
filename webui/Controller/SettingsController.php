<?php
namespace Webui\Controller;

use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Utils\Env;

/** 配置：整树读取（脱敏）/ 单键修改 / 原始文件查看 / 校验 */
final class SettingsController extends Base {
    /** 整棵配置树（敏感字段已脱敏） */
    public function index(Request $request): Response {
        $config = Config::all(true);
        // 历史遗留小节。注意：[cq] 不是纯遗留——cq.api / cq.auth 仍被
        // HttpAdapter、api/app.php 通过 core/Config.php 的别名映射读取，
        // 隐藏它只会让「能收不能发」这类问题无从下手，所以这里不再过滤 [cq]。
        $legacy = [];
        return $this->ok([
            'config'   => $config,
            'legacy'   => $legacy,
            'warnings' => Config::warnings(),
            'path'     => Config::path(),
            'writable' => is_writable(Config::path()),
        ]);
    }

    /** 原始 Config.ini 文本（脱敏行替换） */
    public function raw(Request $request): Response {
        $path = Config::path();
        if (!is_readable($path)) {
            return $this->fail('配置文件不可读', -1, 404);
        }
        $lines = explode("\n", (string)file_get_contents($path));
        foreach ($lines as $i => $line) {
            if (preg_match('/^\s*([a-zA-Z0-9_.]+)\s*[=:]\s*(.+)$/u', $line, $m)) {
                if (in_array(strtolower($m[1]), ['token', 'secret', 'client_secret', 'auth', 'password', 'access_token'], true)) {
                    $lines[$i] = $m[1] . ' = ***';
                }
            }
        }
        return $this->ok(['text' => implode("\n", $lines)]);
    }

    /** 修改单个配置项 */
    public function update(Request $request): Response {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $key = (string)$request->input('key', '');
        if ($key === '' || str_starts_with($key, '_')) {
            return $this->fail('配置键不合法', -1, 400);
        }
        if (!Config::set($key, $request->input('value'))) {
            // 具体原因在 Config::warnings() 里（值含换行被拒、文件不可写等），
            // 不透出来用户只会看到一句笼统的"写入失败"，不知道该改什么。
            $warnings = Config::warnings();
            $reason = $warnings === []
                ? '写入失败（Config.ini 不可写或键所在小节无法创建）'
                : (string)end($warnings);
            return $this->fail($reason, -2, 500);
        }
        return $this->ok([
            'key'      => $key,
            'value'    => Config::get($key),
            'backup'   => Config::lastBackup(),
            'warnings' => Config::warnings(),
        ]);
    }

    /** 重新加载配置缓存 */
    public function reload(Request $request): Response {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        Env::refreshCache();
        return $this->ok(['warnings' => Config::warnings()], '配置已重新加载');
    }
}