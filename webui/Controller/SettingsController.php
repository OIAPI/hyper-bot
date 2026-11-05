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
        // [cq] 只作为旧配置迁移别名保留（首次建账号时读一次），
        // 运行期发送已全部走「机器人账号」，不再读取 cq.api / cq.auth。
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

    /**
     * 修改单个配置项。
     *
     * 传了 section 就按「明确小节」写入：小节可以不存在、也可以带点
     * （插件常把配置放在 [plugin.xxx] 下，用 Config::string('plugin.xxx.key') 读）。
     * 不传 section 时保持原行为（按 key 的点分路径找已有小节）。
     */
    public function update(Request $request): Response {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $key = trim((string)$request->input('key', ''));
        $section = trim((string)$request->input('section', ''));
        if ($key === '' || str_starts_with($key, '_')) {
            return $this->fail('配置键不合法', -1, 400);
        }
        if ($section !== '') {
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,64}$/', $section) || str_contains($section, '..')) {
                return $this->fail('小节名不合法：只能用字母、数字、点、下划线、横线', -1, 400);
            }
            if (!preg_match('/^[A-Za-z0-9_.\-]{1,96}$/', $key) || str_contains($key, '..')) {
                return $this->fail('键名不合法：只能用字母、数字、点、下划线、横线', -1, 400);
            }
        }
        $value = $request->input('value');
        $ok = $section !== '' ? Config::setInSection($section, $key, $value) : Config::set($key, $value);
        if (!$ok) {
            // 具体原因在 Config::warnings() 里（值含换行被拒、文件不可写等），
            // 不透出来用户只会看到一句笼统的"写入失败"，不知道该改什么。
            $warnings = Config::warnings();
            $reason = $warnings === []
                ? '写入失败（Config.ini 不可写或键所在小节无法创建）'
                : (string)end($warnings);
            return $this->fail($reason, -2, 500);
        }
        $path = $section !== '' ? $section . '.' . $key : $key;
        return $this->ok([
            'section'  => $section,
            'key'      => $key,
            'path'     => $path,
            'value'    => Config::get($path),
            'backup'   => Config::lastBackup(),
            'warnings' => Config::warnings(),
        ]);
    }

    /** 删除配置项 / 整个小节 */
    public function destroy(Request $request): Response {
        if ($deny = $this->guard($request, 'settings.write')) {
            return $deny;
        }
        $path = trim((string)$request->input('path', ''));
        $whole = (bool)$request->input('whole', false);
        if ($path === '' || str_contains($path, '..') || !preg_match('/^[A-Za-z0-9_.\-\[\]]{1,160}$/', $path)) {
            return $this->fail('配置路径不合法', -1, 400);
        }
        $ok = $whole ? Config::removeSection($path) : Config::remove($path);
        if (!$ok) {
            $warnings = Config::warnings();
            $reason = $warnings === [] ? '删除失败：路径不存在或 Config.ini 不可写' : (string)end($warnings);
            return $this->fail($reason, -2, 400);
        }
        return $this->ok([
            'path'     => $path,
            'whole'    => $whole,
            'backup'   => Config::lastBackup(),
            'warnings' => Config::warnings(),
        ], '已删除 ' . ($whole ? '小节 ' : '配置项 ') . $path);
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