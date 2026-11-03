<?php
namespace Core\Bot;

/**
 * 当前账号上下文。
 *
 * 协议层代码（Adapter / Api / Converter）历来是「读 config 里的 milky.api、qqbot.token」，
 * 多账号后同一个键在不同账号下必须给出不同的值。与其把 account 参数一路透传到几十个
 * 调用点，不如在事件进入时压栈、离开时弹栈：Protocol\Core\Config 读配置前先问这里。
 *
 * 生命周期只覆盖一次事件处理（含它触发的回包），不会泄漏到下一个请求。
 */
final class AccountContext
{
    /** @var array<string, mixed>|null */
    private static ?array $account = null;

    /** @param array<string, mixed>|null $account */
    public static function use(?array $account): void
    {
        self::$account = $account;
    }

    public static function reset(): void
    {
        self::$account = null;
    }

    /** @return array<string, mixed>|null */
    public static function current(): ?array
    {
        return self::$account;
    }

    public static function id(): string
    {
        return (string)(self::$account['id'] ?? '');
    }

    /**
     * 命中返回 [true, value]，未命中返回 [false, null]。
     * 键统一去掉 protocol. 前缀后再比，兼容两种历史写法。
     * @return array{0:bool,1:mixed}
     */
    public static function get(string $key): array
    {
        if (self::$account === null) {
            return [false, null];
        }
        $key = strtolower($key);
        if (str_starts_with($key, 'protocol.')) {
            $key = substr($key, 9);
        }
        $protocol = strtolower((string)(self::$account['protocol'] ?? ''));

        $map = [
            'account.id'   => self::$account['id'] ?? '',
            'account.name' => self::$account['name'] ?? '',
            'account.self_id' => self::$account['self_id'] ?? '',
            $protocol . '.enable'        => (bool)(self::$account['enable'] ?? true),
            $protocol . '.self_id'       => (string)(self::$account['self_id'] ?? ''),
            $protocol . '.api'           => (string)(self::$account['api'] ?? ''),
            $protocol . '.token'         => (string)(self::$account['token'] ?? ''),
            $protocol . '.forward.url'   => (string)(self::$account['forward_url'] ?? ''),
            $protocol . '.forward.token' => (string)(self::$account['forward_token'] ?? ''),
        ];

        if (array_key_exists($key, $map)) {
            return [true, $map[$key]];
        }
        return [false, null];
    }
}
