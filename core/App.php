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

    private function __construct(?Container $container = null) {
        $this->container = $container ?? Container::instance();
        $root = defined('__root__') ? __root__ : dirname(__DIR__);

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
