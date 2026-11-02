<?php
namespace games\Landlords;

class LandlordGame implements \Serializable {
    // 牌型定义常量
    const TYPE_SINGLE = 1; // 单张
    const TYPE_PAIR = 2; // 对子
    const TYPE_TRIPLE = 3; // 三张
    const TYPE_STRAIGHT = 4; // 顺子
    const TYPE_BOMB = 5; // 炸弹（四张相同）
    const TYPE_TRIPLE_WITH_SINGLE = 6; // 三带一（三张+单张）
    const TYPE_TRIPLE_WITH_PAIR = 7; // 三带二（三张+对子）
    const TYPE_PLANE_SINGLE = 8; // 飞机带单（连续三张+单张*2）
    const TYPE_PLANE_PAIR = 9; // 飞机带对（连续三张+对子*2）
    const TYPE_ROCKET = 10;      // 火箭（大小王）
    const TYPE_FOUR_WITH_SINGLE = 11; // 四带二（4+1+1）
    const TYPE_FOUR_WITH_PAIR = 12;   // 四带两对（4+2+2）
    const TYPE_PLANE_3_SINGLE = 13;   // 3组飞机带单
    const TYPE_PLANE_3_PAIR = 14;     // 3组飞机带对
    // 新增抢地主相关常量与属性
    const BID_NONE = 0;    // 未叫分
    const BID_1 = 1;       // 叫1分
    const BID_2 = 2;       // 叫2分
    const BID_3 = 3;       // 叫3分
    const BID_GIVEUP = -1; // 不抢

    private $bidStatus = [self::BID_NONE, self::BID_NONE, self::BID_NONE]; // 玩家叫分状态
    private $currentBidPlayer = 0; // 当前叫分玩家索引
    private $highestBid = self::BID_NONE; // 最高叫分
    private $highestBidPlayer = -1; // 最高叫分玩家
    private $bidRound = 0; // 抢地主轮次（最多3轮）
    // 基础属性
    private $suits = ['♠', '♥', '♦', '♣'];
    private $ranks = ['3', '4', '5', '6', '7', '8', '9', '10', 'J', 'Q', 'K', 'A', '2'];
    private $jokers = ['小王', '大王'];
    private $cards = [];
    private $players = [[], [], []];
    private $landlord = -1;
    private $baseCards = [];
    private $currentPlayer = 0;
    private $lastCards = [];
    private $lastCardType = 0; // 上一轮牌型
    private $lastCardWeight = 0; // 上一轮核心权重（用于比较）
    private $lastPlayer = -1;
    private $passPlayers = []; // 存储选择“要不起”的玩家索引

    // 初始化游戏
    // public function __construct() {
        // $this->initCards();
        // $this->shuffleCards();
        // $this->dealCards();
    // }


    // 初始化游戏时重置抢地主状态
    public function __construct() {
        $this->initCards();
        $this->shuffleCards();
        $this->dealCards();
        $this->resetBidStatus(); // 新增：初始化抢地主状态
    }

    // 新增：重置抢地主状态
    private function resetBidStatus() {
        $this->bidStatus = [self::BID_NONE, self::BID_NONE, self::BID_NONE];
        $this->currentBidPlayer = 0; // 从玩家0开始叫分
        $this->highestBid = self::BID_NONE;
        $this->highestBidPlayer = -1;
        $this->bidRound = 0;
    }

