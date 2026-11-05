<?php
namespace Core\Bot;

use Core\Bot\Subscription;
use Core\Log\Logger;
use Protocol\Core\Adapter;

/**
 * 事件分发器（v2）：替代 v1 的 `Protocol\Core\EventDispatcher` + `Controller\Trigger\*`。
 *
 * v1 链路：
 *   EventDispatcher → 读 [type] 过滤 → MessageLog → Coroutine::create
 *    → Cache::set('param'/'adapter'/'server') → new \Controller\Trigger\Message()
 *     → 再 new 一次 Trigger 类、再读一次 Redis、再扫一次 Router 列表…… 
 * 一条消息最多要经过 4 层构造函数、3 次 Redis、2 次磁盘扫描。
 *
 * v2 链路：
 *   Dispatcher::dispatch(Event, ?Adapter)
 *     → Subscription 过滤（一次，可解释原因）
 *     → Context::run(闭包)（进出自动恢复）
 *     → Hooks 匹配 → 权限判定 → 插件方法调用
 *
 * 职责边界：
 *   - 本类只做「分发+过滤+权限」，不碰协议翻译（那是 Adapter 的事）；
 *   - 业务钩子的返回值被忽略（与 v1 一致：插件靠 Api\* 主动发消息）。
 */
final class Dispatcher
{
    /** 插件只重载一次的注解族 */
    public const HOOK_MESSAGE = 'Controller\\Attr\\Rule';
    public const HOOK_NOTICE  = 'Controller\\Attr\\Notice';
    public const HOOK_REQUEST = 'Controller\\Attr\\Request';

    /**
     * 权限等级（严格沿用 v1 的编号，避免行为漂移）。
     *
     * v1 的等级是 1..8 的**序号**，不是权限位：
     * super=8 > supervisor=7 > owner=6 > director=5 > manager=4 > admin=3 > editor=2 > default=1。
     *
     * 注意 v1 的 `convertPermissionToLevel()` 用 match 做 === 比较，
     * 而规则里常写 `#\Controller\Attr\Rule(permission: 3)`（int），
     * 也有的写 `PermRule::SUPER`（enum，=== 永远不匹配 'super'）—— 后者是 v1 的隐藏 bug。
     * v2 在这里把 enum 也正确解析，属于修正而非兼容旧缺陷。
     */
    private array $permissionLevels = [
        'default'    => 1,
        'editor'     => 2,
        'admin'      => 3,
        'manager'    => 4,
        'director'   => 5,
        'owner'      => 6,
        'supervisor' => 7,
        'super'      => 8,
    ];

    public function __construct(
        private readonly Registry $registry,
        private readonly Subscription $subscription,
        private readonly Logger $logger,
        /** 群配置：判断群是否开启 */
        private readonly ?\Closure $groupConfig = null,
        /** 群级插件开关：fn(Event $event, string $class): bool，worker 里 false 表示本群关闭 */
        private readonly ?\Closure $groupPluginConfig = null,
        /** 用户权限查询 */
        private readonly ?\Closure $userPermission = null,
        /** 执行器（默认同步执行；Swoole 下由 Runtime 换成协程执行） */
        private ?\Closure $runner = null,
    ) {}

    public function subscription(): Subscription
    {
        return $this->subscription;
    }

    public function setRunner(?\Closure $runner): void
    {
        $this->runner = $runner;
    }

    /**
     * 分发一条事件。
     *
     * @param Event $event 标准 OneBot11 事件
     * @param Adapter|null $adapter 事件来源（决定插件发消息的去向）
     * @return array{accepted:bool,reason?:string,matched:int,executed:int}
     */
    public function dispatch(Event $event, ?Adapter $adapter = null): array
    {
        $reason = $this->subscription->rejectReason($event);
        if ($reason !== null) {
            $this->logger->debug('event rejected', [
                'label'  => $event->label(),
                'reason' => $reason,
            ]);
            return ['accepted' => false, 'reason' => $reason, 'matched' => 0, 'executed' => 0];
        }

        // 响应器：Webhook 场景可为 null（响应由 HTTP 层直接返回）
        return Context::run(new Context($event, $adapter), function () use ($event, $adapter): array {
            $rules = $this->matchRules($event);
            if ($rules === []) {
                return ['accepted' => true, 'matched' => 0, 'executed' => 0];
            }

            $executed = 0;
            $permission = $this->userPermissionLevel($event);
            $groupEnabled = $this->groupEnabled($event);

            foreach ($rules as $rule) {
                if ($this->groupPluginConfig !== null && $event->groupId() !== '') {
                    $class = (string)($rule['class'] ?? '');
                    if ($class !== '' && !($this->groupPluginConfig)($event, $class)) {
                        $this->logger->debug('hook skipped: group plugin off', [
                            'plugin' => $class,
                            'group'  => $event->groupId(),
                        ]);
                        continue;
                    }
                }
                if (!$this->allowed($rule, $event, $permission, $groupEnabled)) {
                    continue;
                }
                $this->runRule($rule, $event, $adapter);
                $executed++;
            }

            return ['accepted' => true, 'matched' => count($rules), 'executed' => $executed];
        });
    }

