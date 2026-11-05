<?php
namespace Core\Bot;

/**
 * 当前账号上下文。
 *
 * 协议层代码（Adapter / Api / Converter）历来是「读 config 里的 milky.api、qqbot.token」，
 * 多账号后同一个键在不同账号下必须给出不同的值。与其把 account 参数一路透传到几十个
 * 调用点，不如在事件进入时压栈、离开时弹栈：Protocol\Core\Config 读配置前先问这里。
 *
 * v3.2.24：改成**协程私有**。原来是一个静态属性，Swoole 下多个事件并发处理时：
 *   A 事件 use(账号1) → 发送时 yield → B 事件 use(账号2) → B reset()
 *   → A 继续发送时上下文已经被清掉，回落到 Config.ini 的默认 api/token。
 * 多账号部署里表现为「有时能回、有时不回」，且日志里看不出异常。
 */
final class AccountContext
{
    /** 非协程环境（CLI / 单测）的兜底 */
    private static ?array $account = null;

    private static function inCoroutine(): bool
    {
        return extension_loaded('swoole') && \Swoole\Coroutine::getCid() > 0;
    }

    /** @param array<string, mixed>|null $account */
    public static function use(?array $account): void
    {
        if (self::inCoroutine()) {
            \Swoole\Coroutine::getContext()['__bot_account'] = $account;
            return;
        }
        self::$account = $account;
    }

    public static function reset(): void
    {
        self::use(null);
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        if (self::inCoroutine()) {
            $ctx = \Swoole\Coroutine::getContext();
            return $ctx['__bot_account'] ?? null;
        }
        return self::$account;
    }

    public static function id(): string
    {
        return (string)(self::current()['id'] ?? '');
    }

    /**
     * 命中返回 [true, value]，未命中返回 [false, null]。
     * 键统一去掉 protocol. 前缀后再比，兼容两种历史写法。
     * @return array{0:bool,1:mixed}
     */
    public static function get(string $key): array
    {
        $account = self::current();
        if ($account === null) {
            return [false, null];
        }
        $key = strtolower($key);
        if (str_starts_with($key, 'protocol.')) {
            $key = substr($key, 9);
        }
        $protocol = strtolower((string)($account['protocol'] ?? ''));

        $map = [
            'account.id'   => $account['id'] ?? '',
            'account.name' => $account['name'] ?? '',
            'account.self_id' => $account['self_id'] ?? '',
            $protocol . '.enable'        => (bool)($account['enable'] ?? true),
            $protocol . '.self_id'       => (string)($account['self_id'] ?? ''),
            $protocol . '.api'           => (string)($account['api'] ?? ''),
            $protocol . '.token'         => (string)($account['token'] ?? ''),
            $protocol . '.forward.url'   => (string)($account['forward_url'] ?? ''),
            $protocol . '.forward.token' => (string)($account['forward_token'] ?? ''),
        ];

        if (array_key_exists($key, $map)) {
            return [true, $map[$key]];
        }
        return [false, null];
    }
}
