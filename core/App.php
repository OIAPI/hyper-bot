<?php
namespace Core;

use Core\Http\Router;
use Core\Log\FileHandler;
use Core\Log\Logger;
use Core\Store\Store;

/**
 * 应用容器初始化：把配置 / 存储 / 日志 / 路由装配成一个可运行的应用。
 *
 * 这是唯一需要知道「项目目录结构」的地方，其它内核类都不读全局常量。
 */
final class App {
    private static ?self $instance = null;

    public readonly Container $container;
    public readonly Logger $logger;
    public readonly Router $router;
    public readonly Store $store;
    public readonly array $config;

    /** v2：运行架构 */
    public readonly \Core\Runtime $runtime;
    /** v2：消息订阅 */
    public readonly \Core\Bot\Subscription $subscription;
    /** v2：插件注册表 */
    public readonly \Core\Bot\Registry $registry;
    /** v2：事件分发器 */
    public readonly \Core\Bot\Dispatcher $dispatcher;

    /** 当前 HTTP 响应器（v1 RequestHandler；CLI/WS 场景为 null） */
    private ?object $requestHandler = null;

    private function __construct(?Container $container = null) {
        $this->container = $container ?? Container::instance();
        $root = defined('__root__') ? __root__ : dirname(__DIR__);

        // 时区：Config 里显式配置优先，否则保持 PHP 默认（CLI 默认 UTC）。
        // 不设置的话日志时间戳与用户本地时间会差 8 小时，排查问题极易误判。
        $tz = Config::string('app.timezone');
        if ($tz !== '' && in_array($tz, timezone_identifiers_list(), true)) {
            date_default_timezone_set($tz);
        }

        $this->config = Config::all(false);
        $this->logger = new Logger('app', Config::bool('app.debug', false) ? 10 : 20);
        $this->logger->pushHandler(new FileHandler(($root . '/runtime/log')));
        $this->logger->pushHandler(new \Core\Log\ConsoleHandler(true, PHP_SAPI === 'cli'));
        $this->router = new Router();
        $this->store = $this->buildStore();

        $this->container
            ->bind('config', $this)
            ->bind('logger', $this->logger)
            ->bind('router', $this->router)
            ->bind('store', $this->store)
            ->bind(Logger::class, $this->logger)
            ->bind(Router::class, $this->router)
            ->bind(Store::class, $this->store);

        // ---- v2 业务内核（Bot）----
        // 运行架构：能力检测优先于用户选择，不可用时明确降级而不是静默半残。
        $this->runtime = \Core\Runtime::fromConfig();
        $this->subscription = \Core\Bot\Subscription::fromConfig();
        $this->registry = new \Core\Bot\Registry($this->logger, $this->buildPluginSources(), $this->buildPluginRoots());
        // 注意：这里故意不 load()。
        // Swoole master 在 fork worker 之前就构造 App；如果 master 先加载插件类，
        // 之后 reload worker 也只是 fork 同一份 master 内存，插件代码永远是旧的。
        // 插件加载推迟到 worker 启动（App::loadPlugins）或 PHP 架构首次请求。
        $this->dispatcher = new \Core\Bot\Dispatcher(
            $this->registry,
            $this->subscription,
            $this->logger,
            fn(\Core\Bot\Event $event): bool => $this->groupEnabled($event),
            fn(\Core\Bot\Event $event, string $class): bool => $this->groupPluginEnabled($event, $class),
            fn(\Core\Bot\Event $event): int => $this->userPermission($event),
        );

        $this->container
            ->bind('runtime', $this->runtime)
            ->bind(\Core\Runtime::class, $this->runtime)
            ->bind('subscription', $this->subscription)
            ->bind(\Core\Bot\Subscription::class, $this->subscription)
            ->bind('registry', $this->registry)
            ->bind(\Core\Bot\Registry::class, $this->registry)
            ->bind('dispatcher', $this->dispatcher)
            ->bind(\Core\Bot\Dispatcher::class, $this->dispatcher);

        // 注入响应器：v1 的 RequestHandler 需要它判断是否还能写响应
        if ($this->requestHandler !== null) {
            $this->container->bind('responder', $this->requestHandler);
        }
    }

    public static function boot(?Container $container = null): self {
        if (self::$instance !== null && $container === null) {
            return self::$instance;
        }
        return self::$instance = new self($container);
    }

