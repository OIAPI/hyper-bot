<?php
namespace Core;

use Core\Bot\Registry;
use Core\Bot\Subscription;
use Core\Log\ConsoleHandler;
use Core\Log\FileHandler;
use Core\Log\Logger;

/**
 * 启动引导（v2）：所有入口（HTTP / WS / worker / CLI）共用的一段初始化。
 *
 * v1 的启动逻辑散落在 `Controller\Server\HttpServer::__construct` 与
 * `bin/webui-serve.php` 里，各写一份，且都假设有 Swoole：
 * `new \Swoole\WebSocket\Server`、`Timer::clearAll`、`Runtime::enableCoroutine`……
 * 没有扩展时直接 fatal，连「打印一条友好提示」的机会都没有。
 *
 * v2 把「启动」与「跑起来」分离：
 *   1. Bootstrap::run()：加载配置、初始化容器、注册协议客户端钩子、打印横幅；
 *   2. 再由各入口（bin/hyper、Swoole server、PHP dev server）按架构选择执行方式。
 */
final class Bootstrap
{
    private static bool $booted = false;

    /**
     * 初始化应用。
     *
     * @param array{arch?:string,quiet?:bool} $options
     * @return App
     */
    public static function run(array $options = []): App
    {
        if (self::$booted) {
            return App::boot();
        }
        self::$booted = true;

        // 1. 解析运行架构（能力检测优先于用户选择）
        $arch = isset($options['arch']) ? (string)$options['arch'] : null;
        if ($arch !== null) {
            // 命令行优先：写进环境让 Core\App 也读到同一结论
            putenv('HYPER_ARCH=' . $arch);
        }

        // 2. 内核启动（配置 / 日志 / 存储 / 路由 / Bot 服务）
        $app = App::boot();

        // 3. 运行时约束：PHP 模式下不支持的配置项要显式告警，而不是静默忽略
        self::validateRuntimeLimits($app);

        // 4. 启动横幅（除非静默）
        if (empty($options['quiet'])) {
            self::banner($app);
        }

        return $app;
    }

    /**
     * 校验「当前架构 + 当前配置」是否互相矛盾。
     * 这类矛盾就是 v1 里「配置写了功能、实际没生效」的根源，必须在启动时喊出来。
     */
    private static function validateRuntimeLimits(App $app): void
    {
        $runtime = $app->runtime;
        $logger = $app->logger;

        if ($runtime->degraded) {
            $logger->warning('[启动] ' . $runtime->describe());
        }

        foreach ($runtime->limitations() as $limit) {
            $logger->debug('[启动] 架构限制：' . $limit);
        }

        if (!$runtime->supportsWebSocketServer()) {
            if (Config::bool('protocol.onebot.reverse.enable', false)) {
                $logger->warning('[启动] OneBot 反向 WS 已启用，但当前架构不支持 WebSocket 服务端 —— 该功能不会生效。请改用 swoole 架构。');
            }
            if (Config::bool('protocol.milky.ws.server_path', '') !== '' && Config::bool('protocol.milky.enable', false)) {
                $logger->debug('[启动] Milky 事件接入请使用 webhook 模式（当前架构无法提供 /milky/event WS 端点）');
            }
        }

        if (!$runtime->supportsPersistentClients()) {
            if (Config::bool('protocol.qqbot.enable', false) && Config::string('protocol.qqbot.event.mode', 'ws') === 'ws') {
                $logger->warning('[启动] QQ 官方机器人事件模式为 ws，但当前架构不支持常驻 Gateway —— 请改为 event.mode = webhook');
            }
            if (Config::bool('protocol.onebot.forward.enable', false)) {
                $logger->warning('[启动] OneBot 正向 WS 已启用，但当前架构不支持常驻 WS 客户端 —— 请改用 HTTP 推送或切换 swoole 架构');
            }
            if (Config::bool('protocol.milky.enable', false) && in_array(Config::string('protocol.milky.event.mode', 'webhook'), ['ws', 'sse'], true)) {
                $logger->warning('[启动] Milky 事件模式为 ' . Config::string('protocol.milky.event.mode', 'webhook') . '，但当前架构不支持常驻连接 —— 请改为 webhook');
            }
        }

        // 订阅配置合理性
        $subscription = $app->subscription;
        if (!$subscription->message && !$subscription->notice && !$subscription->request && !$subscription->metaEvent) {
            $logger->warning('[启动] 消息订阅中所有类型都已关闭，将不处理任何事件');
        }
        if ($subscription->notice && $subscription->noticeTypes === []) {
            $logger->warning('[启动] 已开启 notice 订阅，但没有启用任何具体通知类型（见 [subscription] notice_types）');
        }
    }

    /** 启动横幅 */
    public static function banner(App $app): void
    {
        $version = Version::current();
        $runtime = $app->runtime;
        $lines = [
            "Hyper Bot {$version}",
            str_repeat('─', 52),
            "运行架构  {$runtime->describe()}",
            "存储      " . basename(str_replace('\\', '/', get_class($app->store))),
            "订阅      " . self::describeSubscription($app->subscription),
            "插件      " . count($app->registry->plugins()) . ' 个',
            "配置      " . Config::path(),
        ];
        echo PHP_EOL;
        foreach ($lines as $line) {
            echo '  ' . $line . PHP_EOL;
        }
        echo '  ' . str_repeat('─', 52) . PHP_EOL . PHP_EOL;
    }

    private static function describeSubscription(Subscription $subscription): string
    {
        $parts = [];
        if ($subscription->message) {
            $parts[] = "message({$subscription->messageScope})";
        }
        if ($subscription->notice) {
            $parts[] = 'notice(' . ($subscription->noticeTypes === [] ? '无类型' : implode('/', $subscription->noticeTypes)) . ')';
        }
        if ($subscription->request) {
            $parts[] = 'request';
        }
        if ($subscription->metaEvent) {
            $parts[] = 'meta_event';
        }
        return $parts === [] ? '（全部关闭）' : implode(' + ', $parts);
    }
}
