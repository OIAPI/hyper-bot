<?php
namespace Utils;

/**
 * @desc	命令行字符串解析器（增强版，支持参数校验/命令嵌套/位置参数）
 * @author  deepseek v3
 * @date	2025-06-04
 */
class Yargs
{
	private static $instance = null;
	private $optionRules = [];		 // 选项参数规则（key=参数名）
	private $positionalRules = [];	 // 位置参数规则（按顺序存储）
	private $commandDesc = '';		 // 当前命令描述
	private $subCommands = [];		 // 子命令配置（key=子命令名，value=子解析器）
	private $currentCommandPath = '';  // 当前命令路径（如"user add"）

	private function __construct(string $commandPath = '')
	{
		$this->currentCommandPath = trim($commandPath, '/');
	}

	// 单例模式（支持子命令独立实例）
	public static function getInstance(string $commandPath = ''): self
	{
		if (empty($commandPath)) {
			if (self::$instance === null) {
				self::$instance = new self();
			}
			return self::$instance;
		}
		// 子命令实例独立存储，避免冲突
		static $subInstances = [];
		if (!isset($subInstances[$commandPath])) {
			$subInstances[$commandPath] = new self($commandPath);
		}
		return $subInstances[$commandPath];
	}

	/**
	 * 注册命令描述（支持子命令）
	 */
	public function command(string $command, string $desc, callable $callback = null): self
	{
		$command = trim(trim($command), '/');
		
		if ($callback) {
			// 创建子命令解析器 - 修复命令路径构建
			$fullPath = $this->currentCommandPath ? $this->currentCommandPath . ' ' . $command : $command;
			$subParser = self::getInstance($fullPath);
			$subParser->commandDesc = $desc;
			
			// 存储子命令
			$this->subCommands[$command] = $subParser;
			
			// 配置子命令
			$callback($subParser);
		} else {
			// 设置当前命令描述
			$this->commandDesc = $desc;
		}
		return $this;
	}

	/**
	 * 注册选项参数规则
	 */
	public function option(string $name, array $rule = []): self
	{
		$defaultRule = [
			'type' => 'string',
			'alias' => '',
			'default' => null,
			'required' => false,
			'desc' => '',
			'min' => null,
			'max' => null,
			'pattern' => null,
			'choices' => null
		];
		
		$finalRule = array_merge($defaultRule, $rule);
		$this->optionRules[$name] = $finalRule;
	
		// 处理别名
		if (!empty($finalRule['alias'])) {
			$alias = $finalRule['alias'];
			$this->optionRules[$alias] = ['_is_alias' => true, '_points_to' => $name];
		}
		
		return $this;
	}

	/**
	 * 注册位置参数规则
	 */
	public function positional(string $name, array $rule = []): self
	{
		$defaultRule = [
			'type' => 'string',
			'required' => true,
			'desc' => '',
			'choices' => null,
			'default' => null
		];
		
		// 检查是否已经存在同名位置参数
		$existingIndex = null;
		foreach ($this->positionalRules as $index => $existingRule) {
			if ($existingRule['name'] === $name) {
				$existingIndex = $index;
				break;
			}
		}
		
		$newRule = array_merge($defaultRule, $rule, ['name' => $name]);
		
		if ($existingIndex !== null) {
			// 更新现有规则
			$this->positionalRules[$existingIndex] = $newRule;
		} else {
			// 添加新规则
			$this->positionalRules[] = $newRule;
		}
		
		return $this;
	}

