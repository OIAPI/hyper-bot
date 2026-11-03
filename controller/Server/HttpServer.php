<?php
namespace Controller\Server;

// 注意：必须使用 WebSocket\Server（它继承自 Http\Server），
// 否则 onOpen/onMessage 不会派发、Server::push() 也不存在。
use Swoole\WebSocket\Server;
use Swoole\Event;
use Swoole\Timer;
use Swoole\Runtime;
use Swoole\Coroutine\System;
use Utils\Logger;
use Utils\Env;
use Utils\Cache;

use Controller\Api;
use Core\Http\Request as CoreRequest;
use Webui\Routes as WebuiRoutes;


/**
 * Swoole HTTP服务类：统一管理服务启动、配置、事件回调
 */
class HttpServer {
	private ?Server $server = null;
	private array $config = [];
	private const MASTER_PID_KEY = 'master_pid';

	/**
	 * 构造函数：初始化服务配置
	 */
	public function __construct() {
		$this->initConfig();
		$this->initGLOBALSCache();
	}


	/**
	 * 初始化服务配置（从Env读取，统一管理）
	 */
	private function initConfig(): void {
		$this->config = [
			'host' => Env::get('http.host', '0.0.0.0'),
			'port' => Env::get('http.port', 9501),
			'swoole' => [
				'enable_coroutine' => true,
				'reload_async' => true,
				'max_wait_time' => 5,
				'pid_file' => __runtime__ . '/http.pid',
				'stats_file' => __runtime__ . '/http.status',
				'document_root' => __root__ . '/public',
				'enable_static_handler' => true,
				'daemonize' => false,
				'log_file' => __logger__ . '/logger.log',
				'log_rotation' => SWOOLE_LOG_ROTATION_DAILY,
				// WebSocket 支持（OneBot 反向 WS / Milky WS 服务端）
				// 说明：握手与鉴权在 WsHub::onOpen 中完成，这里保持 Swoole 默认自动握手
				'open_websocket_protocol' => true,
				'websocket_compression' => false,
			],
			'super_permission' => Env::get('permission.super', []),
		];
	}


	/**
	 * 初始化全局缓存（API实例、服务实例）
	 */
	private function initGLOBALSCache(): void {
		if (Cache::$api === null) {
			Cache::$api = Api::getInstance(); 
		}
	}

	/**
	 * 创建Swoole Server实例
	 */
	private function createServer(): void {
		$this->server = new Server(
			$this->config['host'],
			$this->config['port'],
			SWOOLE_PROCESS
		);
		$this->server->set($this->config['swoole']);
		Cache::$http = $this->server;
	}


	/**
	 * 注册服务事件回调（start/request/WorkerStart等）
	 */
	private function registerEvents(): void {
		$this->server->on('start', [$this, 'onStart']);
		$this->server->on('request', [$this, 'onRequest']);
		$this->server->on('WorkerStart', [$this, 'onWorkerStart']);
		$this->server->on('WorkerError', [$this, 'onWorkerError']);
		// WebSocket 事件（OneBot 反向 WS / Milky WS 服务端接入）
		ProtocolOneBotWsHub::bindServer($this->server);
		$this->bindShutdownEvents(); //注册关闭事件
	}


	/**
	 * 服务启动回调（Master进程）
	 * @param Server $server Swoole Server实例
	 */
	public function onStart(Server $server): void {
		Logger::init();
		define(self::MASTER_PID_KEY, $server->master_pid);
		Cache::set('startTime', microtime(true));
		Logger::success(
			"HTTP服务启动成功 | Host: {$this->config['host']} | Port: {$this->config['port']}"
		);
		Logger::success("Master PID: " . constant(self::MASTER_PID_KEY));
		$this->initSuperPermission();
		$this->registerStdinCommand();
		$this->initSIGINT();
		// 调度器由 Core\Scheduler 统一管理（Swoole 下映射到 Swoole\Timer），
		// 这里不再初始化 v1 的 TaskManager。
	}