    public static function current(): ?self {
        return self::$instance;
    }

    /** 绑定当前请求的响应器（Webhook 场景由 RequestHandler 注入） */
    public function withResponder(?object $responder): self {
        $this->requestHandler = $responder;
        if ($responder !== null) {
            $this->container->bind('responder', $responder);
        }
        return $this;
    }

    /**
     * 插件扫描源：内置 app/Plugins + 外部插件目录（runtime/github）。
     *
     * 外部插件的命名空间来自各自 composer.json 的 psr-4，v1 靠 PluginManager 动态加载；
     * 这里保持同样的约定，但把「加载」与「索引」分离。
     */
    private function buildPluginSources(): array {
        $root = defined('__root__') ? __root__ : dirname(__DIR__);
        $sources = ['App\\Plugins\\' => $root . '/app/Plugins'];

        $external = \Core\Config::string('github.localPath', $root . '/runtime/github');
        if ($external !== '' && is_dir($external)) {
            // 外部插件按 PSR-4 声明自身命名空间；未声明则跳过（避免类名猜错）
            foreach (glob(rtrim($external, '/') . '/*/composer.json') ?: [] as $composer) {
                $json = json_decode((string)@file_get_contents($composer), true);
                $psr4 = $json['autoload']['psr-4'] ?? [];
                $dir = dirname($composer);
                foreach ((array)$psr4 as $namespace => $relative) {
                    $sources[(string)$namespace] = rtrim($dir . '/' . ltrim((string)$relative, '/'), '/');
                }
            }
        }
        return $sources;
    }

    /**
     * 注册表需要监视的根目录：新插件落在这里时目录 mtime 会变，
     * Registry 据此自动重扫（不然 Swoole worker 里新插件永远看不见）。
     */
    private function buildPluginRoots(): array
    {
        $root = defined('__root__') ? __root__ : dirname(__DIR__);
        $roots = [$root . '/app/Plugins'];
        $external = \Core\Config::string('github.localPath', $root . '/runtime/github');
        if ($external !== '' && is_dir($external)) {
            $roots[] = rtrim($external, '/');
        }
        return $roots;
    }

    /** 群是否开启（Dispatcher 用） */
    private function groupEnabled(\Core\Bot\Event $event): bool
    {
        $group = $event->groupId();
        if ($group === '') {
            return true;
        }
        $raw = \Core\Bot\AccountScope::get($this->store, "{$group}.info");
        if ($raw === null) {
            // 未登记的群：视为关闭（首次必须在群里用管理命令/面板打开）
            return false;
        }
        $info = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($info) && (int)($info['status'] ?? 0) === 1;
    }