    // 新增：抢地主核心逻辑（玩家叫分）
    /*
    public function bidLandlord($playerIndex, $bid) {
        // 验证叫分合法性
        if (
            $playerIndex < 0 || $playerIndex >= 3 || 
            $playerIndex != $this->currentBidPlayer || 
            !in_array($bid, [self::BID_GIVEUP, self::BID_1, self::BID_2, self::BID_3])
        ) {
            return false;
        }

        // 记录玩家叫分
        $this->bidStatus[$playerIndex] = $bid;

        // 处理叫分逻辑
        if ($bid != self::BID_GIVEUP) {
            // 叫分必须高于当前最高分
            if ($bid <= $this->highestBid) {
                return false;
            }
            // 更新最高分和玩家
            $this->highestBid = $bid;
            $this->highestBidPlayer = $playerIndex;
        }


        // 检查抢地主是否结束（3轮结束或只剩一人未放弃）
        if ($this->isBidOver()) {
            
            $this->confirmLandlord(); // 确认地主并分配底牌
            return true;
        }
        // 切换到下一位玩家叫分
        $this->currentBidPlayer = ($this->currentBidPlayer + 1) % 3;
        $this->bidRound++;
        return true;
    }
    */
    // 修改：抢地主核心逻辑（修复顺位问题）
	public function bidLandlord($playerIndex, $bid) {
	    // 验证叫分合法性（原逻辑保留）
	    if (
	        $playerIndex < 0 || $playerIndex >= 3 || 
	        $playerIndex != $this->currentBidPlayer || 
	        !in_array($bid, [self::BID_GIVEUP, self::BID_1, self::BID_2, self::BID_3])
	    ) {
	        return false;
	    }
	
	    // 记录玩家叫分（原逻辑保留）
	    $this->bidStatus[$playerIndex] = $bid;
	
	    // 处理叫分逻辑（原逻辑保留）
	    if ($bid != self::BID_GIVEUP) {
	        if ($bid <= $this->highestBid) {
	            return false;
	        }
	        $this->highestBid = $bid;
	        $this->highestBidPlayer = $playerIndex;
	    }
	    $this->currentBidPlayer = ($this->currentBidPlayer + 1) % 3;
	    $this->bidRound = (int)(count(array_filter($this->bidStatus)) / 3);
	
	    // 检查抢地主是否结束
	    if ($this->isBidOver() ) {
	        $this->highestBidPlayer = $this->currentBidPlayer;
	        $this->highestBid = self::BID_1;
	        $this->confirmLandlord();
	        return true;
	    }
	
	    return true;
	}


    // 新增：判断抢地主是否结束
    private function isBidOver() {
        // 最多3轮叫分
        if ($this->bidRound >= 3) {
            return true;
        }
        // 统计未放弃的玩家数量
        $remainingPlayers = 0;
        foreach ($this->bidStatus as $status) {
            if ($status != self::BID_GIVEUP) {
                $remainingPlayers++;
            }
        }
        if($remainingPlayers === 1) {
            $this->highestBidPlayer = $this->currentBidPlayer;
	        $this->highestBid = self::BID_1;
        }
        return $remainingPlayers <= 1;
    }

    // 新增：确认地主并分配底牌
    private function confirmLandlord($player = 0) {
        // 如果无人叫分（全部放弃），默认玩家0为地主（可自定义规则）
        if ($this->highestBidPlayer == -1) {
        	echo "随机地主\n";
            $this->highestBidPlayer = mt_rand(0, 2); //随机地主
            $this->highestBid = self::BID_1;
        }
        // 设置地主
        $this->landlord = $this->highestBidPlayer;
        // 地主获得底牌
        $this->players[$this->landlord] = array_merge($this->players[$this->landlord], $this->baseCards);
        // 重新排序地主手牌
        usort($this->players[$this->landlord], function($a, $b) {
            return $a['weight'] - $b['weight'];
        });
        $this->currentPlayer = $this->landlord; // 地主先出牌
    }

    // 废弃原直接叫地主方法，改为通过抢地主流程确认
    public function callLandlord($playerIndex) {
        return false; // 不再支持直接叫地主，需通过bidLandlord实现
    }

    // 新增：获取当前抢地主状态（用于前端展示）
    public function getBidStatus() {
        return [
            'currentBidPlayer' => $this->currentBidPlayer,
            'highestBid' => $this->highestBid,
            'highestBidPlayer' => $this->highestBidPlayer,
            'bidStatus' => $this->bidStatus,
            'isOver' => $this->isBidOver()
        ];
    }

    // 初始化完整牌组
    private function initCards() {
        // 添加普通牌（3-2）
        foreach ($this->suits as $suit) {
            foreach ($this->ranks as $rank) {
                $weight = array_search($rank, $this->ranks) + 1; // 3的权重为1，2为13
                $this->cards[] = [
                    'text' => $suit . $rank,
                    'weight' => $weight,
                    'type' => 'normal'
                ];
            }
        }
        // 添加大小王
        $this->cards[] = [
            'text' => $this->jokers[0],
            'weight' => 14,
            'type' => 'joker'
        ];
        $this->cards[] = [
            'text' => $this->jokers[1],
            'weight' => 15,
            'type' => 'joker'
        ];
    }

