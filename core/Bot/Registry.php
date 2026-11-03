<?php
namespace Core\Bot;

use Core\Container;
use Core\Log\Logger;
use Core\Fs;

/**
 * 插件/钩子注册表（v2）：替代 v1 的「扫目录 + 反射读属性 + Redis 存 worker 列表」。
 *
 * v1 的插件发现链路有 6 跳：
 *   Router::scanAppClasses() → 递归扫盘 → 读插件 composer.json 推命名空间
 *   → class_exists 触发自动加载 → 反射读 #\Controller\Attr\Rule
 *   → 查 Redis 的 {group}.info.worker 是否启用 → 每次事件都重扫。
 *
 * 问题：
 *   1. 每次事件都重扫磁盘 + 读 Redis，靠 Router::list() 的静态缓存兜底，
 *      WebUI 改插件开关还得 Router::clean()；
 *   2. 「插件启用状态」和「群配置」混在同一个 Redis hash 里；
 *   3. 插件类一旦加载就无法卸载，改代码必须重启。
 *
 * v2：
 *   - 扫描一次，结果缓存进 Store（带版本号），WebUI 可主动失效；
 *   - 插件元数据（名称/描述/版本/作者）与启用状态分离；
 *   - 群级启用状态单独存，按 (群, 插件) 查询；
 *   - 保留 v1 的注解写法（#\Controller\Attr\Rule 等），老插件零改动可用。
 */
final class Registry
{
    private const CACHE_KEY = 'bot:registry:v2';

    /** @var array<string,list<array>> 属性类名 => 规则列表 */
    private array $hooks = [];
    /** @var array<string,array> 类名 => 元数据 */
    private array $plugins = [];
    /** @var array<string,string> 类名 => 显示名 */
    private array $labels = [];
    private bool $loaded = false;

    public function __construct(
        private readonly Logger $logger,
        /** 额外扫描目录：命名空间前缀 => 绝对路径 */
        private array $sources = [],
    ) {}

    /** 默认扫描源：内置 app/Plugins（外部插件由 Core\App::buildPluginSources 提供） */
    public static function defaultSources(string $root): array
    {
        return ['App\\Plugins\\' => $root . '/app/Plugins'];
    }

    /**
     * 扫描并索引所有插件。
     * @param bool $force 忽略缓存
     */
    public function load(bool $force = false): self
    {
        if ($this->loaded && !$force) {
            return $this;
        }
        $this->hooks = [];
        $this->plugins = [];
        $this->labels = [];
        $this->loaded = true;

        foreach ($this->sources as $prefix => $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            foreach (glob(rtrim($dir, '/') . '/*.php') ?: [] as $file) {
                $class = $prefix . basename($file, '.php');
                $this->index($class);
            }
        }

        $this->logger->debug('plugin registry loaded', [
            'plugins' => count($this->plugins),
            'hooks'   => array_sum(array_map('count', $this->hooks)),
        ]);
        return $this;
    }

    private function index(string $class): void
    {
        if (!class_exists($class)) {
            return;
        }
        $ref = new \ReflectionClass($class);
        if ($ref->isAbstract()) {
            return;
        }

        // 一律用反射得到的真实类名做键：v1 插件经常「文件名 Group.php、类名 group」，
        // 用文件名推出来的名字会让元数据（真实类名）和钩子（文件名）对不上，
        // 面板就表现为「插件在、钩子 0 个」。
        $class = $ref->getName();
        $this->plugins[$class] = self::metadata($ref, $this->logger);
        $this->labels[$class] = $this->plugins[$class]['displayName'] ?: basename(str_replace('\\', '/', $class));
        // 全局关闭的插件仍然要被列出（面板上还要能重新打开），但不注册钩子，
        // 事件分发时就等于不存在；改文件后重载即生效。
        if (!PluginState::isEnabled($class)) {
            $this->plugins[$class]['enabled'] = false;
            return;
        }
        $this->plugins[$class]['enabled'] = true;

        foreach ($ref->getMethods() as $method) {
            foreach ($method->getAttributes() as $attr) {
                $attrClass = $attr->getName();
                // 只认识 v1 的注解族（Rule / Notice / Request），保持零改动
                if (!str_starts_with($attrClass, 'Controller\\Attr\\')) {
                    continue;
                }
                $instance = $attr->newInstance();
                $perm = $instance->permission ?? 'default';
                if ($perm instanceof \Controller\Enum\PermRule) {
                    $perm = $perm->value;
                }
                $this->hooks[$attrClass][] = [
                    'class'      => $class,
                    'func'       => $method->getName(),
                    'match'      => (string)($instance->match ?? '/.*/'),
                    'name'       => $instance->name ?? null,
                    'pattern'    => (string)($instance->pattern ?? 'group'),
                    'permission' => $perm,
                    'plugin'     => $class,
                ];
            }
        }
    }