	/**
	 * 初始化超级管理员权限（写入Redis）
	 */
	private function initSuperPermission(): void {
		$superList = $this->config['super_permission'];
		if (empty($superList) || !is_array($superList)) {
			// Logger::warning("未配置超级管理员列表（permission.super）");
			return;
		}

		try {
			$redis = Cache::$api->redis;
			$redis->select(Env::get('Redis.select', 0));
			$redis->set('runTimer', time());

			foreach ($superList as $userId) {
				$userId = trim($userId);
				if (empty($userId)) continue;

				$redis->set("memberPermission.{$userId}", 'super');
				Logger::success("权限初始化 | 用户 {$userId} → 提升为 super");
			}
		} catch (\Exception $e) {
			Logger::error("超级管理员权限初始化失败：{$e->getMessage()}");
		}
	}
	/**
	 * 终端命令交互：删除不回行首+光标移动无错乱+中英文兼容
	 */
	private function registerStdinCommand(): void {
		$originalStty = shell_exec('stty -g');
		if (empty($originalStty)) {
			Logger::error("无法获取终端配置，终端命令功能禁用");
			return;
		}
		shell_exec('stty -icanon -echo -icrnl cs8 min 1 time 50');
	
		static $commandHistory = [];
		static $historyIndex = -1;
		static $inputBuffer = '';
		static $cursorPos = 0;
	
		Event::add(STDIN, function ($fd) use (
			$originalStty,
			&$commandHistory,
			&$historyIndex,
			&$inputBuffer,
			&$cursorPos
		) {
			$chars = '';
			$startTime = microtime(true);
			while (microtime(true) - $startTime < 0.05 && strlen($chars) < 200) {
				if (feof($fd)) break;
				$char = fgetc($fd);
				if ($char === false) break;
				$chars .= $char;
			}
			if (empty($chars)) return;
	
			$parsed = $this->parseInputChars($chars);
			if (!empty($parsed['control'])) {
				$this->handleControlChars($parsed['control'], $commandHistory, $historyIndex, $inputBuffer, $cursorPos);
			}
			if (!empty($parsed['printable'])) {
				$this->handlePrintableChars($parsed['printable'], $inputBuffer, $cursorPos);
			}
		});
	
		register_shutdown_function(function () use ($originalStty) {
			shell_exec("stty {$originalStty}");
			Logger::info("终端已恢复正常");
		});
	}

	/**
	 * 解析输入字符：分离控制符和可打印字符
	 */
	private function parseInputChars(string $chars): array {
		$result = ['control' => '', 'printable' => ''];
		$len = strlen($chars);
		$i = 0;
	
		while ($i < $len) {
			$ascii = ord($chars[$i]);
			if ($ascii === 27 && $i + 3 < $len && $chars[$i+1] === '[' && in_array($chars[$i+2], ['1', '4']) && $chars[$i+3] === '~') {
				$result['control'] .= substr($chars, $i, 4);
				$i += 4;
				continue;
			}
			if ($ascii === 27 && $i + 2 < $len && $chars[$i+1] === '[' && in_array($chars[$i+2], ['A', 'B', 'C', 'D', 'H', 'F'])) {
				$result['control'] .= substr($chars, $i, 3);
				$i += 3;
				continue;
			}
			if ($ascii <= 31 || $ascii === 127) {
				$result['control'] .= $chars[$i];
				$i += 1;
				continue;
			}
			$result['printable'] .= $chars[$i];
			$i += 1;
		}
	
		return $result;
	}
	
