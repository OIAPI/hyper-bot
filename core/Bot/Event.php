<?php
namespace Core\Bot;

/**
 * 事件值对象：框架内部唯一的事件形态。
 *
 * 所有协议（OneBot11 / Milky / QQ 官方机器人）的事件都先转成 OneBot11 结构，
 * 再包成 Event。这样插件永远只面对一种形态，「换协议不用改业务」才成立。
 *
 * v1 里事件就是一个裸 \stdClass，散落在 Cache::get('param') 里，字段靠 ?? 兜底。
 * 这里收敛成显式访问器：拼错字段名会立刻暴露，而不是静默拿到 null。
 */
final class Event
{
    /** @param array<string,mixed> $data 标准 OneBot11 事件（已补齐公共字段） */
    public function __construct(public readonly array $data) {}

    public static function fromArray(array $data): self
    {
        $data += [
            'time'        => time(),
            'self_id'     => 0,
            'post_type'   => '',
            'raw_message' => '',
        ];
        return new self($data);
    }

    public function toArray(): array
    {
        return $this->data;
    }

    /** 读取任意字段（不存在返回默认值） */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** post_type：message / notice / request / meta_event */
    public function postType(): string
    {
        return strtolower(trim((string)($this->data['post_type'] ?? '')));
    }

    /** message_type：group / private（Milky 私聊会被转换层写成 private） */
    public function messageType(): string
    {
        $type = strtolower(trim((string)($this->data['message_type'] ?? '')));
        return match ($type) {
            'protected', 'c2c' => 'private',
            default => $type,
        };
    }

    public function noticeType(): string
    {
        return strtolower(trim((string)($this->data['notice_type'] ?? '')));
    }

    public function requestType(): string
    {
        return strtolower(trim((string)($this->data['request_type'] ?? '')));
    }

    public function metaEventType(): string
    {
        return strtolower(trim((string)($this->data['meta_event_type'] ?? '')));
    }

    public function subType(): string
    {
        return strtolower(trim((string)($this->data['sub_type'] ?? '')));
    }

    /**
     * 机器人自身 ID。
     * QQ 官方机器人没有 QQ 号，转换层用 app_id 派生稳定哈希，这里统一按字符串处理。
     */
    public function selfId(): string
    {
        return trim((string)($this->data['self_id'] ?? ''));
    }

    /** 触发者 ID：QQ 号或官方机器人 OpenID（字符串） */
    public function userId(): string
    {
        $uid = $this->data['user_id'] ?? ($this->data['operator_id'] ?? '');
        return trim((string)$uid);
    }

    public function operatorId(): string
    {
        return trim((string)($this->data['operator_id'] ?? ''));
    }

    /** 群号或 group_openid */
    public function groupId(): string
    {
        return trim((string)($this->data['group_id'] ?? ''));
    }

    public function rawMessage(): string
    {
        $raw = $this->data['raw_message'] ?? '';
        return is_string($raw) ? trim($raw) : '';
    }

    public function comment(): string
    {
        return (string)($this->data['comment'] ?? '');
    }

    public function isGroup(): bool
    {
        return $this->messageType() === 'group';
    }

    public function isPrivate(): bool
    {
        return $this->messageType() === 'private';
    }

    /** 发送者信息（昵称/群名片/角色） */
    public function sender(): array
    {
        $sender = $this->data['sender'] ?? [];
        return is_array($sender) ? $sender : [];
    }

    public function nickname(): string
    {
        $sender = $this->sender();
        return (string)($sender['card'] ?? ($sender['nickname'] ?? ''));
    }

    public function role(): string
    {
        return strtolower((string)($this->sender()['role'] ?? ''));
    }

    /** 是否机器人自己发的 */
    public function isSelf(): bool
    {
        $uid = $this->userId();
        return $uid !== '' && $uid === $this->selfId();
    }

    /**
     * 细分类型标识，用于订阅配置与日志：post_type[.子类型]
     * 例：message.group / notice.group_increase / request.friend
     */
    public function label(): string
    {
        $post = $this->postType();
        $sub = match ($post) {
            'message'    => $this->messageType(),
            'notice'     => $this->noticeType(),
            'request'    => $this->requestType(),
            'meta_event' => $this->metaEventType(),
            default      => '',
        };
        return $sub === '' ? $post : "{$post}.{$sub}";
    }

    /** 用于日志的简短描述 */
    public function summary(): string
    {
        return sprintf(
            '%s | self=%s user=%s group=%s | %s',
            $this->label(),
            $this->selfId(),
            $this->userId(),
            $this->groupId() !== '' ? $this->groupId() : '-',
            mb_substr($this->rawMessage(), 0, 60)
        );
    }
}
