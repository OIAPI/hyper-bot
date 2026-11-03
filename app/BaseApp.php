<?php
namespace App;

use Core\Bot\Context;
use Core\Bot\Event;
use Core\Store\StoreBridge;
use Controller\Api;
use Controller\Enum\PermRule;
use Utils\Logger;

/**
 * 插件基类（v2）：插件作者唯一需要接触的父类。
 *
 * 和老版本相比，插件**写法完全不变**：
 *
 *   class demo extends \App\BaseApp {
 *       #[\Controller\Attr\Rule('/^hello$/')]
 *       public function hello() {
 *           return $this->api->message->sendMsg(group: $this->group, message: 'hi');
 *       }
 *   }
 *
 * 变的是这些属性的来源：
 *
 *   $this->input    ← Context 里的 Event（不再是 Cache::get('param') 的裸 stdClass）
 *   $this->api      ← Controller\Api（容器委托，支持无 Redis 环境）
 *   $this->Redis    ← StoreBridge（长得像 Redis，实际是 Core\Store）
 *   $this->qq/group ← Event 的类型化访问器（官方机器人的 OpenID 是字符串，v1 会崩）
 *
 * v1 的致命点：
 *   - 构造函数里 `new \Redis(); pconnect()` → 没装扩展直接 fatal；
 *   - `$this->redisSelect = Env::get('Redis.select')` 但没 select，读写落到 0 库；
 *   - `$this->input = Cache::get('param')` 在定时任务里为 null，随后 `->sender` 就报错；
 *   - ID 用 `string|float|int` 但处处于 0 / 数字比较，OpenID 字符串会被当成 0。
 */
abstract class BaseApp {
	/** 存储门面（Redis 风格） */
	protected StoreBridge $redis;
	/** 当前事件 */
	protected Event $input;
	/** API 容器 */
	protected Api $api;

	/** 当前用户 QQ / OpenID */
	protected string|float|int $qq = 0;
	/** 当前群号 / group_openid（私聊事件为 0） */
	protected string|float|int $group = 0;
	protected ?string $nickname = '';
	protected ?string $raw = '';
	protected object $sender;
	protected string|float|int $message_id = 0;
	protected int $reaction = 66;

	public function __construct(public mixed $app = null)
	{
		$store = \Core\App::boot()->container->get(\Core\Store\Store::class);
		$this->redis = new StoreBridge($store);
		$this->api = Api::getInstance();

		$context = Context::current();
		$this->input = $context?->event ?? Event::fromArray(['post_type' => '']);
		$this->sender = (object)$this->input->sender();

		// 消息 ID：Milky 给 message_seq、官方机器人给字符串，统一按标量存
		$messageId = $this->input->get('message_id') ?? $this->input->get('message_seq') ?? 0;
		$this->message_id = is_scalar($messageId) ? $messageId : 0;

		$this->initUserInfo();
		$this->lazyInitUserPermission();
	}

	/**
	 * 初始化用户/群/原始消息
	 */
	protected function initUserInfo(): void
	{
		$this->qq = $this->input->userId() ?: 0;
		$this->group = $this->input->groupId() ?: 0;
		$this->nickname = $this->input->nickname();

		// 原始消息：走 Api\message::escape，但该能力不可用时不能拖垮插件
		$raw = $this->input->get('raw_message', '');
		$raw = is_string($raw) ? $raw : '';
		try {
			$this->raw = $this->api->message->escape($raw, false);
		} catch (\Throwable $e) {
			$this->raw = $raw;
			Logger::debug('[插件] escape 不可用，使用原始消息：' . $e->getMessage());
		}
	}

	/**
	 * 首次使用时把用户角色写入存储（v1 语义：群成员角色即默认权限）
	 */
	protected function lazyInitUserPermission(): void
	{
		$qq = (string)$this->qq;
		$role = $this->input->role();
		if ($qq === '' || $role === '') {
			return;
		}
		$key = "memberPermission.{$qq}";
		if (!$this->redis->exists($key)) {
			$this->redis->set($key, $role);
		}
	}

	// ---------------------- 权限 ----------------------

	public function comparePermission(string|int|PermRule $userPerm, string|int|PermRule $requiredPerm): bool
	{
		$userPerm = $this->PermRule($userPerm);
		if ($userPerm === PermRule::SUPER) {
			return true;
		}
		$required = $this->PermRule($requiredPerm);
		$userValue = $userPerm->getValue();
		$requiredValue = $required->getValue();
		return ($userValue & $requiredValue) === $requiredValue;
	}