    // 洗牌（随机打乱牌组）
    private function shuffleCards() {
        shuffle($this->cards);
    }

    // 发牌：每人17张，留3张底牌
    private function dealCards() {
        for ($i = 0; $i < 51; $i++) {
            $this->players[$i % 3][] = $this->cards[$i];
        }
        // 预留底牌
        $this->baseCards = array_slice($this->cards, 51, 3);
        // 玩家手牌排序（按权重升序）
        foreach ($this->players as &$player) {
            usort($player, function($a, $b) {
                return $a['weight'] - $b['weight'];
            });
        }
    }

    // 叫地主（返回是否成功叫地主）
    /*
    public function callLandlord($playerIndex) {
        if ($this->landlord == -1 && $playerIndex >= 0 && $playerIndex < 3) {
            $this->landlord = $playerIndex;
            // 地主获得底牌
            $this->players[$playerIndex] = array_merge($this->players[$playerIndex], $this->baseCards);
            // 重新排序地主手牌
            usort($this->players[$playerIndex], function($a, $b) {
                return $a['weight'] - $b['weight'];
            });
            $this->currentPlayer = $playerIndex; // 地主先出牌
            return true;
        }
        return false;
    }
    */

    // 核心：牌型判断（返回牌型及核心权重）
    private function getCardType($cards) {
	    $count = count($cards);
	    if ($count == 0) return [false, 0, 0];
	    
	    // 提取权重并统计出现次数（核心：忽略牌的顺序，仅关注权重分布）
	    $weights = array_column($cards, 'weight');
	    $weightCounts = array_count_values($weights); // 键：权重值，值：出现次数
	    $uniqueWeights = array_keys($weightCounts); // 去重后的权重列表
	    $sortedWeights = $uniqueWeights;
	    sort($sortedWeights); // 排序后的去重权重（用于判断连续性）
	
	    // 1. 火箭（大小王：权重14+15，仅2张）
	    if ($count == 2 && $weightCounts === [14 => 1, 15 => 1]) {
	        return [self::TYPE_ROCKET, 16, 16]; // 火箭权重最高
	    }
	
	    // 2. 单张（1张任意牌）
	    if ($count == 1) {
	        return [self::TYPE_SINGLE, $weights[0], $weights[0]];
	    }
	
	    // 3. 对子（2张相同权重）
	    if ($count == 2 && count($uniqueWeights) == 1) {
	        return [self::TYPE_PAIR, $sortedWeights[0], $sortedWeights[0]];
	    }
	
	    // 4. 三张（3张相同权重）
	    if ($count == 3 && count($uniqueWeights) == 1) {
	        return [self::TYPE_TRIPLE, $sortedWeights[0], $sortedWeights[0]];
	    }
	
	    // 5. 炸弹（4张相同权重，非大小王组合）
	    if ($count == 4 && count($uniqueWeights) == 1) {
	        return [self::TYPE_BOMB, $sortedWeights[0], $sortedWeights[0]];
	    }
	
	    // 6. 顺子（5-12张连续权重，不包含2、小王、大王）
	    if ($count >= 5 && $count <= 12) {
	        $maxW = max($weights);
	        $minW = min($weights);
	        // 顺子不能包含2（权重13）、小王（14）、大王（15）
	        if ($maxW > 12) return [false, 0, 0];
	        // 连续且无重复（权重范围等于牌数，且去重后数量等于牌数）
	        if (($maxW - $minW + 1) == $count && count($uniqueWeights) == $count) {
	            return [self::TYPE_STRAIGHT, $maxW, $minW];
	        }
	    }
	
	    // 7. 三带一（3张相同 + 1张单张，共4张）
	    if ($count == 4 && count($uniqueWeights) == 2) {
	        foreach ($weightCounts as $w => $c) {
	            if ($c == 3) { // 存在3张相同，剩余1张为单
	                return [self::TYPE_TRIPLE_WITH_SINGLE, $w, $w];
	            }
	        }
	    }
	
	    // 8. 三带二（3张相同 + 2张相同，共5张）
	    if ($count == 5 && count($uniqueWeights) == 2) {
	        foreach ($weightCounts as $w => $c) {
	            if ($c == 3) { // 存在3张相同，剩余2张为对子
	                return [self::TYPE_TRIPLE_WITH_PAIR, $w, $w];
	            }
	        }
	    }
	
	    // 9. 四带二（4张相同 + 2张单张 或 4张相同 + 1对，共6张）
	    if ($count == 6) {
	        $fourWeight = 0;
	        foreach ($weightCounts as $w => $c) {
	            if ($c == 4) $fourWeight = $w; // 找到4张相同的权重
	        }
	        if ($fourWeight > 0) {
	            $remainingCounts = array_values(array_filter($weightCounts, function($c) {
	                return $c != 4;
	            }));
	            // 剩余2张为两单（1+1）或一对（2）
	            if (($remainingCounts === [1, 1]) || ($remainingCounts === [2])) {
	                return [self::TYPE_FOUR_WITH_SINGLE, $fourWeight, $fourWeight];
	            }
	        }
	    }
	
	    // 10. 四带两对（4张相同 + 2对，共8张）
	    if ($count == 8 && count($uniqueWeights) == 3) {
	        $fourWeight = 0;
	        $pairCounts = 0;
	        foreach ($weightCounts as $w => $c) {
	            if ($c == 4) $fourWeight = $w;
	            if ($c == 2) $pairCounts++; // 统计对子数量
	        }
	        if ($fourWeight > 0 && $pairCounts == 2) { // 4张+2对
	            return [self::TYPE_FOUR_WITH_PAIR, $fourWeight, $fourWeight];
	        }
	    }
	
	    // 11. 飞机带单/带对（多组连续三张 + 等量单张/对子）
	    $tripleWeights = []; // 存储所有“三张相同”的权重
	    foreach ($weightCounts as $w => $c) {
	        if ($c == 3) $tripleWeights[] = $w;
	    }
	    $tripleCount = count($tripleWeights); // 三张牌组的数量（至少2组）
	    if ($tripleCount >= 2) {
	        sort($tripleWeights); // 排序后检查连续性
	        $isContinuous = true;
	        for ($i = 1; $i < $tripleCount; $i++) {
	            if ($tripleWeights[$i] - $tripleWeights[$i-1] != 1) {
	                $isContinuous = false;
	                break;
	            }
	        }
	        if ($isContinuous) {
	            $totalTripleCards = $tripleCount * 3; // 三张牌组总数量
	            $remainingCards = $count - $totalTripleCards; // 剩余牌数量
	
	            // 飞机带单（每组三张带1张单，剩余牌数=组数）
	            if ($remainingCards == $tripleCount) {
	                $singleCount = 0;
	                foreach ($weightCounts as $w => $c) {
	                    // 排除三张牌组的权重，剩余必须是单张（出现1次）
	                    if (!in_array($w, $tripleWeights) && $c == 1) {
	                        $singleCount++;
	                    }
	                }
	                if ($singleCount == $tripleCount) {
	                    $type = $tripleCount == 2 ? self::TYPE_PLANE_SINGLE : self::TYPE_PLANE_3_SINGLE;
	                    return [$type, end($tripleWeights), $tripleWeights[0]];
	                }
	            }
	
	            // 飞机带对（每组三张带1对，剩余牌数=组数×2）
	            if ($remainingCards == $tripleCount * 2) {
	                $pairCount = 0;
	                foreach ($weightCounts as $w => $c) {
	                    // 排除三张牌组的权重，剩余必须是对子（出现2次）
	                    if (!in_array($w, $tripleWeights) && $c == 2) {
	                        $pairCount++;
	                    }
	                }
	                if ($pairCount == $tripleCount) {
	                    $type = $tripleCount == 2 ? self::TYPE_PLANE_PAIR : self::TYPE_PLANE_3_PAIR;
	                    return [$type, end($tripleWeights), $tripleWeights[0]];
	                }
	            }
	        }
	    }
	
	    // 所有牌型均不匹配
	    return [false, 0, 0];
	}
	public function playCards($playerIndex, $cardTexts) {
	    // 校验当前玩家是否有权出牌
	    if ($playerIndex != $this->currentPlayer) throw new \Exception("当前不是你出牌", -1);
	    
	    // 处理参数：字符串转数组（如"333"→["3","3","3"]）
	    if (is_string($cardTexts)) {
	        $cardTexts = $this->split($cardTexts);
	    }
	    if (!is_array($cardTexts) || empty($cardTexts)) {
	        throw new \Exception("请传入有效的牌面文本", -1);
	    }
	
	    // 基于牌面文本匹配手牌（处理重复牌逻辑）
	    $selectedCards = [];
	    $playerCards = $this->players[$playerIndex];
	    $textCount = array_count_values($cardTexts); // 统计每个文本需要的数量
	    print_r($textCount);
	    print_r($playerCards);
	    foreach ($playerCards as $card) {
	        $text = mb_substr($card['text'], -1);
	        $text = $text == "王" ? $card['text'] : ($text == '0' ? mb_substr($card['text'], -2) : $text);
	        echo $text, PHP_EOL;
	        if (isset($textCount[$text]) && $textCount[$text] > 0) {
	            $selectedCards[] = $card;
	            $textCount[$text]--; // 匹配后减少计数
	        }
	    }
	
	    // 校验是否所有牌都存在于手牌中
	    if (array_sum($textCount) > 0) {
	        throw new \Exception("手牌中不存在指定的牌", -1);
	    }
	    if (empty($selectedCards)) {
	        throw new \Exception("玩家牌不存在", -1);
	    }
	
	    // 验证牌型合法性（后续逻辑不变）
	    list($cardType, $mainWeight, $subWeight) = $this->getCardType($selectedCards);
	    if (!$cardType) throw new \Exception("所出牌型不合法", -1);
	
	    // 验证牌型大小规则（后续逻辑不变）
	    if (!empty($this->lastCards)) {
            $lastType = $this->lastCardType;
            $lastWeight = $this->lastCardWeight;

            if ($cardType == self::TYPE_ROCKET) {
                if ($lastType == self::TYPE_ROCKET) throw new \Exception("火箭不能压火箭", -1); // 火箭不可压火箭
            } else {
                if ($lastType == self::TYPE_ROCKET) throw new \Exception("非火箭不可压火箭", -1); // 非火箭不可压火箭
                if ($lastType == self::TYPE_BOMB) {
                    if ($cardType != self::TYPE_BOMB || $mainWeight <= $lastWeight) {
                        throw new \Exception("所出牌管不起1", -1); // 非炸弹或炸弹权重不足
                    }
                } else {
                    if ($cardType != self::TYPE_BOMB && ($cardType != $lastType || $mainWeight <= $lastWeight)) {
                        throw new \Exception("所出牌管不起2", -1); // 非炸弹需同类型且权重大于上一轮
                    }
                }
            }
        }
	
	    // 移除已出牌（基于text过滤，原有逻辑不变）
	    $selectedTexts = array_column($selectedCards, 'text');
	    $this->players[$playerIndex] = array_values(array_filter(
	        $this->players[$playerIndex],
	        function($card) use ($selectedTexts) {
	            return !in_array($card['text'], $selectedTexts);
	        }
	    ));
	
	    // 更新游戏状态（后续逻辑不变）
	    $this->lastCards = $selectedCards;
	    $this->lastCardType = $cardType;
	    $this->lastCardWeight = $mainWeight;
	    $this->lastPlayer = $this->currentPlayer;
	    $this->currentPlayer = ($this->currentPlayer + 1) % 3;
	    $this->passPlayers = []; // 新增：出牌后清空要不起记录
	    return $this->players[$playerIndex];
	}
    // 出牌验证与手牌更新（修复核心问题）
    /*
    public function playCards($playerIndex, $cardIndexes) {
        // 校验当前玩家是否有权出牌
        if ($playerIndex != $this->currentPlayer) throw new \Exception("当前不是你出牌", -1);
        
        // 提取选中的牌（严格校验索引有效性）
        $selectedCards = [];
        foreach ($cardIndexes as $index) {
            if (!isset($this->players[$playerIndex][$index])) {
                throw new \Exception("未知错误，玩家不存在", -1); // 索引无效，直接返回失败
            }
            $selectedCards[] = $this->players[$playerIndex][$index];
        }
        if (empty($selectedCards)) throw new \Exception("玩家牌不存在", -1);

        // 验证牌型合法性
        list($cardType, $mainWeight, $subWeight) = $this->getCardType($selectedCards);
        if (!$cardType) throw new \Exception("所出牌型不合法", -1);

        // 验证牌型大小规则
        if (!empty($this->lastCards)) {
            $lastType = $this->lastCardType;
            $lastWeight = $this->lastCardWeight;

            if ($cardType == self::TYPE_ROCKET) {
                if ($lastType == self::TYPE_ROCKET) throw new \Exception("火箭不能压火箭", -1); // 火箭不可压火箭
            } else {
                if ($lastType == self::TYPE_ROCKET) throw new \Exception("非火箭不可压火箭", -1); // 非火箭不可压火箭
                if ($lastType == self::TYPE_BOMB) {
                    if ($cardType != self::TYPE_BOMB || $mainWeight <= $lastWeight) {
                        throw new \Exception("所出牌管不起", -1); // 非炸弹或炸弹权重不足
                    }
                } else {
                    if ($cardType != self::TYPE_BOMB && ($cardType != $lastType || $mainWeight <= $lastWeight)) {
                        throw new \Exception("所出牌管不起", -1); // 非炸弹需同类型且权重大于上一轮
                    }
                }
            }
        }

        // 修复：基于唯一text过滤已出牌（确保彻底移除）
        $selectedTexts = array_column($selectedCards, 'text');
        $this->players[$playerIndex] = array_values(array_filter(
            $this->players[$playerIndex],
            function($card) use ($selectedTexts) {
                return !in_array($card['text'], $selectedTexts);
            }
        ));

        // 更新游戏状态
        $this->lastCards = $selectedCards;
        $this->lastCardType = $cardType;
        $this->lastCardWeight = $mainWeight;
        $this->currentPlayer = ($this->currentPlayer + 1) % 3;
        // echo $this->getCardTypeName($this->lastCardType), ' ', join(' ', $selectedTexts);
        return true;
    }*/
    public function pass($playerIndex) {
	    // 校验当前玩家是否有权操作
	    if ($playerIndex != $this->currentPlayer) {
	        throw new \Exception("当前不是你的回合，无法要不起", -1);
	    }
	    
	    // 校验是否有上一轮出牌（无牌可压时不能要不起）
	    if (empty($this->lastCards)) {
	        throw new \Exception("首轮出牌不能要不起", -1);
	    }
	
	    // 记录要不起的玩家
	    $this->passPlayers[] = $playerIndex;
	    /*
	    if(count($this->passPlayers) == 2) {
	        throw new \Exception("success", 1);
	    }
	    */
		print_r($this->passPlayers);
	    // 判断是否所有其他玩家都要不起（当前玩家 == 上一轮出牌玩家时，本轮结束）
	    if ($this->currentPlayer == $this->lastPlayer) {
	        // 本轮结束，清空上一轮出牌记录，由本轮获胜玩家继续出牌
	        $this->resetPass();
	        // return false;
	        throw new \Exception("现在轮到你出牌了，不能要不起", -1);
	    } else if(count($this->passPlayers) >= 2) {
	        $this->resetPass();
	    } else {
	        // 切换到下一位玩家
	        $this->currentPlayer = ($this->currentPlayer + 1) % 3;
	    }
	
	    return true;
	}
	public function resetPass() {
		$this->lastCards = [];
        $this->lastCardType = 0;
        $this->lastCardWeight = 0;
        $this->passPlayers = []; // 重置要不起记录
        // 当前玩家保持为上一轮出牌玩家（继续出牌）
        $this->currentPlayer = $this->lastPlayer;
	}
    // 牌型名称映射
    public function getCardTypeName($type) {
        $names = [
            self::TYPE_SINGLE => '单张',
            self::TYPE_PAIR => '对子',
            self::TYPE_TRIPLE => '三张',
            self::TYPE_STRAIGHT => '顺子',
            self::TYPE_BOMB => '炸弹',
            self::TYPE_ROCKET => '火箭',
            self::TYPE_TRIPLE_WITH_SINGLE => '三带一',
            self::TYPE_TRIPLE_WITH_PAIR => '三带二',
            self::TYPE_FOUR_WITH_SINGLE => '四带二',
            self::TYPE_FOUR_WITH_PAIR => '四带两对',
            self::TYPE_PLANE_SINGLE => '飞机带单',
            self::TYPE_PLANE_PAIR => '飞机带对',
            self::TYPE_PLANE_3_SINGLE => '三顺带单',
            self::TYPE_PLANE_3_PAIR => '三顺带对'
        ];
        return $names[$type] ?? '未知牌型';
    }
    
