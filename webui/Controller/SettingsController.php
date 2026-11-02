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
        return $this->ok([
            'config'   => Config::all(true),
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
        $key = (string)$request->input('key', '');
        if ($key === '' || str_starts_with($key, '_')) {
            return $this->fail('配置键不合法', -1, 400);
        }
        if (!Config::set($key, $request->input('value'))) {
            return $this->fail('写入失败（Config.ini 不可写或键不存在的小节无法创建）', -2, 500);
        }
        return $this->ok(['key' => $key, 'value' => Config::get($key)]);
    }

    /** 重新加载配置缓存 */
    public function reload(Request $request): Response {
        Env::refreshCache();
        return $this->ok(['warnings' => Config::warnings()], '配置已重新加载');
    }
}