	/**
	 * 生成帮助信息
	 */
	public function getHelp(): string
	{
		$help = ["\n命令：{$this->currentCommandPath}"];
		$help[] = "描述：{$this->commandDesc}\n";

		// 子命令列表
		if (!empty($this->subCommands)) {
			$help[] = "子命令：";
			foreach ($this->subCommands as $name => $parser) {
				$help[] = sprintf("  %-10s %s", $name, $parser->commandDesc);
			}
			$help[] = "";
		}

		// 位置参数说明
		if (!empty($this->positionalRules)) {
			$help[] = "位置参数：";
			foreach ($this->positionalRules as $rule) {
				$meta = [
					"类型: {$rule['type']}",
					$rule['required'] ? "必填" : "可选",
					$rule['choices'] ? "可选值: " . implode(',', $rule['choices']) : ""
				];
				$metaStr = implode(' | ', array_filter($meta));
				$help[] = sprintf("  %-10s %-20s %s", $rule['name'], $metaStr, $rule['desc']);
			}
			$help[] = "";
		}

		// 选项参数说明
		if (!empty($this->optionRules)) {
			$help[] = "选项参数：";
			
			$displayedOptions = [];
			
			foreach ($this->optionRules as $name => $rule) {
				if (isset($rule['_is_alias']) || in_array($name, $displayedOptions)) {
					continue;
				}
				
				$displayedOptions[] = $name;
				
				$optStr = strlen($name) === 1 ? "-{$name}" : "--{$name}";
				
				// 查找别名
				$aliases = [];
				foreach ($this->optionRules as $aliasName => $aliasRule) {
					if (isset($aliasRule['_is_alias']) && $aliasRule['_points_to'] === $name) {
						$aliasDisplay = strlen($aliasName) === 1 ? "-{$aliasName}" : "--{$aliasName}";
						$aliases[] = $aliasDisplay;
					}
				}
				
				if (!empty($aliases)) {
					$optStr .= " / " . implode(' / ', $aliases);
				}
		
				$meta = [
					"类型: {$rule['type']}",
					$rule['required'] ? "必填" : "可选",
					$rule['default'] !== null ? "默认: {$rule['default']}" : "",
					$rule['choices'] ? "可选值: " . implode(',', $rule['choices']) : ""
				];
				$metaStr = implode(' | ', array_filter($meta));
				
				$help[] = sprintf("  %-15s %-25s %s", $optStr, $metaStr, $rule['desc']);
			}
		}

		return implode("\n", $help) . "\n";
	}

	/**
	 * 解析入口
	 */
	public function parse(string $input): array
	{
		$input = trim($input);
		if ($input === '--help' || $input === '-h') {
			return $this->getHelp();
		}

		$tokens = $this->tokenize($input);
		$rawResult = $this->parseTokens($tokens);
		// 检查子命令
		$firstArgument = $rawResult['arguments'][0] ?? null;
		if ($firstArgument && isset($this->subCommands[$tokens[0]])) {
			// 构建子命令输入（移除当前命令和子命令名）
			$baseCommand = $this->currentCommandPath ?: ($tokens[0] ?? '');
			echo '子命令：'; var_dump($baseCommand);
			$toRemove = $baseCommand ? $baseCommand . ' ' . $firstArgument : $firstArgument;
			$subInput = trim(str_replace($toRemove, '', $input));
			
			// 解析子命令
			$subResult = $this->subCommands[$baseCommand]->parse($subInput);
			
			return [
				'command' => $baseCommand,
				'arguments' => [],
				'options' => $rawResult['options'],
				'sub' => $subResult,
			];
		}

		// 正常处理当前命令
		return $this->fillDefaultAndValidate($rawResult);
	}

	/**
	 * Token分解
	 */
	private function tokenize(string $input): array
	{
		$tokens = [];
		$length = strlen($input);
		$currentToken = '';
		$inQuotes = false;
		$quoteChar = '';

		for ($i = 0; $i < $length; $i++) {
			$char = $input[$i];

			if ($char === '\\' && $i < $length - 1) {
				$currentToken .= $input[$i + 1];
				$i++;
				continue;
			}

			if (($char === '"' || $char === "'") && !$inQuotes) {
				$inQuotes = true;
				$quoteChar = $char;
				continue;
			}
			if ($char === $quoteChar && $inQuotes) {
				$inQuotes = false;
				$quoteChar = '';
				continue;
			}

			if ($char === ' ' && !$inQuotes) {
				if ($currentToken !== '') {
					$tokens[] = $currentToken;
					$currentToken = '';
				}
				continue;
			}

			$currentToken .= $char;
		}

		if ($currentToken !== '') {
			$tokens[] = $currentToken;
		}

		// 拆分短选项组合
		$finalTokens = [];
		foreach ($tokens as $token) {
			if (str_starts_with($token, '-') && strlen($token) > 2 && !str_starts_with($token, '--')) {
				$chars = str_split(substr($token, 1));
				foreach ($chars as $c) {
					$finalTokens[] = "-{$c}";
				}
			} else {
				$finalTokens[] = $token;
			}
		}

		return $finalTokens;
	}

