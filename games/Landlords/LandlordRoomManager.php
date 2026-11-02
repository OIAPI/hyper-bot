<?php
namespace games\Landlords;

use utils\Cache;

// 房间状态常量
define('ROOM_STATUS_WAITING', 1); // 等待玩家加入
define('ROOM_STATUS_BIDDING', 2); // 抢地主阶段
define('ROOM_STATUS_PLAYING', 3); // 游戏进行中
define('ROOM_STATUS_FINISHED', 4); // 游戏结束

// 玩家状态常量
define('PLAYER_STATUS_OFFLINE', 0);
define('PLAYER_STATUS_ONLINE', 1);
define('PLAYER_STATUS_READY', 2);

/**
 * 斗地主房间管理类
 * 负责房间创建、玩家管理、游戏生命周期控制
 */

class LandlordRoomManager {
    private static $instance;
    private $redis; // Redis连接实例
    private $prefix = 'landlord:'; // 缓存键前缀
    private $message;

    // 单例模式初始化Redis连接
    public static function getInstance() {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->redis = Cache::$api->redis;
    }

    // 生成唯一房间ID
    private function generateRoomId() {
        do {
            $roomId = rand(100000, 999999);
        } while ($this->redis->exists($this->getRoomKey($roomId)));
        return $roomId;
    }

    // 缓存键生成方法
    private function getRoomKey($roomId) {
        return $this->prefix . "room:$roomId";
    }
    private function getPlayersKey($roomId) {
        return $this->prefix . "room:$roomId:players";
    }
    private function getGameKey($roomId) {
        return $this->prefix . "room:$roomId:game";
    }

    // 1. 创建房间
    public function createRoom($creatorId) {
        $roomId = $this->generateRoomId();
        $roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);

        // 房间基础信息
        $roomData = [
            'roomId' => $roomId,
            'creatorId' => $creatorId,
            'status' => ROOM_STATUS_WAITING,
            'createTime' => time()
        ];

        // 存储房间信息（设置2小时过期，自动清理闲置房间）
        $this->redis->hMSet($roomKey, $roomData);
        $this->redis->expire($roomKey, 7200);

