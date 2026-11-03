<?php
namespace App\Plugins;

use App\BaseApp;
use Controller\Attr\Rule;
use Controller\Enum\PermRule;
use Core\App;
use Core\Bot\Registry;
use Webui\PluginInstaller;

/**
 * @name pluginimport
 * @description 在聊天里导入插件：git 仓库直连、远程 zip 下载、重载注册表
 * @author Hyper Bot
 * @lastUpdate 2026-11-03
 * @cover 图标
 * @version 1.0
 *
 * 设计取舍：
 *  - 只开放「git 地址」和「远程 zip 地址」两种，聊天窗口没法传文件；
 *  - 权限用注解声明成 owner（主人），Dispatcher 会在进入方法前拦下不够权限的人，
 *    方法体里仍再判一次——注解是配置，越权检查不能只靠配置；
 *  - 真正的解包/克隆复用 Webui\PluginInstaller，WebUI 与聊天两条入口共用一份实现，
 *    避免「后台能装、聊天装出来结构不一样」这种问题。
 */
class PluginImport extends BaseApp
{
    /** 远程 zip 下载上限，与 PluginInstaller::MAX_BYTES 对齐 */
    private const MAX_BYTES = 32 * 1024 * 1024;

    #[Rule('/^\/?(插件导入|pluginimport)$/iu', name: '插件导入    查看用法（主人）', permission: PermRule::OWNER)]
    public function help(): mixed
    {
        return $this->say(join("\n", [
            '插件导入 · 主人专用',
            '',
            '插件导入 列表                       已安装插件',
            '插件导入 git <仓库地址> [分支]      从 git 克隆安装',
            '插件导入 zip <压缩包直链>           从 http(s) 下载 zip 安装',
            '插件导入 重载                       重新扫描插件目录',
            '',
            '装完会落到 runtime/github/<目录名>，缺 composer.json 也能装，只是不会被扫描到。',
        ]));
    }

    #[Rule('/^\/?(插件导入|pluginimport)\s+(列表|list)$/iu', name: '插件导入 列表', permission: PermRule::OWNER)]
    public function list(): mixed
    {
        if (!$this->allowed()) {
            return $this->say('只有主人可以使用插件导入。');
        }
        $plugins = App::boot()->container->get(Registry::class)->plugins();
        if ($plugins === []) {
            return $this->say('还没有扫描到任何插件。');
        }
        $lines = ['已安装插件（' . count($plugins) . '）：'];
        foreach ($plugins as $plugin) {
            $lines[] = '· ' . ($plugin['displayName'] ?? $plugin['name'] ?? $plugin['class']);
        }
        return $this->say(join("\n", $lines));
    }

    #[Rule('/^\/?(插件导入|pluginimport)\s+(重载|reload)$/iu', name: '插件导入 重载', permission: PermRule::OWNER)]
    public function reload(): mixed
    {
        if (!$this->allowed()) {
            return $this->say('只有主人可以使用插件导入。');
        }
        try {
            $items = $this->rescan();
            return $this->say('已重新扫描，当前 ' . count($items) . ' 个插件。');
        } catch (\Throwable $e) {
            return $this->say('重载失败：' . $e->getMessage());
        }
    }

    #[Rule('/^\/?(插件导入|pluginimport)\s+git\s+(\S+)(?:\s+(\S+))?$/iu', name: '插件导入 git <仓库地址> [分支]', permission: PermRule::OWNER)]
    public function git(): mixed
    {
        if (!$this->allowed()) {
            return $this->say('只有主人可以使用插件导入。');
        }
        preg_match($this->app['match'], $this->raw, $m);
        $url = trim((string)($m[2] ?? ''));
        $branch = trim((string)($m[3] ?? ''));
        if ($url === '') {
            return $this->say('仓库地址是空的。');
        }
        $this->say('开始克隆 ' . $url . ($branch !== '' ? ' (' . $branch . ')' : '') . ' …');

        $installer = new PluginInstaller();
        $result = $installer->installGit($url, $branch);
        return $this->report($result);
    }

    #[Rule('/^\/?(插件导入|pluginimport)\s+zip\s+(\S+)$/iu', name: '插件导入 zip <直链>', permission: PermRule::OWNER)]
    public function zip(): mixed
    {
        if (!$this->allowed()) {
            return $this->say('只有主人可以使用插件导入。');
        }
        preg_match($this->app['match'], $this->raw, $m);
        $url = trim((string)($m[2] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            return $this->say('只接受 http(s) 直链。');
        }
        $bytes = $this->download($url);
        if ($bytes === null) {
            return $this->say('下载失败：地址不可达或超过 32 MB。');
        }
        $this->say('已下载 ' . round(strlen($bytes) / 1024) . ' KB，开始安装…');

        $installer = new PluginInstaller();
        $name = preg_replace('/\.zip$/i', '', basename(parse_url($url, PHP_URL_PATH) ?: 'plugin.zip')) ?: '';
        $result = $installer->installZip($bytes, basename(parse_url($url, PHP_URL_PATH) ?: 'plugin.zip'), (string)$name);
        return $this->report($result);
    }

    // ------------------------------------------------------------------ 内部

    /** 权限双保险：注解被绕过时这里仍然拦得住 */
    private function allowed(): bool
    {
        return $this->comparePermission($this->getUserPermission($this->qq), PermRule::OWNER);
    }

    /** 重扫注册表并返回插件列表（App 上没有 reloadPlugins 这种便捷方法） */
    private function rescan(): array
    {
        $registry = App::boot()->container->get(Registry::class);
        $registry->invalidate();
        return $registry->plugins();
    }

    private function say(string $text): mixed
    {
        return $this->api->message->sendMsg(group: $this->group, message: $text);
    }

    /** @param array{success:bool,msg:string,name:string,path:string,files:int,warnings:list<string>} $result */
    private function report(array $result): mixed
    {
        if (!$result['success']) {
            return $this->say('安装失败：' . $result['msg']);
        }
        $lines = ['已安装：' . $result['name'], '文件数：' . $result['files']];
        if (($result['warnings'] ?? []) !== []) {
            $lines[] = '提示：';
            foreach (array_slice($result['warnings'], 0, 4) as $warning) {
                $lines[] = '· ' . $warning;
            }
        }
        try {
            $items = $this->rescan();
            $lines[] = '重扫完成，当前 ' . count($items) . ' 个插件。';
        } catch (\Throwable $e) {
            $lines[] = '重扫失败（文件已落盘）：' . $e->getMessage();
        }
        return $this->say(join("\n", $lines));
    }

    /** 用 curl 拉取远程 zip，带超时与大小上限 */
    private function download(string $url): ?string
    {
        if (!function_exists('curl_init')) {
            return null;
        }
        $ch = curl_init($url);
        if ($ch === false) {
            return null;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_USERAGENT      => 'HyperBot-PluginImport/1.0',
        ]);
        $bytes = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($bytes) || $bytes === '' || $status >= 400) {
            return null;
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return null;
        }
        return $bytes;
    }
}
