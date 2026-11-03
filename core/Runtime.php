<?php
namespace Core;

/**
 * 运行架构选择（v2）：一套代码，三种跑法。
 *
 * v1 只有一种跑法：Swoole。没有 swoole 扩展就连 WebUI 都起不来
 * （只能靠 bin/webui-serve.php 走 PHP 内置服务器绕过）。
 *
 * v2 引入「运行架构」概念，可在 Config.ini / WebUI 里选择：
 *
 *   swoole  —— 生产形态。常驻进程、协程并发、WebSocket 服务端（OneBot 反向 WS / Milky WS）、
 *              定时任务、协议常驻客户端（正向 WS / Gateway / SSE）。
 *   php     —— 单进程形态。PHP 内置服务器 + 轮询 worker，适合：
 *              · 没装 swoole 的机器（共享主机 / Termux / 容器最小镜像）
 *              · 只想跑 HTTP Webhook 接入（不需要 WS 服务端）
 *              · 本地调试（改代码即生效，不用 reload）
 *   webui   —— 只跑 WebUI（管理面板），不处理任何机器人事件。用于：
 *              · 只想改配置 / 看日志
 *              · 与业务进程分离部署（WebUI 只读 Store，不抢事件）
 *
 * 关键设计：**能力检测优先于用户选择**。
 * 用户选 swoole 但环境没有扩展时，不报错退出，而是明确降级并写日志，
 * 避免「配置写了 swoole、实际静默跑成半残」——v1 就踩过这个坑。
 */
final class Runtime
{
    public const SWOOLE = 'swoole';
    public const PHP = 'php';
    public const WEBUI = 'webui';

    /** @var list<string> 已选择的架构所固有的能力缺陷（用于启动横幅与 WebUI 提示） */
    private array $limitations = [];

    public function __construct(
        public readonly string $mode,
        /** 用户原本的诉求（可能因环境不足而降级） */
        public readonly string $requested,
        public readonly bool $degraded,
        public readonly string $reason = '',
    ) {}

    /** 三种架构的元信息，供 WebUI 渲染选择卡片 */
    public static function catalog(): array
    {
        return [
            self::SWOOLE => [
                'label'       => 'Swoole 常驻',
                'summary'     => '生产形态：协程并发、WebSocket 服务端、定时任务',
                'requires'    => 'ext-swoole',
                'available'   => extension_loaded('swoole'),
                'capabilities'=> [
                    'HTTP 服务（WebUI + 协议 Webhook）',
                    'OneBot 反向 WebSocket 服务端',
                    'OneBot 正向 WebSocket 客户端',
                    'Milky WS / SSE 事件接入',
                    'QQ 官方机器人 Gateway 长连接',
                    '定时任务 / 延迟任务',
                ],
            ],
            self::PHP => [
                'label'       => 'PHP 内置服务器',
                'summary'     => '单进程：无需 Swoole，只跑 HTTP 接入与 WebUI',
                'requires'    => '无',
                'available'   => true,
                'capabilities'=> [
                    'HTTP 服务（WebUI + 协议 Webhook）',
                    'Milky / QQ 官方机器人 Webhook 接入',
                    'OneBot HTTP 推送接入',
                    '定时任务（轮询 worker，精度约 1 秒）',
                ],
                'limitations' => [
                    '不支持 WebSocket 服务端（OneBot 反向 WS 不可用）',
                    '不支持常驻 WS/Gateway 客户端（改为 Webhook 接入）',
                    '单进程，事件串行处理',
                ],
            ],
            self::WEBUI => [
                'label'       => '仅 WebUI',
                'summary'     => '只跑管理面板，不处理机器人事件',
                'requires'    => '无',
                'available'   => true,
                'capabilities'=> [
                    '管理面板（群/插件/协议/日志/配置）',
                    '配置读写、日志查看',
                ],
                'limitations' => [
                    '不接收任何协议事件',
                    '不执行插件逻辑',
                    'Webhook 端点返回 503（避免协议端以为投递成功）',
                ],
            ],
        ];
    }

    /**
     * 解析运行架构。
     *
     * 优先级：命令行 --arch > 环境变量 HYPER_ARCH > Config.ini [runtime] arch > swoole
     *
     * 默认 swoole：没装 ext-swoole 时自动降级为 php 内置服务器并在启动横幅里说明原因，
     * 所以「默认值」不会让缺扩展的机器起不来。显式写 auto 才按扩展检测，
     * 显式写 php / webui 则完全按指定值走。
     */
    public static function detect(?string $forced = null, ?string $config = null): self
    {
        $requested = $forced ?? $config ?? '';
        $requested = strtolower(trim($requested));
        if ($requested === '') {
            $requested = self::SWOOLE;
        } elseif ($requested === 'auto') {
            $requested = extension_loaded('swoole') ? self::SWOOLE : self::PHP;
        }

        $valid = [self::SWOOLE, self::PHP, self::WEBUI];
        if (!in_array($requested, $valid, true)) {
            return new self(self::PHP, $requested, true, "未知架构 \"{$requested}\"，已回落 php");
        }

        // 能力检测优先：选了 swoole 但没有扩展 → 降级
        if ($requested === self::SWOOLE && !extension_loaded('swoole')) {
            return new self(self::PHP, self::SWOOLE, true, '未安装 ext-swoole，已降级为 php 内置服务器');
        }

        return new self($requested, $requested, false);
    }

    public static function fromConfig(): self
    {
        $forced = getenv('HYPER_ARCH');
        if (!is_string($forced) || $forced === '') {
            $forced = null;
        }
        return self::detect($forced, Config::string('runtime.arch', 'swoole'));
    }

    /** 是否是常驻多协程架构 */
    public function isSwoole(): bool
    {
        return $this->mode === self::SWOOLE;
    }

    /** 是否处理机器人事件（webui 模式不处理） */
    public function handlesEvents(): bool
    {
        return $this->mode !== self::WEBUI;
    }

    /** 是否能提供 WebSocket 服务端（OneBot 反向 WS / Milky WS） */
    public function supportsWebSocketServer(): bool
    {
        return $this->isSwoole();
    }

    /** 是否能运行常驻客户端（正向 WS / Gateway / SSE） */
    public function supportsPersistentClients(): bool
    {
        return $this->isSwoole();
    }

    public function limitations(): array
    {
        $catalog = self::catalog();
        return $catalog[$this->mode]['limitations'] ?? [];
    }

    public function label(): string
    {
        return self::catalog()[$this->mode]['label'] ?? $this->mode;
    }

    /** 启动横幅用 */
    public function describe(): string
    {
        $text = $this->label() . "（{$this->mode}）";
        if ($this->degraded) {
            $text .= " ← 请求 {$this->requested}，因 {$this->reason} 降级";
        }
        return $text;
    }
}