        // 初始化玩家列表（创建者加入）
        /*
        $this->redis->hSet($playersKey, $creatorId, PLAYER_STATUS_ONLINE);
        $this->redis->expire($playersKey, 7200);
        */
        // $this->joinRoom($roomId, $creatorId);
        return $roomId;
    }

	public function joinRoom($roomId, $playerId) {
	    $roomKey = $this->getRoomKey($roomId);
	    $playersKey = $this->getPlayersKey($roomId);
	    $playerRoomKey = $this->prefix . "player:{$playerId}:room"; // 新增：玩家当前所在房间的映射键
	
	    // 1. 检测玩家是否已在其他房间（非空闲状态）
	    $existingRoomId = $this->redis->get($playerRoomKey);
	    if ($existingRoomId) {
	    	if($existingRoomId == $roomId) throw new \Exception("你已在该房间，无法重新加入", -1);
	        // 若玩家已在其他房间，且该房间未结束，则不允许加入新房间
	        $existingRoomStatus = $this->redis->hGet($this->getRoomKey($existingRoomId), 'status');
	        if ($existingRoomStatus != ROOM_STATUS_FINISHED) {
	            throw new \Exception("你正在其他房间游戏，无法加入\n游戏房间：{$existingRoomId}", -1); // 玩家正在其他房间游戏，无法加入
	        }
	    }
	
	    // 2. 验证目标房间状态（必须是等待中）
	    if (!$this->redis->exists($roomKey) || $this->redis->hGet($roomKey, 'status') != ROOM_STATUS_WAITING) {
	        throw new \Exception("该房间" . $this->getTypeName($this->redis->hGet($roomKey, 'status')), -1); // 房间不存在或已开始游戏
	    }
	
	    // 3. 验证房间人数（最多3人）
	    if ($this->redis->hLen($playersKey) >= 3) {
	        throw new \Exception("该房间人数已满", -1); // 房间已满
	    }
	
	    // 4. 添加玩家到当前房间，并记录玩家与房间的映射
	    $this->redis->hSet($playersKey, $playerId, PLAYER_STATUS_ONLINE);
	    $this->redis->expire($playersKey, 7200);
	
	    // 记录玩家当前所在房间（用于后续状态检测）
	    $this->redis->set($playerRoomKey, $roomId);
	    $this->redis->expire($playerRoomKey, 7200); // 与房间过期时间一致
		if($this->redis->hLen($playersKey) == 3) throw new \Exception("房间人数已满，是否开始准备？\n发送：准备");
	    return true;
	}

    // 3. 玩家准备
    public function playerReady($roomId, $playerId) {
        $playersKey = $this->getPlayersKey($roomId);
        if (!$this->redis->hExists($playersKey, $playerId)) {
            throw new \Exception("玩家未在房间中", -1);
        }
        $this->redis->hSet($playersKey, $playerId, PLAYER_STATUS_READY);
        $players = $this->redis->hGetAll($playersKey);
        if(array_sum($players) % 6 == 0) throw new \Exception("房间所有玩家已准备就绪，请发送：开始游戏");
        return true;
    }

    // 4. 开始游戏（初始化游戏实例并缓存）
    public function startGame($roomId) {
	    $roomKey = $this->getRoomKey($roomId);
	    $playersKey = $this->getPlayersKey($roomId);
	    $gameKey = $this->getGameKey($roomId);
	
	    // 验证房间状态
	    if ($this->redis->hGet($roomKey, 'status') != ROOM_STATUS_WAITING) {
	    	throw new \Exception("该房间正在游戏中", -1);
	    }
	
	    // 修复：统计准备状态的玩家数量（hGetAll + 过滤）
	    $allPlayers = $this->redis->hGetAll($playersKey); // 获取所有玩家的状态（[playerId => status]）
	    $readyCount = 0;
	    $notReadyList = [];
	    foreach ($allPlayers as $player => $status) {
	        if ($status == PLAYER_STATUS_READY) {
	            $readyCount++;
	        } else {
	        	$notReadyList[] = "{$player}：" . $this->getTypeName($status);
	        }
	    }
	
	    // 验证是否满3名准备玩家
	    if ($readyCount < 3) {
	    	throw new \Exception("该房间已准备玩家不足3人\n" . join("\n", $notReadyList), -1);
	    }
	
	    // 初始化游戏实例并序列化存储
	    $game = new LandlordGame();
	    $this->redis->set($gameKey, serialize($game));
	    $this->redis->expire($gameKey, 7200);
	
	    // 更新房间状态为"抢地主阶段"
	    $this->redis->hSet($roomKey, 'status', ROOM_STATUS_BIDDING);
	    return $this->getCardsAll($roomId);
	}

    // 5. 抢地主操作
    public function bidLandlord($roomId, $playerId, $bid) {
        $roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);
        // 验证状态
        if ($this->redis->hGet($roomKey, 'status') != ROOM_STATUS_BIDDING) {
            throw new \Exception("当前房间并不是抢地主时间", -1);
        }

        // 获取游戏实例
        $game = unserialize($this->redis->get($gameKey));
        if (!$game) throw new \Exception("未在进行游戏", -1);

        // 映射玩家ID到游戏内索引（0-2）
        $playerList = $this->redis->hKeys($playersKey);
        $playerIndex = array_search($playerId, $playerList);
        if ($playerIndex === false) throw new \Exception("玩家不存在", -1);
        if ($playerIndex != $game->getCurrentBidPlayer()) throw new \Exception("当前应为：{$playerList[$game->getCurrentBidPlayer()]} 发言", -1);
        // 执行指定地主逻辑
        $result = $game->bidLandlord($playerIndex, $bid);
        // $result = $game->callLandlord(array_rand($playerList));

        // 保存更新后的游戏实例
        $this->redis->set($gameKey, serialize($game));

        // 抢地主结束后更新房间状态
        if ($game->getBidStatus()['isOver']) {
            $this->redis->hSet($roomKey, 'status', ROOM_STATUS_PLAYING);
            throw new \Exception("地主是：{$playerList[$game->getLandlord()]}\n发送：出牌 + 牌\n例如：出牌33344456(飞机)");
        }
        return $result;
    }
    
    // 6. 玩家出牌
    public function playCards($roomId, $playerId, $cardIndexes) {
        $roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        // 验证状态
        if ($this->redis->hGet($roomKey, 'status') != ROOM_STATUS_PLAYING) {
            throw new \Exception("该房间未在游戏中", -1);
        }

        // 获取游戏实例
        $game = unserialize($this->redis->get($gameKey));
        if (!$game) throw new \Exception("游戏不存在", -1);

        // 映射玩家ID到游戏内索引
        $playerList = $this->redis->hKeys($playersKey);
        $playerIndex = array_search($playerId, $playerList);
        if ($playerIndex === false || $playerIndex != $game->getCurrentPlayer()) {
            throw new \Exception("当前不该你出牌\n当前应是{$playerList[$game->getCurrentPlayer()]}出牌", -1); // 不是当前出牌玩家
        }

        // 执行出牌逻辑
        $result = $game->playCards($playerIndex, $cardIndexes);

        // 保存更新后的游戏实例
        $this->redis->set($gameKey, serialize($game));

        // 检查游戏是否结束
        if ($over = $game->isGameOver()) {
            $this->redis->hSet($roomKey, 'status', ROOM_STATUS_FINISHED);
            $this->redis->hSet($roomKey, 'winner', $over);
            $this->finishRoom($roomId);
            if(str_starts_with($over, "地主")) {
            	$bid = $game->getBidStatus();
            	$bidNumber = $bid['highestBid'] / 2; //叫地主分数
            	$bidPlayer = $bid['highestBitPlayer'] ; //地主;
            	
            }
            throw new \Exception("游戏结束：" . $over);
        }
        return $result;
    }

    // 7. 获取房间信息（API查询用）
    public function getRoomInfo($roomId) {
        $roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }

        // 基础房间信息
        $roomInfo = $this->redis->hGetAll($roomKey);

        // 玩家信息
        $players = $this->redis->hGetAll($playersKey);
        $roomInfo['players'] = $players;

        // 游戏状态（仅当游戏已开始）
        if ($this->redis->exists($gameKey)) {
            $game = unserialize($this->redis->get($gameKey));
            print_r($game);
            $roomInfo['gameStatus'] = [
                'currentPlayer' => $game->getCurrentPlayer(),
                'lastCards' => $game->getLastPlayedCards(), //array_column($game->lastCards, 'text'),
                'bidStatus' => $game->getBidStatus(),
                'isOver' => $game->isGameOver()
            ];
        }

        return $roomInfo;
    }

    // 8. 结束房间（清理缓存）
    public function finishRoom($roomId) {
    	$roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);
    	$playerList = $this->redis->hKeys($playersKey);
    	$del = [
    		$roomKey, 
    		$playersKey,
    		$gameKey,
    	];
    	foreach($playerList as $playerId) {
    		array_push($del, $this->prefix . "player:{$playerId}:room");
    	}
        $this->redis->del($del);
        return true;
    }
    public function getPlayerRoom($playerId) {
    	$playerRoomKey = $this->prefix . "player:{$playerId}:room"; // 新增：玩家当前所在房间的映射键
    	return $this->redis->get($playerRoomKey) ?: false;
    }
    public function getTypeName(int $type) {
    	$typeNames = [
    		ROOM_STATUS_WAITING => '等待',
			ROOM_STATUS_BIDDING => '抢地主',
			ROOM_STATUS_PLAYING => '进行中',
			ROOM_STATUS_FINISHED => '结束',
			
			// 玩家状态常量
			PLAYER_STATUS_OFFLINE => '空闲',
			PLAYER_STATUS_ONLINE => '房间中',
			PLAYER_STATUS_READY => '已准备',
    	];
    	return isset($typeName[$type]) ?
    			$typeName[$type] :
    			'未知';
    }
    /**
     * 获取当前出牌 牌型
     */
    public function getLastPlayedCards($roomId) {
        $roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }
        $game = unserialize($this->redis->get($gameKey));
        $last = $game->getLastPlayedCards();
        return $last ?
        		$last['type'] . '：' . join(' ', $last['cards']) :
        		$last['type'];
    }
    /**
     * 获取当前出牌人
     */
    public function getCurrentPlayer($roomId) {
    	$roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }
        $game = unserialize($this->redis->get($gameKey));
        $current = $game->getCurrentPlayer();
        $players = $this->redis->hKeys($playersKey);
        return isset($players[$current]) ?
        		$players[$current] :
        		throw new \Exception('玩家不存在', -1);
    }
    public function pass($roomId, $playerId) {
    	$roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }
        // 验证状态
        if ($this->redis->hGet($roomKey, 'status') != ROOM_STATUS_PLAYING) {
            throw new \Exception("该房间未在游戏中", -1);
        }

        // 获取游戏实例
        $game = unserialize($this->redis->get($gameKey));
        if (!$game) throw new \Exception("游戏不存在", -1);

        // 映射玩家ID到游戏内索引
        $playerList = $this->redis->hKeys($playersKey);
        $playerIndex = array_search($playerId, $playerList);
        if ($playerIndex === false || $playerIndex != $game->getCurrentPlayer()) {
            throw new \Exception("当前不该你出牌\n当前应是{$playerList[$game->getCurrentPlayer()]}出牌", -1); // 不是当前出牌玩家
        }
        try {
            $game->pass($playerIndex);
        } catch(\Exception $e) {
        	$this->redis->set($gameKey, serialize($game));
        	throw new \Exception($e->getMessage()."哈哈", $e->getCode());
        }
        $this->redis->set($gameKey, serialize($game));
        return true;
    }
    /**
     * 获取所有玩家的牌
     */
    public function getCardsAll($roomId) {
    	$roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }
        // 验证状态
        if ($this->redis->hGet($roomKey, 'status') == ROOM_STATUS_WAITING) {
            throw new \Exception("该房间未在游戏中, 获取卡牌失败", -1);
        }

        // 获取游戏实例
        $game = unserialize($this->redis->get($gameKey));
        if (!$game) throw new \Exception("游戏不存在", -1);
        $playerList = $this->redis->hKeys($playersKey);
        $result = [];
        foreach($playerList as $k => $v) {
        	$result[$v] = $game->getPlayerCardsText($k);
        }
        return $result;
    }
    public function getPlayerCardsText($roomId, $playerId) {
    	$roomKey = $this->getRoomKey($roomId);
        $playersKey = $this->getPlayersKey($roomId);
        $gameKey = $this->getGameKey($roomId);

        if (!$this->redis->exists($roomKey)) {
            return null;
        }
        // 验证状态
        if ($this->redis->hGet($roomKey, 'status') != ROOM_STATUS_PLAYING) {
            throw new \Exception("该房间未在游戏中", -1);
        }
        $playerList = $this->redis->hKeys($playersKey);
        $playerIndex = array_search($playerId, $playerList);
        // 获取游戏实例
        $game = unserialize($this->redis->get($gameKey));
        if (!$game) throw new \Exception("游戏不存在", -1);
        return $game->getPlayerCardsText($playerIndex);
    }
    public function incr($player, float $bid = 1) {
    	$this->redis->incrByFloat($this->getPlayerKey(), $bid);
    }
    public function decr($player, float $bid = 1) {
        $this->redis->incrByFloat($this->getPlayerKey(), $bid * -1);
    }
    public function getPlayerKey($player) {
    	return "game.{$player}.mark";
    }
}

