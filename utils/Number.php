<?php
namespace Utils;
/**
 * @note:由于项目需要，需要一个数字转成汉字的功能，类似19.88元转成十九点八八元这样的功能，
 * @note:经过长时间的网上翻找资料总没有合适的例子，于是决定自己来造这个轮子
 * @note:方法使用参照demo的调用方式，需求所限这里最高只支持到亿这一数位级别
 * @note:如果您有更多的需要请自行扩展config相关配置项
 *
 * @note:这里强调一点如果有小数推荐使用数字字符串格式，否则小数会丢失精度
 *
 * @date 2022/12/07
 *
 * @auther:qianli
 *
 * @email:cz_liqian@sina.com
 */

class Number {
	
	private $chinese = [];
	private $finance = [];
	private $unit = [];
	private $financeUnit = [];
	private $groupUnit = [];
	private $config = array(
		"chinese" => ["零","一","二","三","四","五","六","七","八","九"],	//普通汉字数字
		"finance" => ["零","壹","贰","叁","肆","伍","陆","柒","捌","玖"],	//财务格式汉字数字
		"unit" => ["亿","千万","百万","十万","万","千","百","十",""],		//普通格式单位
		"financeUnit" => ["亿","仟万","佰万","拾万","万","仟","佰","拾",""],//财务格式单位
		"groupUnit" => ['亿', '万', ''] //组单位，数字是从后向前每4位一个级别，万，亿，兆依次后推
	);

	public function __construct(){
		$this->chinese = $this->config['chinese'];
		$this->finance = $this->config['finance'];
		$this->unit = $this->config['unit'];
		$this->financeUnit = $this->config['financeUnit'];
		$this->groupUnit = $this->config['groupUnit'];
	}

	/**
	 * 数字字符串转成汉字字符串
	 *
	 * @param float $number 要进行转化的数字字符串（这里请使用字符串，纯数字可能会丢失精度）
	 * @param int $type	处理类型 1普通汉字字符串，2财务类型写法字符串
	 * @return string
	 */
	public function n2c($number,$type=1){
		
		//拆分数字
		$numberArr = $this->cutNumber($number);
		if(empty($numberArr)){
			return throw new \Exception("无效数字");
		}
		
		$numberInt = $numberArr[0];

		$numberFloat = $numberArr[1];

		$intNumber = $this->disIntNumber($numberInt,$type);

		if(!empty($numberFloat)){
			$floatNumber = $this->disFloatNumber($numberFloat,$type);

			return $intNumber."点".$floatNumber;
		}

		return $intNumber;
	}

	/**
	 * 数字字符串转成财务格式汉字字符串
	 *
	 * @param float $number 要进行转化的数字字符串（这里请使用字符串，纯数字可能会丢失精度）
	 * @return string
	 */
	public function n2f($number){
		return $this->n2c($number,2);
	}

	/**
	 * 整数部分处理
	 *
	 * @param float $number 要进行处理的数字字符串
	 * @param int $type	处理类型 1普通汉字字符串，2财务类型写法字符串
	 * @return string
	 */
	private function disIntNumber($number,$type=1){
		if(empty($number)){
			return null;
		}

		$allArr = array();

		//按位拆数字
		$cellList = $this->cutNumberToCell($number);
		if(empty($cellList)){
			return null;
		}

		$len = count($cellList);
		
		//对[1]数组进行处理，从后向前每4位截取一段
		$group = $this->getNumberGroup($cellList);
		if(empty($group)){
			return null;
		}
		

		//分别循环每个组的数据组成最终的结果
		foreach($group as $gk=>$item){
			$relstr = $this->disGroupNumber($item,$type);
			if(!empty($relstr)){
				$allArr[] = $relstr.$this->groupUnit[$gk];
				
			}
		}
		
		return implode("",array_reverse($allArr));
	}


	/**
	 * 将分好组的数字逐组处理成汉字字符串
	 *
	 * @param array $group 要进行处理的数字数组
	 * @param int $type	处理类型 1普通汉字字符串，2财务类型写法字符串
	 * @return string
	 */
	private function disGroupNumber($group,$type=1){
		if(empty($group)){
			return null;
		}

		$zero = false;
		$numStr = "";

		$units = $this->getUnitList(count($group),$type);
		if(empty($units)){
			return null;
		}

		//数字逐位转汉字
		$chineseList = $this->replaceNumberToChinese($group,$type);
		if(empty($chineseList)){
			return null;
		}

		foreach($chineseList as $ck=>$cn){
			if($cn == '零' && $zero == false){
				$zero = true;
				$numStr .= $cn;
			}else{
				if($cn == '零'){
					continue;
				}else{
					$numStr .= $cn.$units[$ck];
				}
			}
		}

		//结束剔除字符串末尾的零
		$numStr = rtrim($numStr,"零");

		return $numStr;
	}