	/**
	 * 处理控制符：修复删除后光标回行首问题
	 */
	private function handleControlChars(string $control, array &$history, int &$historyIdx, string &$buffer, int &$cursorPos): void {
		$len = strlen($control);
		$i = 0;
		$totalChars = mb_strlen($buffer, 'UTF-8');
	
		while ($i < $len) {
			$ascii = ord($control[$i]);
		
			if ($ascii === 27 && $i + 3 < $len && $control[$i+1] === '[' && $control[$i+3] === '~') {
				$secondChar = $control[$i+2];
				if ($secondChar === '1') {
					$cursorPos = 0;
				} elseif ($secondChar === '4') {
					$cursorPos = $totalChars;
				}
				$this->refreshInputLine($buffer, $cursorPos);
				$i += 4;
				continue;
			}
			if ($ascii === 27 && $i + 2 < $len) {
				$third = $control[$i+2];
				switch ($third) {
					case 'A':
						if (!empty($history) && $historyIdx < count($history)-1) {
							$historyIdx++;
							$buffer = $history[$historyIdx];
							$cursorPos = mb_strlen($buffer, 'UTF-8');
						}
						break;
					case 'B':
						if ($historyIdx > -1) {
							$historyIdx--;
							$buffer = $historyIdx === -1 ? '' : $history[$historyIdx];
							$cursorPos = mb_strlen($buffer, 'UTF-8');
						}
						break;
					case 'D':
						if ($cursorPos > 0) {
							$prevChar = mb_substr($buffer, $cursorPos - 1, 1, 'UTF-8');
							$charWidth = (strlen($prevChar) > 1) ? 2 : 1;
							$cursorPos--;
						}
						break;
					case 'C':
						if ($cursorPos < $totalChars) {
							$nextChar = mb_substr($buffer, $cursorPos, 1, 'UTF-8');
							$charWidth = (strlen($nextChar) > 1) ? 2 : 1;
							$cursorPos++;
						}
						break;
					case 'H':
						$cursorPos = 0;
						break;
					case 'F':
						$cursorPos = mb_strlen($buffer, 'UTF-8');
						break;
				}
				$this->refreshInputLine($buffer, $cursorPos);
				$i += 3;
				continue;
			}
	
			switch ($ascii) {
				case 13:
				case 10:
					echo "\n";
					$command = trim($buffer);
					if (!empty($command)) $this->executeCommand($command, $history);
					$buffer = '';
					$historyIdx = -1;
					$cursorPos = 0;
					$this->refreshInputLine($buffer, $cursorPos);
					break;
				case 8:
				case 127:
					if ($totalChars > 0 && $cursorPos > 0) {
						$newCursorPos = $cursorPos - 1;
						$buffer = mb_substr($buffer, 0, $newCursorPos, 'UTF-8') . mb_substr($buffer, $cursorPos, null, 'UTF-8');
						$cursorPos = $newCursorPos;
						$this->refreshInputLine($buffer, $cursorPos);
					}
					break;
			}
			$i += 1;
		}
	}
	
	/**
	 * 处理可打印字符：插入后同步光标
	 */
	private function handlePrintableChars(string $printable, string &$buffer, int &$cursorPos): void {
		if (!$this->isValidUtf8($printable)) {
			// Logger::warning("输入包含无效UTF-8字符，已过滤");
			return;
		}
	
		$printableChars = mb_strlen($printable, 'UTF-8');
		if ($printableChars === 0) return;
		$buffer = mb_substr($buffer, 0, $cursorPos, 'UTF-8') . $printable . mb_substr($buffer, $cursorPos, null, 'UTF-8');
		$cursorPos += $printableChars;
	
		$this->refreshInputLine($buffer, $cursorPos);
	}
	
	/**
	 * 刷新输入行：简化逻辑，避免光标位置偏差
	 */
	private function refreshInputLine(string $input, int $cursorPos): void {
		ob_start();
		echo "\033[2K\033[0G";
		echo $input;
		if ($cursorPos < mb_strlen($input, 'UTF-8')) {
			$tail = mb_substr($input, $cursorPos, null, 'UTF-8');
			$tailWidth = 0;
			$tailLength = mb_strlen($tail, 'UTF-8');
			
			for ($i = 0; $i < $tailLength; $i++) {
				$char = mb_substr($tail, $i, 1, 'UTF-8');
				$tailWidth += (preg_match('/[\x{4e00}-\x{9fa5}]/u', $char)) ? 2 : 1;
			}
			
			if ($tailWidth > 0) {
				echo "\033[{$tailWidth}D";
			}
		}
		
		flush();
		ob_flush();
	}

	
	/**
	 * 验证UTF-8编码
	 */
	private function isValidUtf8(string $str): bool {
		return mb_check_encoding($str, 'UTF-8') && $str === mb_convert_encoding(mb_convert_encoding($str, 'UTF-32', 'UTF-8'), 'UTF-8', 'UTF-32');
	}
	private function executeCommand(string $command, array &$commandHistory): void {
		$lowerCmd = strtolower($command);
		$validCmds = ['clear', 'stop', 'restart', 'reload', '?', 'help'];
		if (in_array($lowerCmd, $validCmds) && !in_array($command, $commandHistory)) {
			array_unshift($commandHistory, $command);
		}
	
		switch ($lowerCmd) {
			case 'clear':
				echo shell_exec('clear');
				// Logger::debug("终端命令执行 | clear（清空屏幕）");
				break;
			case 'stop':
				$this->stopServer();
				break;
			case 'restart':
			case 'reload':
				echo shell_exec('clear');
				Logger::warning("终端命令执行 | restart（重启Worker进程）");
				// System::exec("kill -USR1 " . constant(self::MASTER_PID_KEY));
				$this->server->reload();
				
				Logger::warning("Worker进程重启完成");
				echo "\n";
				break;
			case '?':
			case 'help':
				$this->showHelpCommand();
				echo "\n";
				break;
			default:
				Logger::warning("未知命令：{$command}，输入 help/? 查看帮助");
				echo "\n";
				break;
		}
	}
	
