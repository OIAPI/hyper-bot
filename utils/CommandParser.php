<?php
namespace Utils;

/**
 * @desc	命令行字符串解析器（支持参数注册/校验，类似yargs）
 * @author  deepseek v3
 * @date	2025-06-03
 */
class CommandParser
{
	private static $instance = null;
	// 新增：存储注册的参数规则（key=参数名，value=规则配置）
	private $optionRules = [];
	// 新增：存储注册的命令描述（用于生成帮助信息）
	private $commandDesc = '';

	private function __construct() {}

	public static function getInstance(): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 新增：注册命令描述（用于生成帮助）
	 * @param string $desc 命令功能描述
	 * @return $this
	 */
	public function command(string $desc): self
	{
		$this->commandDesc = $desc;
		return $this;
	}

	/**
	 * 新增：注册参数规则（核心方法，类似yargs.option）
	 * @param string $name 参数名（短选项用单字符，长选项用多字符，如 'n' 或 'name'）
	 * @param array $rule 规则配置，支持：
	 *					- type: 类型（string/int/boolean，默认string）
	 *					- alias: 别名（如长选项'name'的别名为'n'）
	 *					- default: 默认值（未传参时使用）
	 *					- required: 是否必填（bool，默认false）
	 *					- desc: 参数描述（用于生成帮助）
	 * @return $this
	 */
	public function option(string $name, array $rule = []): self
	{
		// 补全默认规则
		$defaultRule = [
			'type' => 'string',
			'alias' => '',
			'default' => null,
			'required' => false,
			'desc' => ''
		];
		$this->optionRules[$name] = array_merge($defaultRule, $rule);

		// 处理别名映射（如注册'name'时，若alias为'n'，则反向注册'n'的别名指向'name'）
		if (!empty($rule['alias'])) {
			$alias = $rule['alias'];
			$this->optionRules[$alias] = array_merge($defaultRule, [
				'type' => $rule['type'],
				'alias' => $name, // 别名的alias指向主参数名
				'default' => $rule['default'],
				'required' => $rule['required'],
				'desc' => $rule['desc']
			]);
		}
		return $this;
	}

	/**
	 * 新增：生成帮助信息（类似yargs --help）
	 * @return string
	 */
	public function getHelp(): string
	{
		$help = ["\n命令描述：{$this->commandDesc}\n"];
		$help[] = "参数说明：";
		foreach ($this->optionRules as $name => $rule) {
			// 跳过别名重复项（只显示主参数）
			if (!empty($rule['alias']) && $rule['alias'] !== $name) {
				continue;
			}
			// 格式化参数显示（短选项用 -x，长选项用 --xxx）
			$optStr = strlen($name) === 1 ? "-{$name}" : "--{$name}";
			// 补充别名
			if (!empty($rule['alias'])) {
				$aliasStr = strlen($rule['alias']) === 1 ? "-{$rule['alias']}" : "--{$rule['alias']}";
				$optStr .= " / {$aliasStr}";
			}
			// 补充类型/必填/默认值
			$meta = [
				"类型: {$rule['type']}",
				$rule['required'] ? "必填" : "可选",
				$rule['default'] !== null ? "默认: {$rule['default']}" : ""
			];
			$metaStr = implode(' | ', array_filter($meta));
			// 拼接行
			$help[] = sprintf("  %-15s %-20s %s", $optStr, $metaStr, $rule['desc']);
		}
		return implode("\n", $help) . "\n";
	}

	/**
	 * 原解析方法：新增「规则校验」和「默认值填充」
	 */
	public function parse(string $input): array
	{
		$input = trim($input);
		if (empty($input)) {
			return $this->fillDefaultAndValidate([]);
		}

		$tokens = $this->tokenize($input);
		$rawResult = $this->parseTokens($tokens);
		// 新增：填充默认值 + 校验参数（核心优化点）
		return $this->fillDefaultAndValidate($rawResult);
	}

	/**
	 * 原方法：无修改（token分解）
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

		return $tokens;
	}

	/**
	 * 原方法：无修改（token解析为命令/参数/选项）
	 */
	private function parseTokens(array $tokens): array
	{
		if (empty($tokens)) {
			return [];
		}

		$result = [
			'command' => $tokens[0] ?? '',
			'arguments' => [],
			'options' => [],
		];

		for ($i = 1; $i < count($tokens); $i++) {
			$token = $tokens[$i];

			if (str_starts_with($token, '--')) {
				$this->parseLongOption($token, $result);
			} elseif (str_starts_with($token, '-')) {
				$this->parseShortOption($token, $result, $tokens, $i);
			} else {
				$result['arguments'][] = $token;
			}
		}

		return $result;
	}

	/**
	 * 原方法：无修改（解析长选项）
	 */
	private function parseLongOption(string $token, array &$result)
	{
		$option = substr($token, 2);
		$parts = explode('=', $option, 2);

		$name = $parts[0];
		$value = count($parts) > 1 ? $parts[1] : true;

		$result['options'][$name] = $value;
	}

	/**
	 * 原方法：无修改（解析短选项）
	 */
	private function parseShortOption(string $token, array &$result, array $tokens, int &$index)
	{
		$option = substr($token, 1);

		if (isset($tokens[$index + 1]) && !str_starts_with($tokens[$index + 1], '-')) {
			$result['options'][$option] = $tokens[$index + 1];
			$index++;
		} else {
			$result['options'][$option] = true;
		}
	}

	/**
	 * 新增：填充默认值 + 校验参数（核心逻辑）
	 * @param array $rawResult 原始解析结果
	 * @return array 最终处理结果（含默认值+类型转换）
	 * @throws \InvalidArgumentException 校验失败时抛出异常
	 */
	private function fillDefaultAndValidate(array $rawResult): array
	{
		$result = $rawResult;
		$parsedOptions = $result['options'] ?? [];

		// 1. 处理别名（将别名参数映射到主参数名）
		$mappedOptions = [];
		foreach ($parsedOptions as $name => $value) {
			$rule = $this->optionRules[$name] ?? null;
			if ($rule && !empty($rule['alias']) && $rule['alias'] !== $name) {
				// 别名指向主参数名（如 -n 映射到 name）
				$mappedName = $rule['alias'];
				$mappedOptions[$mappedName] = $value;
			} else {
				$mappedOptions[$name] = $value;
			}
		}

		// 2. 填充默认值 + 类型转换 + 校验必填
		foreach ($this->optionRules as $name => $rule) {
			// 跳过别名（只处理主参数）
			if (!empty($rule['alias']) && $rule['alias'] !== $name) {
				continue;
			}

			// 优先用解析的值，无值则用默认值
			$value = $mappedOptions[$name] ?? $rule['default'];

			// 校验必填
			if ($rule['required'] && $value === null) {
				throw new \InvalidArgumentException("参数 --{$name}（或 -{$rule['alias']}）为必填项");
			}

			// 类型转换（如int类型需转为数字，boolean需转为bool）
			switch ($rule['type']) {
				case 'int':
					$value = $value === null ? null : (int)$value;
					break;
				case 'boolean':
					$value = $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN);
					break;
				case 'string':
				default:
					$value = $value === null ? null : (string)$value;
					break;
			}

			$mappedOptions[$name] = $value;
		}

		// 3. 更新结果中的options（移除别名，只保留主参数）
		$result['options'] = $mappedOptions;
		return $result;
	}
}