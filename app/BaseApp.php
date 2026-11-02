<?php
namespace App;

use Swoole\Timer;
use Utils\Env;
use Utils\Logger;
use Utils\Cache;
use Controller\Api;
use Controller\Router;
use Controller\Enum\PermRule;
use Redis;

/**
 * 插件统一基础类
 * 优化Redis连接、初始化逻辑及性能
 */
abstract class BaseApp {
	// 核心属性：统一管理公共资源
	protected Redis $redis;		  // Redis实例（确保非null）
	protected object $input;		 // 输入参数（缓存的请求参数）
	protected Api $api;			  // 外部API实例
	// ID 使用 string|float|int：QQ 号/群号是数字，QQ 官方机器人则是 OpenID 字符串
	protected string|float|int $qq = 0;		 // 当前用户 QQ / OpenID
	protected string|float|int $group = 0;	  // 当前群号 / group_openid
	protected ?string $nickname = '';// 当前用户昵称
	protected ?string $raw = '';	 // 原始消息（已转义）
	protected object $sender;		// 发送者信息
	protected string $redisSelect;	 // Redis数据库编号（避免重复读取配置）
	protected string|float|int $message_id = 0; // 消息 ID（Milky 为 message_seq，官方机器人为字符串）
	protected int $reaction = 66;
	use \Controller\Traits\Worker;


	/**
	 * 构造函数：统一初始化所有基础资源
	 * @param mixed $app 业务应用实例（兼容原app类参数）
	 */
	public function __construct(public $app) {
		// 1. 初始化基础依赖（优先复用缓存中的实例）
		$this->input = Cache::get('param');
		$this->api = Cache::get('api');
		$this->sender = $this->input->sender ?? new \stdClass();
		$this->message_id = $this->input->message_id ?? 0;
		$this->redisSelect = Env::get('Redis.select');

		// 2. 初始化Redis（单例复用，避免重复连接）
		$this->initRedis();

		// 3. 初始化用户/群相关信息
		$this->initUserInfo();
		// $this->initGroupInfoIfNeed();

		// 4. 初始化用户权限（延迟到首次使用时执行，提升性能）
		$this->lazyInitUserPermission();
		
		$this->initSetGroupReaction();
	}


	/**
	 * Redis初始化：复用缓存实例+单例连接，避免重复auth/select
	 */
	protected function initRedis(): void {
		// 优先复用Cache中的Redis实例
		if (Cache::$api->Redis instanceof Redis && Cache::$api->Redis->isConnected()) {
			$this->redis = Cache::$api->Redis;
		} else {
			// 新建连接（使用pconnect长连接，提升性能）
			$this->redis = new Redis();
			$this->redis->pconnect(
				Env::get('Redis.host'),
				Env::get('Redis.port'),
				3 // 连接超时时间（秒）
			);
			// 仅当有密码时执行auth（避免无效操作）
			$auth = Env::get('Redis.auth');
			if (!empty($auth)) {
				$this->redis->auth($auth);
			}
			Cache::$api->Redis = $this->redis; // 存入缓存，供后续复用
		}

		// 切换到指定数据库（仅当当前库不匹配时执行）
		if ($this->redis->getDbNum() != $this->redisSelect) {
			$this->redis->select($this->redisSelect);
		}
	}


	/**
	 * 初始化用户信息（QQ、昵称、原始消息）
	 */
	protected function initUserInfo(): void {
		$this->qq = $this->input->user_id ?? 0;
		$this->group = $this->input->group_id ?? 0;

		// 昵称优先级：群名片 > 好友昵称 > 空字符串
		$this->nickname = $this->sender->card ?? $this->sender->nickname ?? '';

		// 原始消息转义（兼容空消息场景）
		$rawMsg = $this->input->raw_message ?? '';
		$this->raw = $this->api->message->escape($rawMsg, false);
	}


	/**
	 * 延迟初始化用户权限：仅当用户存在时执行，避免无效Redis操作
	 */
	protected function lazyInitUserPermission(): void {
		// 无用户ID时跳过初始化
		if (empty($this->qq) || !isset($this->sender->role)) {
			return;
		}

		$permKey = "memberPermission.{$this->qq}";
		// 仅当Redis中无权限记录时，初始化并缓存
		if (!$this->redis->exists($permKey)) {
			$this->redis->set($permKey, $this->sender->role);
			Logger::success("[权限初始化] 用户{$this->qq}角色：{$this->sender->role}");
		}
	}