	public function PermRule(string|int|PermRule $perm): PermRule
	{
		if ($perm instanceof PermRule) {
			return $perm;
		}
		return PermRule::getByPerm(is_string($perm) ? strtolower(trim($perm)) : $perm);
	}

	public function getUserPermission(string|float|int $userQQ): PermRule
	{
		$key = "memberPermission.{$userQQ}";
		$value = $this->redis->exists($key) ? $this->redis->get($key) : 'default';
		return PermRule::getByPerm((string)$value);
	}

	// ---------------------- 群信息 ----------------------

	/** 群是否开启（status === 1） */
	public function groupStatus(string|float|int $group): bool
	{
		return ($this->getGroupInfo($group)->status ?? 0) === 1;
	}

	/** 读取群配置（worker 列表 = 各插件是否启用） */
	public function getGroupInfo(string|float|int $group): object
	{
		$key = "{$group}.info";
		$raw = $this->redis->get($key);
		if ($raw !== false) {
			$data = json_decode((string)$raw);
			if (is_object($data)) {
				if (empty($data->worker)) {
					$data->worker = $this->scanWorkerList();
					$this->redis->set($key, json_encode($data, 320));
				}
				return $data;
			}
		}

		$data = (object)['status' => 0, 'worker' => $this->scanWorkerList()];
		$this->redis->set($key, json_encode($data, 320));
		return $data;
	}

	public function initGroupInfo(string|float|int $group, bool $isArray = false): object|array
	{
		$key = "{$group}.info";
		$status = 0;
		$worker = [];

		$raw = $this->redis->get($key);
		if ($raw !== false) {
			$old = json_decode((string)$raw, true);
			if (is_array($old)) {
				$status = $old['status'] ?? 0;
				$worker = (array)($old['worker'] ?? []);
			}
		}

		$workerMap = $this->scanWorkerList();
		foreach ($worker as $name => $enabled) {
			if (array_key_exists($name, $workerMap)) {
				$workerMap[$name] = $enabled;
			}
		}

		$info = ['status' => $status, 'worker' => $workerMap];
		$this->redis->set($key, json_encode($info, JSON_UNESCAPED_UNICODE));
		return $isArray ? $info : (object)$info;
	}

	public function updateGroupInfo(string|float|int $group, object|array $info): bool
	{
		$key = "{$group}.info";
		$info = is_object($info) ? (array)$info : $info;
		$info['status'] = $info['status'] ?? 0;
		$info['worker'] = $info['worker'] ?? [];
		$ok = $this->redis->set($key, json_encode($info, JSON_UNESCAPED_UNICODE));
		// 群配置变了：让插件注册表失效
		\Core\App::boot()->container->get(\Core\Bot\Registry::class)->invalidate();
		return (bool)$ok;
	}

	/** 全部可用的插件类（v1 的 Router::scanAppClasses 替代品） */
	public function scanWorkerList(): array
	{
		$registry = \Core\App::boot()->container->get(\Core\Bot\Registry::class);
		$list = [];
		foreach ($registry->plugins() as $plugin) {
			$list[$plugin['class']] = true;
		}
		return $list;
	}

	// ---------------------- 工具 ----------------------

	/**
	 * 延迟删除消息。
	 *
	 * v1 用 `Swoole\Timer::after`，没有 Swoole 时直接抛错。
	 * v2 交给 Core\Scheduler：Swoole 下就是 Timer::after，
	 * PHP 模式下降级为「注册到延迟队列，由轮询 worker 到点执行」。
	 */
	protected function del(string|float|int $msgId, float $delay = 2): void
	{
		\Core\Scheduler::after($delay, function () use ($msgId): void {
			try {
				$this->api->message->deleteMsg($msgId);
				Logger::success("[消息删除] 延迟后删除消息ID：{$msgId}");
			} catch (\Throwable $e) {
				Logger::error("[消息删除失败] ID:{$msgId}，原因：{$e->getMessage()}");
			}
		});
	}

	/** 是否好友（优先读缓存） */
	protected function isFriend(string|float|int|null $qq = null): bool
	{
		$qq = $qq ?? $this->qq;
		if ($qq === '' || $qq === null) {
			return false;
		}

		$key = "{$this->input->selfId()}.friend.list";
		$cached = $this->redis->get($key);
		if ($cached !== false) {
			$list = json_decode((string)$cached, true);
			return is_array($list) && in_array($qq, $list, false);
		}

		$res = $this->api->friend->getFriendList();
		$list = array_column((array)($res->data ?? []), 'user_id');
		$this->redis->set($key, json_encode($list));
		return in_array($qq, $list, false);
	}

	public function __get(string $key): mixed
	{
		return match ($key) {
			'Redis' => $this->redis,
			default => null,
		};
	}
}