<?php
namespace Core\Bot;

use Core\Container;
use Protocol\Core\Adapter;

/**
 * 事件上下文（v2）：替代 v1 的 `Utils\Cache::set('param'|'adapter'|'api'|'server')` 约定。
 *
 * v1 的问题：上下文靠「协程 Context 里的魔法键名」传递，写入点有 6 个
 * （EventDispatcher / RequestHandler×2 / 三个 Trigger / Context），
 * 每漏一处就是一个静默 bug——例如插件的 sendMsg 会静默回落到旧 HTTP 协议端。
 *
 * v2 的做法：
 *   - 上下文是一个**不可变对象**，只能整体替换，不能单字段丢三落四；
 *   - 用 `Context::run()` 包裹执行，进出自动压栈/出栈，子协程/嵌套调用都不会串号；
 *   - 在协程环境里额外存一份到 Swoole 协程上下文，保证并发事件不互相覆盖。
 */
final class Context
{
    private static ?self $current = null;

    /** 非协程环境的兜底栈（CLI / 单测） */
    private static array $stack = [];

    public function __construct(
        public readonly Event $event,
        /** 事件来源适配器；null 表示无入站事件（定时任务/主动推送） */
        public readonly ?Adapter $adapter = null,
        /** 响应器（仅 HTTP Webhook 场景有用，WS/常驻事件为 null） */
        public readonly mixed $responder = null,
    ) {}

    public static function current(): ?self
    {
        // 以栈顶为准（单一真相源）。$current 只是 run() 期间的快速路径，
        // 但 bind() 会直接改写栈顶，两者若不同步就会出现「绑定成功但读不到」。
        $stack = self::stack();
        return $stack === [] ? null : $stack[count($stack) - 1];
    }

    public static function event(): ?Event
    {
        return self::current()?->event;
    }

    public static function adapter(): ?Adapter
    {
        return self::current()?->adapter;
    }

    /**
     * 在上下文内执行一段逻辑。
     *
     * 关键点：无论 callback 抛异常还是提前 return，退出时一定恢复外层上下文，
     * 因此嵌套调用（hook 里再触发一次分发）不会污染调用方。
     */
    public static function run(self $context, callable $callback): mixed
    {
        $stack = self::stack();
        $stack[] = $context;
        self::writeStack($stack);

        try {
            return $callback($context);
        } finally {
            // 只出栈，不碰 $current：栈是唯一真相源，
            // 同时恢复 $current 会引入第二份状态，容易出现「读到的和栈里不是同一个」。
            $stack = self::stack();
            array_pop($stack);
            self::writeStack($stack);
            self::$current = $stack === [] ? null : $stack[count($stack) - 1];
        }
    }

    /** 没有入站事件时的空上下文（定时任务 / CLI / 主动推送） */
    public static function empty(?Adapter $adapter = null): self
    {
        return new self(Event::fromArray(['post_type' => '', 'time' => time()]), $adapter);
    }

    /**
     * 只绑定适配器（保留 v1 `Protocol\Core\Context::bind()` 的语义）。
     *
     * 用于「没有入站事件、但需要指定出站后端」的场景：单测、CLI 主动推送、
     * 以及协议层在分发前预置适配器。语义与 v1 一致：
     *   - 已有上下文 → 替换其 adapter（保留原事件）
     *   - 没有上下文 → 建立空上下文
     * 调用方负责在结束后 `clear()`，否则残留会影响后续无上下文场景。
     */
    public static function bind(?Adapter $adapter): void
    {
        // 替换栈顶而不是压栈：bind 的语义是「当前上下文的出站后端是它」，
        // 不是「进入一层新上下文」。压栈会让 clear() 之后仍残留一层。
        $stack = self::stack();
        $current = $stack === [] ? null : $stack[count($stack) - 1];
        $event = $current?->event ?? Event::fromArray(['post_type' => '', 'time' => time()]);
        $replacement = new self($event, $adapter, $current?->responder);

        if ($stack === []) {
            $stack[] = $replacement;
        } else {
            $stack[count($stack) - 1] = $replacement;
        }
        self::writeStack($stack);
    }



    /** 当前上下文的事件（没有则返回空事件，避免调用方到处判空） */
    public static function eventOrDefault(): Event
    {
        return self::event() ?? Event::fromArray(['post_type' => '']);
    }

    // ---------------- 协程私有存储 ----------------

    /** @return list<self> */
    private static function stack(): array
    {
        if (!extension_loaded('swoole') || \Swoole\Coroutine::getCid() <= 0) {
            return self::$stack;
        }
        $ctx = \Swoole\Coroutine::getContext();
        /** @var array $list */
        $list = $ctx['__bot_ctx'] ?? [];
        return $list;
    }

    /** @param list<self> $stack */
    private static function writeStack(array $stack): void
    {
        if (!extension_loaded('swoole') || \Swoole\Coroutine::getCid() <= 0) {
            self::$stack = $stack;
            return;
        }
        $ctx = \Swoole\Coroutine::getContext();
        $ctx['__bot_ctx'] = $stack;
    }

    /**
     * 供 Swoole 协程派生使用：把当前上下文的「栈」快照下来，
     * 在子协程开头恢复。这是 v1 里 Cache::snapshot()/restore() 的替代品，
     * 但不再需要调用方逐个字段搬运。
     */
    public static function snapshot(): array
    {
        return self::stack();
    }

    public static function restore(array $snapshot): void
    {
        self::writeStack($snapshot);
        self::$current = $snapshot === [] ? null : $snapshot[count($snapshot) - 1];
    }

    /** 仅用于测试：清空 */
    public static function clear(): void
    {
        self::$current = null;
        self::$stack = [];
        self::writeStack([]);
    }
}