	/**
	 * Token解析
	 */
	private function parseTokens(array $tokens): array
	{
		if (empty($tokens)) {
			return ['command' => '', 'arguments' => [], 'options' => []];
		}

		$result = [
			'command' => $tokens[0],
			'arguments' => [],
			'options' => [],
		];

		for ($i = 1; $i < count($tokens); $i++) {
			$token = $tokens[$i];

			if (str_starts_with($token, '--')) {
				$option = substr($token, 2);
				if (str_starts_with($option, 'no-')) {
					$name = substr($option, 3);
					$result['options'][$name] = false;
					continue;
				}
				$parts = explode('=', $option, 2);
				$name = $parts[0];
				$value = count($parts) > 1 ? $parts[1] : true;
				$result['options'][$name] = $value;
			}
			elseif (str_starts_with($token, '-')) {
				$option = substr($token, 1);
				if (isset($tokens[$i + 1]) && !str_starts_with($tokens[$i + 1], '-')) {
					$result['options'][$option] = $tokens[$i + 1];
					$i++;
				} else {
					$result['options'][$option] = true;
				}
			}
			else {
				$result['arguments'][] = $token;
			}
		}

		return $result;
	}

	/**
	 * 填充默认值+校验参数
	 */
	private function fillDefaultAndValidate(array $rawResult): array
	{
		$result = $rawResult;
		$parsedOptions = $result['options'] ?? [];
		$parsedArguments = $result['arguments'] ?? [];

		// 处理选项参数别名映射
		$mappedOptions = [];
		foreach ($parsedOptions as $name => $value) {
			$rule = $this->optionRules[$name] ?? null;
			if ($rule && !empty($rule['alias']) && $rule['alias'] !== $name) {
				$mappedName = $rule['alias'];
				$mappedOptions[$mappedName] = $value;
			} else {
				$mappedOptions[$name] = $value;
			}
		}

		// 选项参数处理
		foreach ($this->optionRules as $name => $rule) {
			if (isset($rule['_is_alias']) || !isset($rule['type'])) {
				continue;
			}
			
			if (!empty($rule['alias']) && $rule['alias'] !== $name) {
				continue;
			}

			$value = $mappedOptions[$name] ?? $rule['default'];

			if ($rule['required'] && $value === null) {
				throw new \InvalidArgumentException("参数 --{$name} 为必填项");
			}
			if ($value === null) {
				continue;
			}

			// 类型转换
			switch ($rule['type']) {
				case 'int':
					$value = (int)$value;
					break;
				case 'boolean':
					$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
					break;
				case 'string':
				default:
					$value = (string)$value;
					break;
			}

			// 校验逻辑
			if ($rule['type'] === 'int') {
				if ($rule['min'] !== null && $value < $rule['min']) {
					throw new \InvalidArgumentException("参数 --{$name} 最小值为 {$rule['min']}");
				}
				if ($rule['max'] !== null && $value > $rule['max']) {
					throw new \InvalidArgumentException("参数 --{$name} 最大值为 {$rule['max']}");
				}
			}

			if ($rule['type'] === 'string' && $rule['pattern'] !== null) {
				if (!preg_match($rule['pattern'], $value)) {
					throw new \InvalidArgumentException("参数 --{$name} 格式不符合要求");
				}
			}

			if ($rule['choices'] !== null && !in_array($value, $rule['choices'])) {
				throw new \InvalidArgumentException("参数 --{$name} 可选值为：" . implode(',', $rule['choices']));
			}

			$mappedOptions[$name] = $value;
		}

		// 位置参数处理
		$mappedArguments = [];
		foreach ($this->positionalRules as $index => $rule) {
			$value = $parsedArguments[$index] ?? $rule['default'];

			if ($rule['required'] && $value === null) {
				throw new \InvalidArgumentException("位置参数 {$rule['name']} 为必填项");
			}
			if ($value === null) {
				$mappedArguments[$rule['name']] = null;
				continue;
			}

			switch ($rule['type']) {
				case 'int':
					$value = (int)$value;
					break;
				case 'boolean':
				case 'bool':
					$value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
					break;
				default:
					$value = (string)$value;
					break;
			}

			if ($rule['choices'] !== null && !in_array($value, $rule['choices'])) {
				throw new \InvalidArgumentException("位置参数 {$rule['name']} 可选值为：" . implode(',', $rule['choices']));
			}

			$mappedArguments[$rule['name']] = $value;
		}

		// 多余位置参数校验
		if (count($parsedArguments) > count($this->positionalRules)) {
			$extraCount = count($parsedArguments) - count($this->positionalRules);
			throw new \InvalidArgumentException("存在未定义的位置参数（多余 {$extraCount} 个）");
		}

		$result['options'] = $mappedOptions;
		$result['arguments'] = $mappedArguments;
		return $result;
	}
}