    // 检查游戏是否结束
    public function isGameOver() {
        foreach ($this->players as $index => $player) {
            if (count($player) == 0) {
                return $index == $this->landlord ? '地主获胜' : '农民获胜';
            }
        }
        return false;
    }

    // 获取玩家手牌文本
    public function getPlayerCardsText($playerIndex) {
        return array_column($this->players[$playerIndex], 'text');
    }

    // 获取底牌文本
    public function getBaseCardsText() {
        return array_column($this->baseCards, 'text');
    }

    // 新增：获取上一轮出牌文本（方便调试）
    public function getLastCardsText() {
        return array_column($this->lastCards, 'text');
    }
    /**
	 * 获取上一轮出牌的详细信息
	 * @return array 包含牌文本和牌型的数组
	 */
	public function getLastPlayedCards() {
	    if (empty($this->lastCards)) {
	        return ['cards' => [], 'type' => '无出牌记录'];
	    }
	    return [
	        'cards' => array_column($this->lastCards, 'text'), // 牌的文本（如♠3、♥5）
	        'type' => $this->getCardTypeName($this->lastCardType) // 牌型名称（如“飞机带单”）
	    ];
	}
	public function getCurrentPlayer() {
		return $this->currentPlayer;
	}
	public function getPassPlayers() {
		return $this->passPlayers;
	}
	public function getLandlord() {
		return $this->landlord;
	}
	public function getCurrentBidPlayer() {
		return $this->currentBidPlayer;
	}
	/**
	 * 将传入的字符串分割为牌数组（支持大王、小王和普通牌）
	 * @param string $input 输入字符串（如"大王3小王K"）
	 * @return array 分割后的牌数组（如["大王", "3", "小王", "K"]）
	 */
	public function split($input) {
	    $cards = [];
	    $length = mb_strlen($input, 'UTF-8'); // 使用mb_strlen获取多字节长度
	    $i = 0;
	
	    while ($i < $length) {
	        // 优先匹配双字符中文关键词：大王、小王
	        if ($i + 1 < $length) {
	            // 使用mb_substr截取2个中文汉字（每个汉字占1个多字节长度）
	            $twoChars = mb_substr($input, $i, 2, 'UTF-8'); 
	            if ($twoChars === '大王' || $twoChars === '小王' || $twoChars === "10") {
	                $cards[] = $twoChars;
	                $i += 2; // 跳过2个字符（中文“大王”“小王”各占1个长度单位）
	                continue;
	            }
	        }
	
	        // 单字符处理（中文/英文/数字）
	        $singleChar = mb_substr($input, $i, 1, 'UTF-8'); // 截取1个多字节字符
	        $cards[] = $singleChar;
	        $i += 1;
	    }
	
	    return $cards;
	}
    // 序列化方法（必须实现，确保游戏状态可缓存）
    public function __serialize() {
        return [
            'cards' => $this->cards,
            'players' => $this->players,
            'landlord' => $this->landlord,
            'baseCards' => $this->baseCards,
            'currentPlayer' => $this->currentPlayer,
            'lastCards' => $this->lastCards,
            'lastCardType' => $this->lastCardType,
            'lastCardWeight' => $this->lastCardWeight,
            'bidStatus' => $this->bidStatus,
            'currentBidPlayer' => $this->currentBidPlayer,
            'highestBid' => $this->highestBid,
            'highestBidPlayer' => $this->highestBidPlayer,
            'bidRound' => $this->bidRound,
            'lastPlayer' => $this->lastPlayer,
            'passPlayers' => $this->passPlayers,
        ];
    }
    // 反序列化方法（必须实现，从缓存恢复游戏状态）
    public function __unserialize(array $data) {
        foreach ($data as $key => $value) {
            $this->$key = $value;
        }
    }
    public function serialize() {
        return serialize($this->__serialize);
    }
    // 反序列化方法（必须实现，从缓存恢复游戏状态）
    public function unserialize($data) {
        $unserialized = unserialize($data);
        $this->__unserialize($data);
    }
}