	/**
	 * 从后向前每4位截取一段
	 *
	 * @param array $cellList 要进行处理的数组
	 * @return array
	 */
	private function getNumberGroup($cellList){
		if(empty($cellList)){
			return null;
		}

		$oldNumList = array_reverse($cellList);
		$chunkNumList = array_chunk($oldNumList,4);

		$total = count($chunkNumList);
		$group = array();
		foreach($chunkNumList as $key=>$chunkItem){
			$group[$total - $key -1] = array_reverse($chunkItem);
		}

		return $group;
	}


	/**
	 * 根据类型不同获取相应的单位列表
	 *
	 * @param int $len 数字位数
	 * @param int $type	处理类型 1普通汉字字符串，2财务类型写法字符串
	 * @return array
	 */
	private function getUnitList($len,$type=1){
		if(empty($len)){
			return null;
		}

		if($type == 1){
			$oldList = $this->unit;
		}else{
			$oldList = $this->financeUnit;
		}
		$oldList = array_reverse($oldList);
		$newList = array_slice($oldList,0,$len);
		$newList = array_reverse($newList);
		return $newList;
	}

	
	/**
	 * 处理小数部分
	 *
	 * @param int $number 要处理的数字串
	 * @return string
	 */
	private function disFloatNumber($number,$type){
		if(empty($number)){
			return null;
		}

		//按位拆数字
		$cellList = $this->cutNumberToCell($number);
		if(empty($cellList)){
			return null;
		}

		//按位替换汉字
		$chineseList = $this->replaceNumberToChinese($cellList,$type);
		if(empty($chineseList)){
			return null;
		}

		return implode("",$chineseList);
	}

	/**
	 * 将数字逐个替换成汉字
	 *
	 * @param array $cellList 要进行处理的数组
	 * @param int $type	处理类型 1普通汉字字符串，2财务类型写法字符串
	 * @return array
	 */
	private function replaceNumberToChinese($cellList,$type){
		if(empty($cellList)){
			return null;
		}

		$result = array();

		if($type == 1){
			foreach($cellList as $key=>$num){
				$result[$key] = $this->chinese[$num];
			}
		}else{
			foreach($cellList as $key=>$num){
				$result[$key] = $this->finance[$num];
			}
		}

		return $result;
	}


	/**
	 * 将数字字符串拆成数字数组
	 *
	 * @param int $number 要处理的数字串
	 * @return array
	 */
	private function cutNumberToCell($number){
		if(empty($number)){
			return null;
		}

		$numberList = str_split($number,1);
		return $numberList;
	}
	
	/**
	 * 将原始数字字符串拆成整数部分和小数部分
	 *
	 * @param int $number 要处理的数字串
	 * @return array
	 */
	private function cutNumber($number,$symbols='.'){
		if(strpos($number,$symbols) === false){
			return [$number,null];
		}

		return explode($symbols,$number);
	}

	/**
	 * 将汉字或财务格式的数字转化成阿拉伯数字
	 *
	 * @param string $str 要处理的字符串
	 * @return number
	 */
	public function c2n($str){
		if(is_numEric($str)) return $str;
		//判断是否是财务格式如果是财务格式先转成正常汉字格式
		$str = $this->financeToChinese($str);
		
		//判断是否有点
		$strArr = $this->cutNumber($str,"点");

		$intStr = $strArr[0];
		$floatStr = $strArr[1];

		//整数位需要处理
		$intNumber = $this->disIntStr($intStr);

		if(!empty($floatStr)){
			//处理小数，小数位直接替换成数字
			$floatNumber = $this->replaceChineseToNumber($floatStr);

			return $intNumber.".".$floatNumber;
		}

		return $intNumber;
		
	}