	private function stopServer(): void {
		$pid = constant(self::MASTER_PID_KEY);
		Logger::warning("终端命令执行 | stop（停止服务）");
		Logger::warning("服务将在3秒后退出...");
		$originalStty = shell_exec('stty -g');
		shell_exec("stty {$originalStty}");
		
		$time = 3;
		while ($time > 0) {
			Logger::tput($time, 1);
			$time--;
		}
		// Logger::tput($time, $time);
		System::exec("kill -9 {$pid}");
		Logger::info("服务退出成功");
		exit(0);
	}

	/**
	 * 显示命令帮助
	 */
	private function showHelpCommand(): void {
		$helpMsg = <<<HELP
终端命令列表：
- clear		  清空屏幕
- stop		   优雅停止服务（3秒倒计时）
- restart		重启Worker进程（不中断服务）
- help/?		 查看命令帮助
HELP;
		Logger::info($helpMsg);
	}
	
	/**
	 * 请求处理回调
	 * @param \Swoole\Http\Request $request 请求对象
	 * @param \Swoole\Http\Response $response 响应对象
	 */
	public function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void {
		try {
			// 原始请求体与请求头（QQ 官方机器人 / Milky WebHook 验签必须使用原始字节）
			$rawBody = $request->getContent() ?: '';
			$headers = [];
			foreach (($request->header ?: []) as $key => $value) {
				$headers[strtolower($key)] = $value;
			}

			$path = (string)($request->server['path_info'] ?? '/');

			// 说明：WebSocket 升级请求不会走到这里。
			// open_websocket_protocol = true 时，Swoole 完成握手后直接派发
			// open/message/close 事件给 WsHub，onRequest 根本不会被调用。
			// （v1 这里有一份「退化兜底」的 upgrade 逻辑，实际不可达，已删除。）

			// 静态资源交给 Swoole 的 enable_static_handler（document_root = public）；
			// 但目录本身不会自动补 index.html，这里显式跳转一次。
			if ($path === '/webui' || $path === '/webui/') {
				$response->status(302);
				$response->header('Location', '/webui/index.html');
				$response->end();
				return;
			}

			// v2：所有请求走统一内核路由（WebUI + 协议 Webhook + 健康检查），
			// 与 php 架构完全同一条管线，不再有第二套注解路由。
			$core = CoreRequest::fromSwoole((object)[
				'server'  => $request->server ?: [],
				'header'  => $headers,
				'get'     => $request->get ?: [],
				'post'    => $request->post ?: [],
				'cookie'  => $request->cookie ?: [],
				'files'   => $request->files ?: [],
				'rawBody' => $rawBody,
			]);

			$result = WebuiRoutes::kernel()->handle($core);

			$response->status($result->status);
			foreach ($result->headers as $name => $value) {
				$response->header((string)$name, (string)$value);
			}
			foreach ($result->cookies as $cookie) {
				$response->header('Set-Cookie', (string)$cookie, false);
			}
			$response->end($result->body);
		} catch (\Throwable $e) {
			$response->status(500);
			$response->header('Content-Type', 'application/json; charset=utf-8');
			$response->end((string)json_encode([
				'code'    => -1,
				'message' => '请求处理异常：' . $e->getMessage(),
				'data'    => null,
			], JSON_UNESCAPED_UNICODE));
			Logger::error('请求处理异常：' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
		}
	}


	/**
	 * Worker进程启动回调
	 * @param Server $server Swoole Server实例
	 * @param int $workerId Worker进程ID
	 */
	public function onWorkerStart(Server $server, int $workerId): void {
		Timer::clearAll();
		
		$this->registerErrorHandler();
		Logger::debug("Worker进程启动 | ID: {$workerId} | PID: {$server->worker_pid}");
		$workerNum = $server->setting['worker_num'];
		if ($workerId === $workerNum - 1) {
			Logger::debug("Task Worker 数量：{$workerNum}");
		}

		// WebUI 首次运行需要生成管理员账号（多 Worker 下每个进程都会检查，
		// 已存在则直接跳过，不会互相覆盖）。
		\Webui\Auth::bootstrap();

		// 只在一个 Worker 中启动协议客户端（常驻协程），避免重复连接
		if ($workerId === 0) {
			$this->startProtocolClients();
		}
	}

	/**
	 * 启动各协议的常驻客户端（正向 WS / Milky WS·SSE / QQ 官方 Gateway）
	 */
	private function startProtocolClients(): void {
		\Swoole\Coroutine::create(function () {
			try {
				\Core\Bootstrap::bootProtocolClients();
			} catch (\Throwable $e) {
				Logger::error("协议客户端启动失败：{$e->getMessage()} | {$e->getFile()}:{$e->getLine()}");
			}
		});
	}


	/**
	 * 注册全局异常/错误处理
	 */
	private function registerErrorHandler(): void {
		set_exception_handler(function (\Throwable $e) {
			Logger::error(
				"未捕获异常 | 文件: {$e->getFile()} | 行号: {$e->getLine()} | 消息: {$e->getMessage()}"
			);
		});
		set_error_handler(function (int $code, string $message, string $file, int $line) {
			if(str_contains($file, 'Composer/Util/Silencer.php') !== false) return true; //插件的auto错误，不理会
			Logger::error(
				"PHP错误 | 代码: {$code} | 文件: {$file} | 行号: {$line} | 消息: {$message}"
			);
			return true;
		});
		register_shutdown_function(function () {
			Logger::shutdown();
			$error = error_get_last();
			if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR])) {
				Logger::error(
					"致命错误 | 文件: {$error['file']} | 行号: {$error['line']} | 消息: {$error['message']}"
				);
			}
		});
	}


	/**
	 * Worker进程错误回调
	 * @param Server $server Swoole Server实例
	 * @param int $workerId Worker进程ID
	 * @param int $workerPid Worker进程PID
	 * @param int $exitCode 退出状态码
	 * @param int $signal 退出信号
	 */
	public function onWorkerError(Server $server, int $workerId, int $workerPid, int $exitCode, int $signal): void {
		Logger::error(
			"Worker进程错误 | ID: {$workerId} | PID: {$workerPid} | 退出码: {$exitCode} | 信号: {$signal}"
		);
	}
	
	/**
	 * 绑定服务关闭阶段事件（替代 SIGTERM 信号注册，避免冲突）
	 */
	private function bindShutdownEvents(): void
	{
		$this->server->on('BeforeShutdown', function (Server $server) {
			Logger::warning("服务开始关闭（触发 BeforeShutdown） | Master PID：" . constant(self::MASTER_PID_KEY));
		});
	
		$this->server->on('Shutdown', function (Server $server) {
			$runTime = round((microtime(true) - Cache::get('startTime')) / 60, 2); // 需在 bindStartEvent 中记录 $this->startTime
			Logger::success(sprintf(
				"服务已完全关闭（触发 Shutdown） | "
				. "Master PID：%d | "
				. "运行时长：%s 分钟 | "
				. "所有 Worker/Task 进程已退出",
				constant(self::MASTER_PID_KEY),
				$runTime
			));
		});
	}
	private function initSIGINT() {
		\Swoole\Process::signal(SIGINT, function () {
			Logger::warning("收到 SIGINT 强制停止信号（Ctrl+C），1 秒后关闭服务...");
			Logger::tput("倒计时 1s", 1);
			$this->stop();
			Logger::success("服务已强制关闭 | Master PID：" . constant(self::MASTER_PID_KEY));
			// exit(0);
		});
	}

	/**
	 * 启动服务
	 */
	public function start(): void {
		if ($this->server === null) {
			$this->createServer();
			$this->registerEvents();
		}
		Logger::info("HTTP服务准备启动...");
		$this->server->start();
	}


	/**
	 * 优雅停止服务（外部调用）
	 */
	public function stop(): void {
		if ($this->server && $this->server->master_pid > 0) {
			$this->server->shutdown();
			Logger::success("Server已停止");
			System::exec('kill -15 ' . constant(self::MASTER_PID_KEY));
			System::exec('kill -9 ' . constant(self::MASTER_PID_KEY));
		}
	}
}
