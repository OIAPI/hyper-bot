<?php
namespace Utils;

use Composer\Factory;
use Composer\Installer;
use Composer\IO\BufferIO;
use Composer\Json\JsonFile;
use Swoole\Coroutine\System;


class PluginManager {
	private $pluginRootDir;

	public function __construct(string $pluginRootDir) {
		$this->pluginRootDir = rtrim($pluginRootDir, '/') . '/';
		if (!is_dir($this->pluginRootDir)) {
			Operate::dir($this->pluginRootDir);
		}
		// $this->registerErrorHandler(); //会覆盖全局错误处理
	}
	/**
	 * 注册专用的错误处理器
	 */
	private function registerErrorHandler(): void {
		static $registered = false;
		
		if (!$registered) {
			set_error_handler(function($errno, $errstr, $errfile, $errline) {
				// 只抑制 Composer 的 autoload 文件缺失错误
				if ($errno === E_WARNING && 
					strpos($errstr, 'Failed to open stream') !== false &&
					strpos($errstr, 'vendor/composer/autoload_') !== false) {
					// 记录调试信息但不显示错误
					Logger::debug("Composer 文件未就绪:，等待中…");
					return true;
				}
				return false;
			});
			$registered = true;
		}
	}
	// 【不变】读取插件 composer.json 配置
	public function readPluginConfig(string $pluginName): ?array {
		$composerPath = $this->pluginRootDir . $pluginName . '/composer.json';
		if (!file_exists($composerPath) || !is_file($composerPath) || is_link($composerPath)) {
			Logger::error("插件【{$pluginName}】的 composer.json 不存在或非法");
			return null;
		}
		try {
			$jsonFile = new JsonFile($composerPath);
			return $jsonFile->read();
		} catch (\Exception $e) {
			Logger::error("解析 composer.json 失败：" . $e->getMessage());
			return null;
		}
	}

	/**
	 * 安装插件依赖并生成autoload（优化版：避免重复生成）
	 */
	public function installPluginDeps(string $pluginName): bool {
		$config = $this->readPluginConfig($pluginName);
		if (!$config) return false;
	
		$pluginDir = $this->pluginRootDir . $pluginName;
		$composerJsonPath = $pluginDir . '/composer.json';
		$vendorDir = $pluginDir . '/vendor';
		$composerLock = $pluginDir . '/composer.lock';
		$autoloadGenerated = false;
	
		// 检查是否已经安装过
		if (is_dir($vendorDir) && file_exists($composerLock)) {
			Logger::info("插件【{$pluginName}】依赖已安装，跳过处理");
			return true;
		}
	
		try {
			$io = new BufferIO();
			$originalCwd = getcwd();
			chdir($pluginDir);
			
			$composer = Factory::create($io, $composerJsonPath);
			$hasDependencies = !empty($config['require']) && count($config['require']) > 0;
	
			if ($hasDependencies) {
				// 有依赖：安装依赖（会自动生成autoload）
				Logger::info("安装插件【{$pluginName}】依赖...");
				$installer = Installer::create($io, $composer);
				$installer->setDryRun(false)
						->setVerbose(false)
						->setPreferDist(true)
						->setDevMode(false) // 生产环境不建议安装dev依赖
						->setOptimizeAutoloader(true);
				
				$result = $installer->run();
				
				if ($result !== 0) {
					throw new \Exception("依赖安装失败，返回码: " . $result);
				}
				Logger::success("插件【{$pluginName}】依赖安装成功");
				$autoloadGenerated = true;
				
			} else {
				Logger::info("插件【{$pluginName}】无依赖");
			}
	
			// 如果有autoload配置且尚未生成，才生成autoload
			if (isset($config['autoload']) && !$autoloadGenerated) {
				// 检查autoload文件是否已经存在
				$autoloadFiles = [
					$vendorDir . '/autoload.php',
					$vendorDir . '/composer/autoload_psr4.php',
					$vendorDir . '/composer/autoload_classmap.php'
				];
				
				$autoloadExists = true;
				foreach ($autoloadFiles as $file) {
					if (!file_exists($file)) {
						$autoloadExists = false;
						break;
					}
				}
				
				if (!$autoloadExists) {
					Logger::info("生成插件【{$pluginName}】autoload...");
					$installationManager = $composer->getInstallationManager();
					$repoManager = $composer->getRepositoryManager();
					$autoloadGenerator = $composer->getAutoloadGenerator();
					
					$autoloadGenerator->dump(
						$composer->getConfig(),
						$repoManager->getLocalRepository(),
						$composer->getPackage(),
						$installationManager,
						'composer',
						false // 优化autoload
					);
					Logger::success("插件【{$pluginName}】Autoload 生成成功");
				} else {
					Logger::info("插件【{$pluginName}】autoload 已存在，跳过生成");
				}
			}
	
			chdir($originalCwd);
			return true;
			
		} catch (\Exception $e) {
			if (isset($originalCwd)) {
				chdir($originalCwd);
			}
			
			// 特殊处理"无依赖"的提示信息
			if (strpos($e->getMessage(), 'No dependencies to install or update') !== false) {
				Logger::info("插件【{$pluginName}】无依赖需要安装");
				return true;
			}
			
			Logger::error("插件【{$pluginName}】处理失败: " . $e->getMessage());
			return false;
		}
	}