    /** 从类注释 / composer.json 提取插件元数据 */
    private static function metadata(\ReflectionClass $ref, Logger $logger): array
    {
        $short = $ref->getShortName();
        $meta = [
            'class'       => $ref->getName(),
            'name'        => $short,
            'displayName' => $short,
            'description' => '',
            'author'      => '',
            'version'     => '',
            'lastUpdate'  => '',
            // 插件声明的可订阅项：@subscribe <type> <label>，@subscribeGroup <分组名>
            'subscribes'  => [],
            'subscribeGroup' => '',
        ];

        $doc = (string)$ref->getDocComment();
        if ($doc !== '' && preg_match_all('/@(\w+)\s+(.+)/', $doc, $m, PREG_SET_ORDER)) {
            foreach ($m as [, $key, $value]) {
                $key = strtolower($key);
                $value = trim($value);
                switch ($key) {
                    case 'name':
                        // v1 的 @name 是插件标识；显示名优先用它
                        $meta['name'] = $value;
                        $meta['displayName'] = $value;
                        break;
                    case 'description':
                        $meta['description'] = $value;
                        break;
                    case 'author':
                        $meta['author'] = $value;
                        break;
                    case 'version':
                        $meta['version'] = $value;
                        break;
                    case 'lastupdate':
                        $meta['lastUpdate'] = $value;
                        break;
                    case 'subscribegroup':
                        $meta['subscribeGroup'] = $value;
                        break;
                    case 'subscribe':
                        // 支持 "type 中文名" 与 "type|中文名" 两种写法
                        $parts = preg_split('/[\s|]+/u', $value, 2) ?: [];
                        $type = strtolower(trim((string)($parts[0] ?? '')));
                        if ($type !== '') {
                            $meta['subscribes'][] = [
                                'type'  => $type,
                                'label' => trim((string)($parts[1] ?? '')) ?: $type,
                                'group' => trim((string)$meta['subscribeGroup']),
                            ];
                        }
                        break;
                }
            }
        }
        // 没有 @name 时退回类短名（不是整个类的全名，避免把命名空间显示给用户）
        if ($meta['description'] === '') {
            $meta['description'] = '';
        }
        return $meta;
    }

    /** 某属性类下的全部规则 */
    public function hooks(string $attrClass): array
    {
        $this->load();
        return $this->hooks[$attrClass] ?? [];
    }

    public function allHooks(): array
    {
        $this->load();
        return $this->hooks;
    }

    public function plugins(): array
    {
        $this->load();
        return array_values($this->plugins);
    }

    public function plugin(string $class): ?array
    {
        $this->load();
        if (isset($this->plugins[$class])) {
            return $this->plugins[$class];
        }
        // PHP 类名不区分大小写，查找也保持同样语义
        foreach ($this->plugins as $name => $meta) {
            if (strcasecmp($name, $class) === 0) {
                return $meta;
            }
        }
        return null;
    }

    public function label(string $class): string
    {
        $this->load();
        if (isset($this->labels[$class])) {
            return $this->labels[$class];
        }
        foreach ($this->labels as $name => $label) {
            if (strcasecmp($name, $class) === 0) {
                return $label;
            }
        }
        return $class;
    }

    /** 菜单项（v1 的 Router::getMenu()） */
    public function menu(): array
    {
        $this->load();
        $names = [];
        foreach ($this->hooks as $rules) {
            foreach ($rules as $rule) {
                if (!empty($rule['name'])) {
                    $names[$rule['name']] = true;
                }
            }
        }
        $names = array_keys($names);
        usort($names, static fn($a, $b) => mb_strlen($a) <=> mb_strlen($b));
        return $names;
    }

    public function invalidate(): void
    {
        $this->loaded = false;
    }
}