	/**
	 * 将财务格式的数字转化成汉字格式数字
	 *
	 * @param string $str 要处理的字符串
	 * @return string
	 */
	public function financeToChinese($str){
		if(empty($str)){
			return null;
		}

		$str = str_replace($this->finance,$this->chinese,$str);
		$str = str_replace($this->financeUnit,$this->unit,$str);

		return $str;
	}

	/**
	 * 处理汉字数字的整数部分
	 *
	 * @param string $str 要处理的字符串
	 * @return number
	 */
	private function disIntStr($str){
		if(empty($str)){
			return null;
		}
		$endNum = 0;
		//按指定段分割字符串[亿，万]
		$strArr = $this->cutIntStrByUnit($str,$this->groupUnit);
		if(empty($strArr['arr'])){
			return null;
		}

		foreach($strArr['arr'] as $mk=>$min){
			//将分割好的字符串组进行二次分割，按[千，百，十]进行分割
			$minStrArr = $this->cutIntStrByUnit($min,$this->unit);
			if(empty($minStrArr['arr'])){
				continue;
			}else{
				$subNum = 0;
				foreach($minStrArr['arr'] as $ck=>$cv){
					//汉字转数字,追加单位相乘
					$num = $this->chineseReplaceToNumber($cv,$minStrArr['break'],$ck);
					//将同一个foreach下的数加起来
					$subNum += $num;
				}

				//对单一大组的数据进行单位相乘处理
				$multiple = $this->unitAndMultiple($strArr['break'],$mk);
				$endNum += $subNum * $multiple;
			}
		}

		//将所有结果相加获取最终的实际金额
		return $endNum;
	}

	/**
	 * 将对应汉字位置上的数字转成正常数字，例如 七千零一 转成7001
	 *
	 * @param string $str 要处理的字符串
	 * @param array $unitArr 对应倍率单位组
	 * @param int $index 当前数字在倍率组中对应的位置
	 * @return number
	 */
	private function chineseReplaceToNumber($str,$unitArr,$index){
		if(empty($str)){
			return null;
		}
		$str = str_replace('两', '二', $str);
		$strArr = str_split($str,3);
		if(count($strArr) == 1){
			$realnum = array_search($str,$this->chinese);
		}else{
			$realnum = 0;
			foreach($strArr as $sub){
				$realnum += array_search($sub, $this->chinese);
			}
		}

		$multiple = $this->unitAndMultiple($unitArr,$index);

		return $realnum * $multiple;
		
	}

	/**
	 * 根据单位计算倍率
	 *
	 * @param array $unitArr 对应倍率单位组
	 * @param int $index 当前数字在倍率组中对应的位置
	 * @return number
	 */
	private function unitAndMultiple($unitArr, $index){
		// print_r($unitArr);
		if(!empty($unitArr) && isset($unitArr[$index])){
			$len = count($this->unit) - array_search(
			$unitArr[$index],
			$this->unit) - 1;
			$multiple = "1".str_repeat("0",$len);
			return $multiple;
		}else{
			return 1;
		}
	}

	/**
	 * 按单位进行数据拆分
	 *
	 * @param string $str 要拆分的字符串
	 * @param array $midArr 拆分依据单位数组
	 * @return number
	 */
	private function cutIntStrByUnit($str, $midArr){
		if(empty($str)){
			return null;
		}
		if($str === "十") $str = "一十";

		$str = str_replace($midArr, array_keys($midArr), $str);
		$isMatched = preg_match_all('/\d/', $str, $matches);
		if(!$isMatched){
			//没有匹配的内容表示未进行任何替换,则表示这是个位数单位给空
			return ['arr'=>[$str],'break'=>[""]];
		}

		$unitKeys = $matches[0];

		$str = str_replace($unitKeys,"*",$str);

		foreach($midArr as $k=>$item){
			if(in_array($k,$unitKeys)){
				$break[] = $item;
			}
		}
		
		//获取字符串中的数字符号，将之转化成对应的单位保存起来
		return ['arr'=>explode("*",$str),'break'=>$break];
	}

	/**
	 * 将汉字转成数字
	 *
	 * @param string $str 要转化的字符串
	 * @return number
	 */
	private function replaceChineseToNumber($str){
		if(empty($str)){
			return null;
		}

		$strArr = str_split($str,3);
		$endArr = array();

		foreach($strArr as $k=>$v){
			$realnum = array_search($v,$this->chinese);
			$endArr[$k] = $realnum;
		}

		return implode("",$endArr);
	}

}