    /** 群级插件开关（Dispatcher 用）：worker[class] === false 表示本群关闭，缺省启用 */
    private function groupPluginEnabled(\Core\Bot\Event $event, string $class): bool
    {
        $group = $event->groupId();
        if ($group === '') {
            return true;
        }
        $raw = \Core\Bot\AccountScope::get($this->store, "{$group}.info");
        $info = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($info)) {
            return true;
        }
        $worker = is_array($info['worker'] ?? null) ? $info['worker'] : [];
        return ($worker[$class] ?? true) !== false;
    }

    /** 用户权限（Dispatcher 用）：super 列表 > 角色 > 存储里的覆盖值 */
    private function userPermission(\Core\Bot\Event $event): int
    {
        $userId = $event->userId();
        if ($userId === '') {
            return 1;
        }

        $super = \Core\Config::array('permission.super', []);
        foreach ($super as $candidate) {
            if ((string)$candidate === $userId) {
                return 15;
            }
        }

        $stored = \Core\Bot\AccountScope::get($this->store, "memberPermission.{$userId}");
        $role = is_scalar($stored) && $stored !== null ? strtolower((string)$stored) : $event->role();

        // 角色 -> 等级由 MemberRoles 统一维护（v1 的 supervisor/director/manager 自动映射）
        return \Core\Bot\MemberRoles::level($role);
    }

    /** 在当前进程加载插件（Swoole 必须在 WorkerStart 里调用，不能在 master 调） */
    public function loadPlugins(bool $force = false): void {
        $this->registry->load($force);
    }

    /** 丢弃注册表并清掉插件文件的 OPcache（改代码后调用；Swoole 还需重启 Worker） */
    public function invalidatePluginCode(): void {
        $this->registry->flushOpcache();
        $this->registry->invalidate();
    }

    public function withLogger(Logger $logger): self {
        $this->container->bind('logger', $logger)->bind(Logger::class, $logger);
        return $this;
    }

    /** MySQL / PostgreSQL / SQLite 持久层；Redis 仅作为可选读缓存 */
    private function buildPdoStore(string $driver, string $root): Store {
        $fileFallback = new \Core\Store\FileStore($root . '/store');
        $dsn = (string)Config::string('store.dsn', '');
        if ($dsn === '') {
            if ($driver === 'sqlite') {
                $dsn = 'sqlite:' . (string)Config::string('store.path', $root . '/store.sqlite');
            } elseif ($driver === 'mysql') {
                $dsn = sprintf(
                    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                    (string)Config::string('store.host', '127.0.0.1'),
                    (int)Config::string('store.port', '3306'),
                    (string)Config::string('store.database', 'hyper'),
                    (string)Config::string('store.charset', 'utf8mb4')
                );
            } elseif ($driver === 'pgsql') {
                $dsn = sprintf(
                    'pgsql:host=%s;port=%d;dbname=%s',
                    (string)Config::string('store.host', '127.0.0.1'),
                    (int)Config::string('store.port', '5432'),
                    (string)Config::string('store.database', 'hyper')
                );
            }
        }
        if ($dsn === '') {
            \Utils\Logger::warning('[store] 选择了数据库存储但未配置 store.dsn / host / database，已回退文件存储');
            return $fileFallback;
        }
        $user = (string)Config::string('store.user', (string)Config::string('store.username', ''));
        $password = (string)Config::string('store.password', (string)Config::string('store.pass', ''));
        $prefix = (string)Config::string('store.prefix', 'hyper_');
        try {
            $pdo = \Core\Store\PdoStore::fromDsn($dsn, $user, $password, [], $prefix);
        } catch (\Throwable $e) {
            \Utils\Logger::error('[store] 数据库连接/建表失败，已回退文件存储：' . $e->getMessage() . '（DSN: ' . preg_replace('/password=[^;]+/i', 'password=***', $dsn) . '）');
            return $fileFallback;
        }

        if (strtolower((string)Config::string('store.cache', '')) === 'redis') {
            $cache = $this->redisCacheStore();
            if ($cache !== null) {
                return new \Core\Store\CachedStore($pdo, $cache, max(1, (int)Config::string('store.cache_ttl', '30')));
            }
            \Utils\Logger::warning('[store] store.cache=redis 但 Redis 不可用，本次直接读写数据库');
        }
        return $pdo;
    }

    /** 仅供 CachedStore 使用的 Redis 缓存连接（失败返回 null，不阻断持久层） */
    private function redisCacheStore(): ?\Core\Store\Store {
        if (!class_exists('Redis')) {
            return null;
        }
        try {
            $client = new \Redis();
            $host = (string)Config::string('Redis.host', '127.0.0.1');
            $port = (int)Config::string('Redis.port', '6379');
            if (defined('Redis::OPT_READ_TIMEOUT')) {
                @$client->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
            }
            if (@$client->connect($host, $port, 0.8) !== true) {
                return null;
            }
            $auth = (string)Config::string('Redis.auth', '');
            if ($auth !== '') {
                try { @$client->auth($auth); } catch (\Throwable) {}
            }
            $select = (int)Config::string('Redis.select', '0');
            if ($select > 0) {
                @$client->select($select);
            }
            if (@$client->ping() === false) {
                return null;
            }
            $factory = function () use ($host, $port): \Redis {
                $c = new \Redis();
                if (defined('Redis::OPT_READ_TIMEOUT')) {
                    @$c->setOption(\Redis::OPT_READ_TIMEOUT, 1.0);
                }
                if (@$c->connect($host, $port, 0.8) !== true) {
                    throw new \RuntimeException('Redis 缓存重连失败');
                }
                $auth = (string)Config::string('Redis.auth', '');
                if ($auth !== '') {
                    @$c->auth($auth);
                }
                $select = (int)Config::string('Redis.select', '0');
                if ($select > 0) {
                    @$c->select($select);
                }
                return $c;
            };
            return new \Core\Store\RedisStore($client, $factory);
        } catch (\Throwable $e) {
            \Utils\Logger::warning('[store] Redis 缓存连接失败：' . $e->getMessage());
            return null;
        }
    }

    private function buildStore(): Store {
        $driver = strtolower(Config::string('store.driver', 'auto'));
        $root = (defined('__runtime__') ? __runtime__ : dirname(__DIR__) . '/runtime');

        // 固定数据持久层：MySQL / PostgreSQL / SQLite（PDO）
        if ($driver === 'auto' && \Core\Config::string('store.dsn', '') !== '') {
            $driver = 'pdo';
        }
        if (in_array($driver, ['mysql', 'pgsql', 'sqlite', 'pdo'], true)) {
            return $this->buildPdoStore($driver, $root);
        }

        if ($driver === 'file') {
            return new \Core\Store\FileStore($root . '/store');
        }
        if ($driver === 'memory') {
            return new \Core\Store\MemoryStore();
        }

        $fileFallback = new \Core\Store\FileStore($root . '/store');

        // auto / redis：能用 Redis 就用，否则退化为文件存储。
        // 关键点：失败原因必须说出来。以前这里把异常全吞掉，结果用户看到的
        // 现象是「Redis 明明能连，面板却不用」——其实是 AUTH / SELECT / PING
        // 某一步失败后静默回退了，日志里一个字都没有。
        if (class_exists('Redis')) {
            $reason = '';
            $client = null;
            try {
                $client = new \Redis();
                $host = Config::string('Redis.host', '127.0.0.1');
                $port = Config::int('Redis.port', 6379);
                if (defined('Redis::OPT_READ_TIMEOUT')) {
                    // 长时间空闲后 TCP 可能假死：给读操作 1.5s 上限，避免整个 worker 卡住
                    @$client->setOption(\Redis::OPT_READ_TIMEOUT, 1.5);
                }
                if (@$client->connect($host, $port, 1.0) !== true) {
                    $reason = '连接 ' . $host . ':' . $port . ' 失败（端口没开 / 防火墙 / 只监听其他地址）';
                } else {
                    $auth = Config::string('Redis.auth', '');
                    if ($auth !== '') {
                        try {
                            @$client->auth($auth);
                        } catch (\Throwable $e) {
                            // 服务器压根没设密码时，AUTH 会报 "no password is set"，
                            // 这种情况不该判定为失败，继续往下走
                            if (stripos($e->getMessage(), 'no password is set') === false) {
                                $reason = 'AUTH 失败：' . $e->getMessage()
                                    . '（Config.ini 的 Redis.auth 与服务器 requirepass 不一致）';
                            }
                        }
                    }
                    if ($reason === '') {
                        $select = Config::int('Redis.select', 0);
                        if ($select > 0) {
                            try {
                                @$client->select($select);
                            } catch (\Throwable $e) {
                                $reason = 'SELECT ' . $select . ' 失败：' . $e->getMessage()
                                    . '（库索引超出范围，或该账号无权限）';
                            }
                        }
                    }
                    if ($reason === '') {
                        try {
                            $pong = @$client->ping();
                            if ($pong === false || $pong === null) {
                                $reason = 'PING 无响应（对端可能不是 Redis，端口被别的服务占了）';
                            }
                        } catch (\Throwable $e) {
                            $reason = 'PING 失败：' . $e->getMessage() . '（对端可能不是 Redis）';
                        }
                    }
                    if ($reason === '') {
                        $factory = function () use ($host, $port): \Redis {
                            $c = new \Redis();
                            if (defined('Redis::OPT_READ_TIMEOUT')) {
                                @$c->setOption(\Redis::OPT_READ_TIMEOUT, 1.5);
                            }
                            if (@$c->connect($host, $port, 1.0) !== true) {
                                throw new \RuntimeException('Redis 重连失败：' . $host . ':' . $port);
                            }
                            $auth = (string)Config::string('Redis.auth', '');
                            if ($auth !== '') {
                                @$c->auth($auth);
                            }
                            $select = (int)Config::string('Redis.select', '0');
                            if ($select > 0) {
                                @$c->select($select);
                            }
                            return $c;
                        };
                        return new \Core\Store\ResilientStore(new \Core\Store\RedisStore($client, $factory), $fileFallback);
                    }
                    @$client->close();
                }
            } catch (\Throwable $e) {
                $reason = $e->getMessage();
            }
            \Utils\Logger::warning('Redis 不可用，已回退文件存储：' . $reason . '；执行 php bin/hyper redis 可逐步排查');
        }

        return $fileFallback;
    }
}