    /** 按 post_type 选出候选规则并做正则匹配 */
    private function matchRules(Event $event): array
    {
        $hooks = $this->registry->hooks(match ($event->postType()) {
            'message'    => self::HOOK_MESSAGE,
            'notice'     => self::HOOK_NOTICE,
            'request'    => self::HOOK_REQUEST,
            default      => '',
        });
        if ($hooks === []) {
            return [];
        }

        $candidates = [];
        foreach ($hooks as $rule) {
            if ($event->postType() === 'message') {
                if (!$this->matchesRegex($rule['match'], $event->rawMessage())) {
                    continue;
                }
            } else {
                // 通知/请求：v1 用完整匹配 notice_type / request_type
                $subject = $event->postType() === 'notice' ? $event->noticeType() : $event->requestType();
                if (!$this->matchesRegex($rule['match'], $subject)) {
                    continue;
                }
            }
            $candidates[] = $rule;
        }
        return $candidates;
    }

    private function matchesRegex(string $regex, string $subject): bool
    {
        if ($regex === '') {
            return true;
        }
        // v1 插件常用 #[Notice('group_increase')] 这种「原始类型名」写法，
        // 它不是完整正则（缺分隔符），直接 preg_match 会报 Internal error。
        // 这类值按精确匹配处理；只有以分隔符开头的才当正则。
        $first = $regex[0];
        if (preg_match('/^[A-Za-z0-9_\\\\]/', $first) === 1 || ord($first) > 127) {
            return $subject === $regex;
        }
        $result = @preg_match($regex, $subject);
        if ($result === false) {
            $this->logger->error('invalid hook regex', ['regex' => $regex, 'error' => preg_last_error_msg()]);
            return false;
        }
        return $result === 1;
    }

    /**
     * 权限 + 群状态判定（沿用 v1 语义，避免行为漂移）
     *
     * v1 规则：群关闭时只有权限 >= 3 的能触发；用户权限低于规则要求则拒绝。
     */
    private function allowed(array $rule, Event $event, int $permission, bool $groupEnabled): bool
    {
        $required = $this->level($rule['permission']);

        if (!$groupEnabled && $permission < 3) {
            $this->logger->debug('hook denied: group disabled', [
                'plugin' => $rule['plugin'],
                'func'   => $rule['func'],
            ]);
            return false;
        }

        if ($permission < $required) {
            $this->logger->debug('hook denied: permission', [
                'plugin'   => $rule['plugin'],
                'func'     => $rule['func'],
                'have'     => $permission,
                'require'  => $required,
            ]);
            return false;
        }

        return true;
    }

    private function level(mixed $permission): int
    {
        if (is_int($permission)) {
            return $permission;
        }
        if ($permission instanceof \Controller\Enum\PermRule) {
            return $permission->getValue();
        }
        $key = strtolower(trim((string)$permission));
        return $this->permissionLevels[$key] ?? 1;
    }

    private function userPermissionLevel(Event $event): int
    {
        if ($this->userPermission !== null) {
            $value = ($this->userPermission)($event);
            if (is_int($value) || is_string($value)) {
                return $this->level($value);
            }
        }
        // 没有权限来源时：群主/管理员至少拿到 admin
        return match ($event->role()) {
            'owner'  => 6,
            'admin'  => 3,
            default  => 1,
        };
    }

    private function groupEnabled(Event $event): bool
    {
        if ($event->groupId() === '') {
            return true; // 私聊不受群开关限制
        }
        if ($this->groupConfig !== null) {
            return (bool)(($this->groupConfig)($event));
        }
        return true;
    }

    /**
     * 执行单条规则。
     *
     * 插件方法签名为无参（v1 也是这样，靠 $this->input / $this->api / $this->Redis
     * 从上下文取数据）。这里保持兼容：实例化时不传参，方法调用也不传参。
     */
    private function runRule(array $rule, Event $event, ?Adapter $adapter): void
    {
        $runner = $this->runner ?? static fn(callable $fn) => $fn();

        $runner(function () use ($rule, $event): void {
            try {
                $class = $rule['class'];
                if (!class_exists($class)) {
                    $this->logger->error('hook class missing', ['class' => $class]);
                    return;
                }
                $plugin = new $class($rule);
                if (!method_exists($plugin, $rule['func'])) {
                    $this->logger->error('hook method missing', ['class' => $class, 'func' => $rule['func']]);
                    return;
                }
                $plugin->{$rule['func']}();
                $this->logger->debug('hook executed', ['plugin' => $class, 'func' => $rule['func']]);
            } catch (\Throwable $e) {
                $this->logger->error('hook failed', [
                    'class' => $rule['class'],
                    'func'  => $rule['func'],
                    'error' => $e->getMessage(),
                    'file'  => $e->getFile() . ':' . $e->getLine(),
                ]);
            }
        });
    }
}
