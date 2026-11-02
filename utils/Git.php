<?php
namespace Utils;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Git 工具类：完整功能实现
 * 特性：单列模式 + Env 配置 + 自动提取仓库名 + 核心 Git 操作
 * 依赖：服务器安装 Git、PHP 开启 shell_exec()、项目支持 Env 类（如 ThinkPHP）
 */
class Git
{
	// 1. 单列模式核心 + 主路径存储
	private static ?self $instance = null;
	private ?string $basePath = null; // 仓库根目录（默认从 Env github.localPath 读取）
	private ?string $repo = null;

	/**
	 * 获取全局唯一实例
	 * @param string $basePath 手动指定主路径（可选，优先级高于 Env）
	 * @return self
	 */
	public static function getInstance(string $basePath = ''): self
	{
		if (self::$instance === null) {
			self::$instance = new self();
			// 初始化主路径：手动传参 → Env 配置
			$initPath = $basePath ?: Env::get('github.localPath', '');
			if (!empty($initPath)) {
				self::$instance->setBasePath($initPath);
			}
		}
		return self::$instance;
	}

	// 禁止外部实例化、克隆
	private function __construct() {}
	private function __clone() {}


	// 2. 主路径管理
	/**
	 * 设置/更新仓库根目录
	 * @param string $basePath 主路径（空则从 Env 重新读取）
	 * @return array ['success' => bool, 'msg' => string]
	 */
	public function setBasePath(string $basePath = ''): array
	{
		$targetPath = $basePath ?: Env::get('github.localPath', '');
		if (empty($targetPath)) {
			$this->basePath = null;
			return ['success' => false, 'msg' => '主路径未配置（需设置 Env github.localPath 或手动传参）'];
		}

		// 处理路径格式（统一末尾目录分隔符）
		if(!file_exists($targetPath)) Operate::dir($targetPath);
		$realPath = realpath($targetPath);
		if ($realPath === false) {
			$this->basePath = null;
			return ['success' => false, 'msg' => "主路径不存在：{$targetPath}"];
		}
		$this->basePath = rtrim($realPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		// 校验路径权限
		if (!is_writable($this->basePath)) {
			$this->basePath = null;
			return ['success' => false, 'msg' => "主路径无写入权限：{$this->basePath}"];
		}

		return ['success' => true, 'msg' => "主路径设置成功：{$this->basePath}"];
	}

	/**
	 * 获取当前主路径
	 * @return string|null 主路径（未配置则返回 null）
	 */
	public function getBasePath(): ?string
	{
		if ($this->basePath === null) {
			$this->setBasePath(); // 尝试从 Env 补全
		}
		return $this->basePath;
	}


	// 3. 核心辅助方法
	/**
	 * 从仓库 URL 提取默认仓库名（如 https://xxx/repo.git → repo）
	 * @param string $repoUrl 仓库 URL（HTTP/SSH）
	 * @return string 提取的仓库名
	 */
	private function extractDefaultRepoName(string $repoUrl): string
	{
		// 处理 SSH 格式（如 git@xxx:user/repo.git → repo）
		if (str_contains($repoUrl, ':')) {
			$explode = explode(':', $repoUrl);
			$repoUrl = end($explode);
		}
		// 去除 URL 末尾 .git 后缀，获取basename
		return trim(preg_replace('/\.git$/i', '', Basename($repoUrl)));
	}

	/**
	 * 解析仓库路径（主路径 + 仓库名）
	 * @param string $repoName 仓库名
	 * @return array ['success' => bool, 'msg' => string, 'path' => string|null]
	 */
	private function resolveRepoPath(string $repoName): array
	{
		$basePath = $this->getBasePath();
		if ($basePath === null) {
			return ['success' => false, 'msg' => '主路径未配置', 'path' => null];
		}

		$fullPath = $basePath . $repoName;
		return ['success' => true, 'msg' => '路径解析成功', 'path' => $fullPath];
	}

	/**
	 * 检查仓库有效性（是否为 Git 仓库）
	 * @param string $repoName 仓库名
	 * @return array ['success' => bool, 'msg' => string]
	 */
	private function checkRepoValid(string $repoName): array
	{
		$pathRes = $this->resolveRepoPath($repoName);
		if (!$pathRes['success']) {
			return $pathRes;
		}
		$localPath = $pathRes['path'];

		if (!is_dir($localPath)) {
			return ['success' => false, 'msg' => "仓库不存在：{$repoName}"];
		}
		if (!is_dir($localPath . '/.git')) {
			return ['success' => false, 'msg' => "不是有效 Git 仓库：{$repoName}（缺少 .git 目录）"];
		}
		return ['success' => true, 'msg' => '仓库有效'];
	}

	/**
	 * 统一执行 Shell 命令
	 * @param string $cmd 命令字符串
	 * @return array ['success' => bool, 'output' => string, 'error' => string]
	 */
	private function runCmd(string $cmd, int $timeout = 30): array
	{
		$channel = new \Swoole\Coroutine\Channel(1);
		
		go(function () use ($cmd, $timeout, $channel) {
			$cmd .= " 2>&1"; // 合并错误输出到标准输出
			
			$descriptorspec = [
				0 => ["pipe", "r"], // stdin
				1 => ["pipe", "w"], // stdout
				2 => ["pipe", "w"]  // stderr
			];
			
			$process = proc_open($cmd, $descriptorspec, $pipes);
			
			if (!is_resource($process)) {
				$channel->push([
					'success' => false,
					'output' => '',
					'error' => 'Failed to execute command'
				]);
				return;
			}
			
			// 设置非阻塞模式
			stream_set_blocking($pipes[1], false);
			stream_set_blocking($pipes[2], false);
			
			$output = '';
			$errorOutput = '';
			$startTime = time();
			$timedOut = false;
			
			// 使用协程定时器进行超时控制
			$timerId = \Swoole\Timer::after($timeout * 1000, function () use (&$timedOut, $process, &$output, &$errorOutput, $channel, $timeout) {
				$timedOut = true;
				if (is_resource($process)) {
					proc_terminate($process);
					$channel->push([
						'success' => false,
						'output' => $output,
						'error' => "Command timed out after {$timeout} seconds" . ($errorOutput ? ": " . $errorOutput : ''),
						'delete' => true,
					]);
				}
			});
			
			// 使用协程循环读取输出
			while (true) {
				$status = proc_get_status($process);
				
				if (!$status['running'] || $timedOut) {
					break;
				}
				
				// 读取标准输出
				$readPipes = [$pipes[1], $pipes[2]];
				$writePipes = $exceptPipes = null;
				
				// 使用 stream_select 进行非阻塞检查
				$changed = stream_select($readPipes, $writePipes, $exceptPipes, 0, 100000); // 100ms
				
				if ($changed > 0) {
					foreach ($readPipes as $pipe) {
						$data = stream_get_contents($pipe);
						if ($data !== false && $data !== '') {
							if ($pipe === $pipes[1]) {
								$output .= $data;
							} else {
								$errorOutput .= $data;
							}
						}
					}
				}
				
				// 让出协程执行权，避免阻塞
				\Swoole\Coroutine::sleep(1); // 1ms
			}
			
			// 清理定时器
			if ($timerId !== null) {
				\Swoole\Timer::clear($timerId);
			}
			
			// 如果已经超时并推送了结果，直接返回
			if ($timedOut) {
				// 确保进程已经关闭
				if (is_resource($process)) {
					@proc_terminate($process);
					@fclose($pipes[0]);
					@fclose($pipes[1]);
					@fclose($pipes[2]);
					@proc_close($process);
				}
				return;
			}
			
			// 读取剩余的输出
			$stdout = stream_get_contents($pipes[1]);
			if ($stdout !== false) {
				$output .= $stdout;
			}
			
			$stderr = stream_get_contents($pipes[2]);
			if ($stderr !== false) {
				$errorOutput .= $stderr;
			}
			
			// 关闭所有管道
			fclose($pipes[0]);
			fclose($pipes[1]);
			fclose($pipes[2]);
			
			// 获取退出码
			$exitCode = proc_close($process);
			
			$channel->push([
				'success' => $exitCode === 0,
				'output' => trim($output),
				'error' => $exitCode !== 0 ? trim($errorOutput ?: $output) : ''
			]);
		});
		
		// 等待结果返回，设置超时避免永久阻塞
		$result = $channel->pop($timeout + 5); // 额外5秒缓冲时间
		
		if ($result === false) {
			return [
				'success' => false,
				'output' => '',
				'error' => 'Command execution channel timeout'
			];
		}
		if(isset($result['delete']) && str_contains($cmd, 'clone')) $this->delete();
		return $result;
	}

	/**
	 * 转换为仓库内相对路径
	 * @param string $repoName 仓库名
	 * @param string $filePath 文件绝对路径/相对路径
	 * @return string 仓库内相对路径
	 */
	private function getRepoRelativePath(string $repoName, string $filePath): string
	{
		$pathRes = $this->resolveRepoPath($repoName);
		if (!$pathRes['success']) {
			return $filePath; // 路径解析失败时返回原路径
		}
		$repoPath = rtrim($pathRes['path'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
		$realFilePath = realpath($filePath) ?: $filePath;

		return str_starts_with($realFilePath, $repoPath) 
			? str_replace($repoPath, '', $realFilePath) 
			: $filePath;
	}


	// 4. 核心 Git 操作方法
	/**
	 * 克隆仓库（优先自动提取仓库名，手动传参覆盖）
	 * @param string $repoUrl 仓库 URL（HTTP/SSH）
	 * @param string $repoName 手动指定仓库名（可选）
	 * @param array $options 配置：
	 *						- branch: 分支（默认 main）
	 *						- depth: 克隆深度（默认 0，完整克隆）
	 *						- username: HTTP 认证用户名（默认 Env git.username）
	 *						- password: HTTP 认证密码/Token（默认 Env git.password）
	 * @return array ['success' => bool, 'msg' => string, 'data' => array]
	 *				data 含 repoName（实际仓库名）、localPath（完整路径）
	 */
	public function clone(string $repoUrl, string $repoName = '', array $options = []): array
	{
		// 1. 校验主路径
		$basePath = $this->getBasePath();
		if ($basePath === null) {
			return ['success' => false, 'msg' => '主路径未配置，无法克隆', 'data' => []];
		}
		// 2. 确定最终仓库名：手动传参 → URL 提取默认名
		$finalRepoName = $repoName ?: $this->extractDefaultRepoName($repoUrl);
		// Logger::error($finalRepoName);
		// return ['success' => false, 'msg' => '无法从 URL 提取仓库名，请手动指定', 'data' => []]; 
		$this->repo = $finalRepoName;
		if (empty($finalRepoName)) {
			return ['success' => false, 'msg' => '无法从 URL 提取仓库名，请手动指定', 'data' => []];
		}

		// 3. 校验目标路径（避免覆盖非空目录）
		$localPath = $basePath . $finalRepoName;
		if (is_dir($localPath) && count(scandir($localPath)) > 2) {
			return [
				'success' => false,
				'msg' => "仓库已存在且非空：{$finalRepoName}（路径：{$localPath}）",
				'data' => []
			];
		}

		// 4. 处理配置（Env 自动补全账号密码）
		$options = array_merge([
			'branch' => 'main',
			'depth' => 0,
			'username' => Env::get('git.username', ''),
			'password' => Env::get('git.password', '')
		], $options);

		// 5. HTTP 认证处理（仅 HTTP/HTTPS 协议）
		if (!empty($options['username']) && str_starts_with($repoUrl, 'http')) {
			$parsed = parse_url($repoUrl);
			if ($parsed) {
				$repoUrl = str_replace(
					"{$parsed['scheme']}://",
					"{$parsed['scheme']}://" . urlencode($options['username']) . ":" . urlencode($options['password']) . "@",
					$repoUrl
				);
			}
		}

		// 6. 构建并执行克隆命令
		$cmdParts = [
			'git clone',
			"-b " . escapeshellarg($options['branch']), // 指定分支
			$options['depth'] > 0 ? "--depth " . escapeshellarg($options['depth']) : '', // 克隆深度
			escapeshellarg($repoUrl), // 仓库 URL（防命令注入）
			escapeshellarg($localPath) // 本地路径（防命令注入）
		];
		$cmd = implode(' ', array_filter($cmdParts)); // 过滤空元素（如无 depth 时）
		$result = $this->runCmd($cmd);

		// 7. 返回结果
		if ($result['success']) {
			return [
				'success' => true,
				'msg' => "克隆成功（仓库名：{$finalRepoName}）",
				'data' => [
					'repoName' => $finalRepoName,
					'localPath' => $localPath
				]
			];
		} else {
			return [
				'success' => false,
				'msg' => "克隆失败：{$result['error']}",
				'data' => []
			];
		}
	}

	/**
	 * 拉取远程仓库更新
	 * @param string $repoName 仓库名（克隆时返回的 repoName）
	 * @param array $options 配置：
	 *						- branch: 拉取分支（默认当前分支）
	 *						- rebase: 是否变基（默认 false）
	 * @return array ['success' => bool, 'msg' => string]
	 */
	public function pull(string $repoName, array $options = []): array
	{
		// 1. 校验仓库有效性
		$check = $this->checkRepoValid($repoName);
		if (!$check['success']) {
			return $check;
		}
		$this->repo = $repoName;
		// 2. 解析仓库路径
		$pathRes = $this->resolveRepoPath($repoName);
		$localPath = $pathRes['path'];

		// 3. 处理分支配置（默认拉取当前分支）
		$currentBranch = $this->getCurrentBranch($repoName)['data'] ?? '';
		$options = array_merge([
			'branch' => $currentBranch,
			'rebase' => false
		], $options);
		if (empty($options['branch'])) {
			return ['success' => false, 'msg' => '无法获取目标分支'];
		}

		// 4. 构建并执行拉取命令
		$cmd = implode(' ', [
			"cd " . escapeshellarg($localPath),
			"&& git pull",
			$options['rebase'] ? '--rebase' : '',
			"origin " . escapeshellarg($options['branch'])
		]);
		$result = $this->runCmd($cmd);

		// 5. 返回结果
		return $result['success'] ?
			[
				'success' => true,
				'msg' => "拉取成功（仓库：{$repoName}，分支：{$options['branch']}）"
			] :
			[
				'success' => false,
				'msg' => "拉取失败：{$result['error']}"
			];
	}

	/**
	 * 分支管理（创建/切换/删除/列出）
	 * @param string $repoName 仓库名
	 * @param string $action 操作类型：create/switch/delete/list
	 * @param string $branchName 分支名（list 时可空）
	 * @return array ['success' => bool, 'msg' => string, 'data' => array|null]
	 *				list 操作时 data 为分支数组
	 */
	public function branch(string $repoName, string $action, string $branchName = ''): array
	{
		// 1. 校验仓库有效性
		$check = $this->checkRepoValid($repoName);
		if (!$check['success']) {
			return $check;
		}
		$this->repo = $repoName;
		// 2. 解析仓库路径
		$pathRes = $this->resolveRepoPath($repoName);
		$localPath = $pathRes['path'];

		// 3. 校验操作类型
		$validActions = ['create', 'switch', 'delete', 'list'];
		if (!in_array($action, $validActions)) {
			return [
				'success' => false,
				'msg' => "无效操作类型，仅支持：" . implode('/', $validActions),
				'data' => null
			];
		}

		// 4. 构建命令
		$cmd = "cd " . escapeshellarg($localPath) . " && git ";
		$successMsg = '';
		switch ($action) {
			case 'create':
				if (empty($branchName)) {
					return ['success' => false, 'msg' => '创建分支需指定分支名', 'data' => null];
				}
				$cmd .= "branch " . escapeshellarg($branchName);
				$successMsg = "分支创建成功（仓库：{$repoName}，分支：{$branchName}）";
				break;
			case 'switch':
				if (empty($branchName)) {
					return ['success' => false, 'msg' => '切换分支需指定分支名', 'data' => null];
				}
				$cmd .= "checkout " . escapeshellarg($branchName);
				$successMsg = "分支切换成功（仓库：{$repoName}，分支：{$branchName}）";
				break;
			case 'delete':
				if (empty($branchName)) {
					return ['success' => false, 'msg' => '删除分支需指定分支名', 'data' => null];
				}
				$cmd .= "branch -d " . escapeshellarg($branchName); // -d 需合并，-D 强制删除
				$successMsg = "分支删除成功（仓库：{$repoName}，分支：{$branchName}）";
				break;
			case 'list':
				$cmd .= "branch --format='%(refname:short)'"; // 仅显示分支名
				$successMsg = "分支列表获取成功（仓库：{$repoName}）";
				break;
		}

		// 5. 执行命令
		$result = $this->runCmd($cmd);
		if (!$result['success']) {
			return [
				'success' => false,
				'msg' => "分支操作失败：{$result['error']}",
				'data' => null
			];
		}

		// 6. 处理列表结果
		$data = null;
		if ($action === 'list') {
			$branches = array_filter(explode(PHP_EOL, $result['output']));
			// 去除分支名首尾的引号、空格、制表符、换行符，确保格式干净
			$data = array_map(fn($item) => trim($item, "' \t\n\r"), $branches);
			// 过滤空分支名（防止异常数据）
			$data = array_filter($data);
		}

		return [
			'success' => true,
			'msg' => $successMsg,
			'data' => $data
		];
	}

	/**
	 * 代码提交（暂存+提交）
	 * @param string $repoName 仓库名
	 * @param string $commitMsg 提交信息（必填）
	 * @param array $files 待提交文件列表（空则提交所有，支持绝对路径/仓库内相对路径）
	 * @return array ['success' => bool, 'msg' => string, 'data' => string|null]
	 *				data 为提交的 SHA 哈希值
	 */
	public function commit(string $repoName, string $commitMsg, array $files = []): array
	{
		// 1. 校验仓库有效性
		$check = $this->checkRepoValid($repoName);
		if (!$check['success']) {
			return $check;
		}
		$this->repo = $repoName;

		// 2. 校验提交信息
		$commitMsg = trim($commitMsg);
		if (empty($commitMsg)) {
			return ['success' => false, 'msg' => '提交信息不能为空', 'data' => null];
		}

		// 3. 解析仓库路径
		$pathRes = $this->resolveRepoPath($repoName);
		$localPath = $pathRes['path'];

		// 4. 构建命令（先暂存文件，再提交，最后获取提交 SHA）
		$cmdParts = ["cd " . escapeshellarg($localPath)];
		
		// 处理文件暂存：空则提交所有，否则暂存指定文件
		if (empty($files)) {
			$cmdParts[] = "&& git add .";
		} else {
			// 转换文件路径为仓库内相对路径，避免绝对路径问题
			$relativeFiles = array_map(fn($file) => $this->getRepoRelativePath($repoName, $file), $files);
			// 转义文件路径，防止命令注入
			$escapedFiles = array_map(fn($file) => escapeshellarg($file), $relativeFiles);
			$cmdParts[] = "&& git add " . implode(' ', $escapedFiles);
		}

		// 提交命令（带提交信息）+ 获取提交 SHA（git rev-parse HEAD 取最新提交哈希）
		$cmdParts[] = "&& git commit -m " . escapeshellarg($commitMsg);
		$cmdParts[] = "&& git rev-parse HEAD";

		// 拼接完整命令
		$cmd = implode(' ', $cmdParts);
		$result = $this->runCmd($cmd);

		// 5. 处理结果：成功则返回提交 SHA，失败返回错误信息
		if ($result['success']) {
			$commitSha = trim($result['output']);
			return [
				'success' => true,
				'msg' => "提交成功（仓库：{$repoName}）",
				'data' => $commitSha
			];
		} else {
			return [
				'success' => false,
				'msg' => "提交失败：{$result['error']}",
				'data' => null
			];
		}
	}

	/**
	 * 获取提交记录
	 * @param string $repoName 仓库名
	 * @param array $options 配置：
	 *						- branch: 目标分支（默认当前分支）
	 *						- limit: 记录条数（默认 10）
	 *						- search: 关键词搜索（匹配提交信息，默认空）
	 * @return array ['success' => bool, 'msg' => string, 'data' => array|null]
	 *				data 为提交记录数组（含 sha、author、time、message）
	 */
	public function getCommits(string $repoName, array $options = []): array
	{
		// 1. 校验仓库有效性
		$check = $this->checkRepoValid($repoName);
		if (!$check['success']) {
			return $check;
		}
		$this->repo = $repoName;

		// 2. 解析仓库路径
		$pathRes = $this->resolveRepoPath($repoName);
		$localPath = $pathRes['path'];

		// 3. 处理配置参数
		$currentBranch = $this->getCurrentBranch($repoName)['data'] ?? '';
		$options = array_merge([
			'branch' => $currentBranch,
			'limit' => 10,
			'search' => ''
		], $options);

		// 校验分支（避免空分支）
		if (empty($options['branch'])) {
			return ['success' => false, 'msg' => '无法获取目标分支', 'data' => null];
		}

		// 4. 构建 log 命令（格式化输出：SHA|作者|时间|提交信息）
		$cmdParts = [
			"cd " . escapeshellarg($localPath),
			"&& git log --pretty=format:'%H|%an|%ai|%s'", // %H=SHA, %an=作者名, %ai=提交时间, %s=信息
			"-n " . escapeshellarg($options['limit']), // 限制条数
			escapeshellarg($options['branch']) // 目标分支
		];

		// 关键词搜索（匹配提交信息）
		if (!empty($options['search'])) {
			$cmdParts[] = "--grep=" . escapeshellarg($options['search']);
		}

		// 拼接命令并执行
		$cmd = implode(' ', $cmdParts);
		$result = $this->runCmd($cmd);

		// 5. 解析提交记录
		$commits = [];
		if ($result['success']) {
			$lines = array_filter(explode(PHP_EOL, $result['output']));
			foreach ($lines as $line) {
				// 去除首尾引号/空格，按 | 分割（最多分割4段，避免提交信息含 |）
				$line = trim($line, "' \t\n\r");
				$parts = explode('|', $line, 4);
				if (count($parts) < 4) continue; // 跳过格式异常的记录

				list($sha, $author, $time, $message) = $parts;
				$commits[] = [
					'sha' => $sha,
					'author' => $author,
					'time' => date('Y-m-d H:i:s', strtotime($time)), // 统一时间格式
					'message' => $message
				];
			}
		}

		return $result['success'] ?
			[
				'success' => true,
				'msg' => "共获取到 " . count($commits) . " 条提交记录（仓库：{$repoName}）",
				'data' => $commits
			] :
			[
				'success' => false,
				'msg' => "获取提交记录失败：{$result['error']}",
				'data' => null
			];
	}

	/**
	 * 获取当前分支名
	 * @param string $repoName 仓库名
	 * @return array ['success' => bool, 'msg' => string, 'data' => string|null]
	 */
	public function getCurrentBranch(string $repoName): array
	{
		// 1. 校验仓库有效性
		$check = $this->checkRepoValid($repoName);
		if (!$check['success']) {
			return $check;
		}
		$this->repo = $repoName;

		// 2. 解析仓库路径并执行命令
		$pathRes = $this->resolveRepoPath($repoName);
		$localPath = $pathRes['path'];
		$cmd = "cd " . escapeshellarg($localPath) . " && git rev-parse --abbrev-ref HEAD";
		$result = $this->runCmd($cmd);

		return $result['success'] ?
			[
				'success' => true,
				'msg' => '获取当前分支成功',
				'data' => trim($result['output'])
			] :
			[
				'success' => false,
				'msg' => "获取当前分支失败：{$result['error']}",
				'data' => null
			];
	}
	/**
	 * 删除本地仓库（含安全校验，防止误删非仓库目录）
	 * @param string $repoName 仓库名（克隆时返回的 repoName，需在主路径下）
	 * @param bool $force 强制删除（即使目录非空/权限不足，默认 false）
	 * @return array ['success' => bool, 'msg' => string]
	 */
	public function delete(?string $repoName = null, bool $force = false): array
	{
		// 1. 校验主路径配置
		$basePath = $this->getBasePath();
		if ($basePath === null) {
			return ['success' => false, 'msg' => '主路径未配置，无法定位仓库'];
		}
		$repoName = $repoName ?: $this->repo;
		if(!$repoName) return ['success' => false, 'msg' => '请输入仓库名字']; 
	
		// 2. 解析仓库完整路径（主路径+仓库名，避免跨目录删除）
		$fullPath = rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $repoName;
		$fullPath = realpath($fullPath); // 转换为真实路径，防止符号链接欺骗
	
		// 3. 安全校验：确保是主路径下的有效 Git 仓库
		if ($fullPath === false) {
			return ['success' => false, 'msg' => "仓库不存在：{$repoName}（路径：{$basePath}{$repoName}）"];
		}
		// 校验路径归属（防止删除主路径外的目录，如 ../../etc）
		if (strpos($fullPath, $basePath) !== 0) {
			return ['success' => false, 'msg' => "禁止删除主路径外的目录：{$fullPath}（安全校验不通过）"];
		}
		// 校验是否为 Git 仓库（避免误删普通目录）
		if (!is_dir($fullPath . '/.git')) {
			return ['success' => false, 'msg' => "{$repoName} 不是有效 Git 仓库（缺少 .git 目录），禁止删除"];
		}
	
		// 4. 执行删除（调用递归删除辅助方法）
		$deleteSuccess = $this->deleteDir($fullPath, $force);
		if ($deleteSuccess) {
			return ['success' => true, 'msg' => "本地仓库删除成功：{$repoName}（路径：{$fullPath}）"];
		} else {
			$forceTip = $force ? '' : '，可尝试开启 $force=true 强制删除（需注意权限）';
			return ['success' => false, 'msg' => "仓库删除失败：权限不足或目录被占用{$forceTip}"];
		}
	}
	
	/**
	 * 递归删除目录（辅助方法，支持强制删除）
	 * @param string $dirPath 待删除目录路径
	 * @param bool $force 强制删除（忽略权限，默认 false）
	 * @return bool 删除成功返回 true，失败返回 false
	 */
	private function deleteDir(string $dirPath, bool $force = false): bool
	{
		// 路径不存在直接返回成功
		if (!file_exists($dirPath)) {
			return true;
		}
	
		// 处理文件（非目录直接删除）
		if (!is_dir($dirPath)) {
			// 强制删除时忽略文件权限，否则检查可写性
			if ($force || is_writable($dirPath)) {
				return unlink($dirPath);
			}
			return false;
		}
	
		// 递归删除目录内所有内容（先删子目录/文件，再删当前目录）
		$iterator = new RecursiveDirectoryIterator(
			$dirPath,
			RecursiveDirectoryIterator::SKIP_DOTS // 跳过 . 和 ..
		);
		$recursiveIterator = new RecursiveIteratorIterator(
			$iterator,
			RecursiveIteratorIterator::CHILD_FIRST // 先处理子元素，再处理父目录
		);
	
		foreach ($recursiveIterator as $item) {
			$itemPath = $item->getRealPath();
			// 递归删除子目录/文件
			if (!$this->deleteDir($itemPath, $force)) {
				return false;
			}
		}
	
		// 删除空目录（强制删除时忽略权限）
		if ($force) {
			return rmdir($dirPath);
		}
		return is_writable($dirPath) ? rmdir($dirPath) : false;
	}
}
