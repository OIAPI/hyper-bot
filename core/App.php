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
        $this->registry = new \Core\Bot\Registry($this->logger, $this->buildPluginSources());
        $this->dispatcher = new \Core\Bot\Dispatcher(
            $this->registry,
            $this->subscription,
            $this->logger,
            fn(\Core\Bot\Event $event): bool => $this->groupEnabled($event),
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

    /** 群是否开启（Dispatcher 用） */
    private function groupEnabled(\Core\Bot\Event $event): bool
    {
        $group = $event->groupId();
        if ($group === '') {
            return true;
        }
        $raw = $this->store->get("{$group}.info");
        if ($raw === null) {
            // 未登记的群：视为关闭（与 v1 一致：需要先 initGroupInfo）
            return false;
        }
        $info = is_string($raw) ? json_decode($raw, true) : $raw;
        return is_array($info) && (int)($info['status'] ?? 0) === 1;
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

        $key = "memberPermission.{$userId}";
        $stored = $this->store->get($key);
        $role = is_scalar($stored) && $stored !== null ? strtolower((string)$stored) : $event->role();

        return match ($role) {
            'super'      => 15,
            'supervisor' => 7,
            'owner'      => 6,
            'director'   => 5,
            'manager'    => 4,
            'admin'      => 3,
            'editor'     => 2,
            default      => 1,
        };
    }

    public function withLogger(Logger $logger): self {
        $this->container->bind('logger', $logger)->bind(Logger::class, $logger);
        return $this;
    }

    private function buildStore(): Store {
        $driver = strtolower(Config::string('store.driver', 'auto'));
        $root = (defined('__runtime__') ? __runtime__ : dirname(__DIR__) . '/runtime');

        if ($driver === 'file') {
            return new \Core\Store\FileStore($root . '/store');
        }
        if ($driver === 'memory') {
            return new \Core\Store\MemoryStore();
        }

        // auto / redis：能用 Redis 就用，否则退化为文件存储
        if (class_exists('Redis')) {
            try {
                $client = new \Redis();
                $host = Config::string('Redis.host', '127.0.0.1');
                $port = Config::int('Redis.port', 6379);
                $connected = @$client->connect($host, $port, 1.0);
                if ($connected === true) {
                    $auth = Config::string('Redis.auth', '');
                    if ($auth !== '') {
                        @$client->auth($auth);
                    }
                    $select = Config::int('Redis.select', 0);
                    if ($select > 0) {
                        @$client->select($select);
                    }
                    return new \Core\Store\RedisStore($client);
                }
            } catch (\Throwable) {
                // 落到文件存储
            }
        }

        return new \Core\Store\FileStore($root . '/store');
    }
}