	/**
	 * 按需初始化群信息：仅当群号有效时执行
	 */
	protected function initGroupInfoIfNeed(): void {
		if ($this->group > 0) {
			$this->initGroupInfo($this->group);
		}
	}
	
	protected function initSetGroupReaction() {
	}


	// ---------------------- 权限相关方法 ----------------------
	/**
	 * 权限比较：判断用户权限是否满足要求
	 * @param string|int|PermRule $userPerm 用户当前权限
	 * @param string|int|PermRule $requiredPerm 所需权限
	 * @return bool 满足返回true，否则false
	 */
	public function comparePermission(string|int|PermRule $userPerm, string|int|PermRule $requiredPerm): bool {
		$userPerm = $this->PermRule($userPerm);
		
		if($userPerm === PermRule::SUPER) return true;
		
		$requiredPerm = $this->PermRule($requiredPerm);
		$userPermValue = $userPerm->getValue();
		$requiredPermValue = $requiredPerm->getValue();
		// 位运算逻辑：(用户权限 & 所需权限) === 所需权限 → 表示用户权限包含所需权限
		return ($userPermValue & $requiredPermValue) === $requiredPermValue;
	}


	/**
	 * 权限名称转数字（基于PermRule枚举，统一格式）
	 * @param string|int|PermRule $perm 权限（名称/数字）
	 * @return PermRule 权限角色
	 */
	public function PermRule(string|int|PermRule $perm): PermRule
	{
		if($perm instanceof PermRule) return $perm;
		if (is_string($perm)) {
			$perm = strtolower(trim($perm));
		}
		return PermRule::getByPerm($perm);
	}

	/**
	 * 获取用户权限（从Redis缓存读取）
	 * @param string|float|int $userQQ 用户QQ / OpenID
	 * @return string|int 权限（名称/数字）
	 */
	public function getUserPermission(string|float|int $userQQ): PermRule {
		$permKey = "memberPermission.{$userQQ}";
		return PermRule::getByPerm($this->redis->exists($permKey) ? $this->redis->get($permKey) : 'default');
	}


	// ---------------------- 工具方法 ----------------------
	/**
	 * 延迟删除消息（基于Swoole Timer）
	 * @param string|float|int $msgId 消息ID
	 * @param float $delay 延迟时间（秒，默认2秒）
	 */
	protected function del(string|float|int $msgId, float $delay = 2): void {
		Timer::after((int)($delay * 1000), function () use ($msgId) {
			try {
				$this->api->message->deleteMsg($msgId);
				Logger::success("[消息删除] 延迟{$delay}秒删除消息ID：{$msgId}");
			} catch (\Exception $e) {
				Logger::error("[消息删除失败] ID:{$msgId}，原因：{$e->getMessage()}");
			}
		});
	}


	/**
	 * 检测是否为好友（优化：优先读缓存，无缓存再调用API）
	 * @param float|null $qq 目标QQ（默认当前用户）
	 * @return bool 是否为好友
	 */
	protected function isFriend(string|float|int|null $qq = null): bool {
		$qq = $qq ?? $this->qq;
		if ($qq <= 0) return false;

		// 1. 优先读取缓存的好友列表
		$friendCacheKey = "{$this->input->self_id}.friend.list";
		if ($this->redis->exists($friendCacheKey)) {
			$friendList = json_decode($this->redis->get($friendCacheKey), true);
			return in_array($qq, $friendList, true); // 严格匹配类型
		}
		// 2. 缓存不存在，调用API获取并缓存
		$res = $this->api->friend->getFriendList();
		if (empty($res->data)) return false;

		$friendList = array_column($res->data, 'user_id');
		$this->redis->set($friendCacheKey, json_encode($friendList));
		return in_array($qq, $friendList, true);
	}
	public function __get($key) {
		return match($key) {
			'Redis' => $this->redis
		};
	}
}