	/**
	 * 加载插件自动加载（优化版：避免重复加载和注册）
	 */
	public function loadPluginAutoload(string $pluginName): bool {
		static $loadedPlugins = [];
		
		if (in_array($pluginName, $loadedPlugins)) {
			Logger::debug("插件【{$pluginName}】自动加载已注册，跳过重复加载");
			return true;
		}
		
		$pluginDir = $this->pluginRootDir . $pluginName . '/';
		$config = $this->readPluginConfig($pluginName);
		if (!$config) {
			Logger::error("插件【{$pluginName}】配置读取失败");
			return false;
		}
	
		// 1. 优先加载 vendor/autoload（带等待机制）
		$composerAutoload = $pluginDir . 'vendor/autoload.php';
		$composerDir = $pluginDir . 'vendor/composer/';
		
		$autoloadReady = false;
		if (is_dir($composerDir)) {
			// 检查关键的 autoload 文件是否存在
			$requiredFiles = [
				$composerAutoload,
				$composerDir . 'autoload_psr4.php',
				$composerDir . 'autoload_classmap.php'
			];
			
			$autoloadReady = true;
			foreach ($requiredFiles as $file) {
				if (!file_exists($file)) {
					$autoloadReady = false;
					break;
				}
			}
		}
	
		// 如果 autoload 文件不完整，等待生成
		if (!$autoloadReady) {
			Logger::info("等待插件【{$pluginName}】autoload 文件生成...");
			$maxWait = 5; // 最大等待5秒
			$waited = 0;
			$checkInterval = 0.2; // 检查间隔200毫秒
			
			while ($waited < $maxWait) {
				// 检查所有必需文件是否都存在
				$allFilesExist = true;
				foreach ($requiredFiles as $file) {
					if (!file_exists($file)) {
						$allFilesExist = false;
						break;
					}
				}
				
				if ($allFilesExist) {
					$autoloadReady = true;
					break;
				}
				
				System::sleep($checkInterval);
				$waited += 0.2;
				
				// 每1秒输出一次日志，避免日志过多
				if (round($waited, 1) == floor($waited) || $waited >= $maxWait) {
					Logger::debug("等待 autoload 文件生成... [{$waited}s]");
				}
			}
		}
	
		if ($autoloadReady && file_exists($composerAutoload)) {
			require_once $composerAutoload;
			Logger::success("已加载 vendor/autoload（插件【{$pluginName}】）");
			$loadedPlugins[] = $pluginName;
			return true;
		}
	
		// 2. 备用方案：手动注册 PSR-4
		if (isset($config['autoload']['psr-4'])) {
			Logger::warning("vendor/autoload 未就绪，手动注册 PSR-4（插件【{$pluginName}】）");
			$registered = $this->registerPsr4Autoload($pluginName, $config['autoload']['psr-4'], $pluginDir);
			
			if ($registered) {
				$loadedPlugins[] = $pluginName;
				return true;
			}
		}
	
		Logger::error("插件【{$pluginName}】autoload 加载失败");
		return false;
	}
	
	/**
	 * 注册PSR-4自动加载（带去重检查）
	 */
	private function registerPsr4Autoload(string $pluginName, array $psr4Config, string $pluginDir): bool {
		static $registeredNamespaces = []; // 跟踪已注册的命名空间
		
		$registered = false;
		
		foreach ($psr4Config as $namespace => $path) {
			// 检查是否已经注册过这个命名空间
			$namespaceKey = $pluginName . ':' . $namespace;
			if (in_array($namespaceKey, $registeredNamespaces)) {
				Logger::debug("命名空间【{$namespace}】已注册，跳过重复注册");
				continue;
			}
			
			$fullPath = $pluginDir . $path;
			if (!is_dir($fullPath)) {
				Logger::warning("PSR-4路径不存在: {$fullPath}");
				continue;
			}
			
			spl_autoload_register(function ($class) use ($namespace, $fullPath) {
				if (strpos($class, $namespace) === 0) {
					$relativeClass = substr($class, strlen($namespace));
					$file = $fullPath . str_replace('\\', '/', $relativeClass) . '.php';
					if (file_exists($file)) {
						require $file;
					}
				}
			});
			
			$registeredNamespaces[] = $namespaceKey;
			$registered = true;
			Logger::debug("注册命名空间: {$namespace} -> {$fullPath}");
		}
		
		return $registered;
	}
}