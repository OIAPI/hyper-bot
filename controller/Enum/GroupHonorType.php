<?php
namespace Controller\Enum;

enum GroupHonorType: string {
	case ALL = 'all'; // 所有
	case TALKATIVE = 'talkative'; // 龙王
	case PERFORMER = 'performer'; // 应该是福禄寿
	case LEGEND = 'legend'; // 火苗
	case NEWBIE = 'strong_newbie'; //新人王
	case EMOTION = 'emotion'; //快乐源泉
}