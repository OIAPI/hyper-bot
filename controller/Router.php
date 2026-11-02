<?php
namespace Controller;

use Utils\Logger;
use Utils\Cache;
use Utils\Env;
use Utils\Utils;
use Controller\Attr\Rule;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use DirectoryIterator;

/**
 * @name Route
 * @desc 路由（优化版：自动扫描+配置化）
 */
class Router {
	public static $list = [];
	public static $menu = [];
	public static $Route;
	public static array $appDocs = [];
	private static $pluginsLoaded = false; // 新增静态变量
	
	use \Controller\Traits\staticWorker;

	// 配置：支持多目录扫描
	public static $config = [
		'app_dirs' => [ // 改为复数，支持多个目录
			APP_PATH . '/Plugins',	  // 原有目录
		],
		'namespace_prefixes' => [	   // 对应的命名空间前缀
			'App\Plugins\\',			// 原有目录的命名空间
		],
		'exclude_classes' => [
			'App\BaseApp',
		],
		'filter' => [ //排除这俩
			'App\Plugins\Help',
			'App\Plugins\Sys',
		],
	];

	private static $pluginManager;

	public static function initPluginManager() {
		if (!self::$pluginManager) {
			$github = Env::get('github.localPath', __runtime__ . '/github');
			
			if(!in_array($github, self::$config['app_dirs'])) self::$config['app_dirs'][] = $github; //添加github目录
			self::$config['namespace_prefixes'] = array_pad(self::$config['namespace_prefixes'], Count(self::$config['app_dirs']), '');
			self::$pluginManager = new \Utils\PluginManager($github);
		}
		return self::$pluginManager;
	}
	/**
	 * 路由列表：自动扫描目录+反射解析
	 */
	public static function list() {
		if (!empty(self::$list)) return self::$list;

		// 1. 自动扫描应用目录下的所有类文件
		$classList = self::scanAppClasses();
		// var_dump($classList);
		if (empty($classList)) return self::$list;

		// 2. 反射解析类与方法（逻辑复用原逻辑，仅数据源改为扫描结果）
		// WS / 常驻事件可能没有 group_id（私聊、QQ 官方机器人、Milky 私聊），需容错
		$param = Cache::$param;
		$groupId = is_object($param) ? ($param->group_id ?? 0) : 0;
		$info = self::getGroupInfo($groupId);

		foreach ($classList as $appClass => $status) {
			// 过滤未在group worker中的类（保留原业务逻辑）
			if (!isset($info->worker->$appClass) || in_array($appClass, self::$config['exclude_classes']) || $info->worker->$appClass === false) {
				continue;
			}

			$reflect = new ReflectionClass($appClass);
			self::appDocs($appClass, $reflect->getDocComment());

			foreach ($reflect->getMethods() as $method) {
				foreach ($method->getAttributes() as $attr) {
					$attrName = $attr->getName();
					if (!isset(self::$list[$attrName])) self::$list[$attrName] = [];

					$Attribute = $attr->newInstance();
					array_push(self::$list[$attrName], [
						'match' => $Attribute->match,
						'class' => $appClass, // 无需手动加反斜杠，反射已返回完整类名
						'func' => $method->getName(),
						'pattern' => $Attribute->pattern,
						'permission' => $Attribute->permission,
						'name' => $Attribute->name,
					]);

					if ($Attribute->name) self::$menu[] = $Attribute->name;
				}
			}
		}

		return self::$list;
	}

	/**
	 * 自动扫描所有应用目录下的可路由类（兼容原有+新插件）
	 */
	public static function scanAppClasses(): array {
		$classList = [];
		$pluginManager = self::initPluginManager();
	
		// 扫描所有配置的目录
		foreach (self::$config['app_dirs'] as $index => $dir) {
			$namespacePrefix = self::$config['namespace_prefixes'][$index];
			
			if (!is_dir($dir)) {
				Logger::warning("路由扫描：目录不存在 -> {$dir}");
				continue;
			}
	
			// 如果是插件目录，先确保插件已加载
			if ($dir === Env::get('github.localPath', __runtime__ . '/github')) {
				self::loadAllPlugins($pluginManager);
			}
	
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
				RecursiveIteratorIterator::CHILD_FIRST
			);
	
			foreach ($iterator as $file) {
				if ($file->isFile() && $file->getExtension() === 'php') {
					$className = self::getClassNameFromFile($file, $dir, $namespacePrefix);
					if ($className && class_exists($className)) {
						$classList[] = $className;
					}
				}
			}
		}
		sort($classList);
		Utils::fill($classList, self::$config['filter']);
		return $classList;
	}
	
	/**
	 * 从文件路径计算类名（支持动态解析插件命名空间）
	 */
	private static function getClassNameFromFile($file, $baseDir, $namespacePrefix): ?string {
		$relativePath = ltrim(str_replace([$baseDir, '.php'], '', $file->getRealPath()), DIRECTORY_SEPARATOR);
		// 如果是插件目录，需要从 composer.json 读取命名空间
		if ($baseDir === Env::get('github.localPath', __runtime__ . '/github')) {
			return self::getPluginClassName($file, $relativePath);
		}
		
		// 原有目录：使用固定命名空间前缀
		return $namespacePrefix . str_replace(DIRECTORY_SEPARATOR, '\\', ltrim($relativePath, DIRECTORY_SEPARATOR));
	}
	
	/**
	 * 从插件文件获取完整类名
	 */
	private static function getPluginClassName($file, $relativePath): ?string {
		$pluginManager = self::initPluginManager();
		$filePath = $file->getRealPath();
		// 提取插件名称（目录名）
		$pathParts = explode(DIRECTORY_SEPARATOR, trim($relativePath, DIRECTORY_SEPARATOR));
		$pluginName = $pathParts[0]; //插件名字
		
		// 读取插件的 composer.json 配置
		$config = $pluginManager->readPluginConfig($pluginName);
		if (!$config || !isset($config['autoload']['psr-4'])) {
			return null;
		}
		
		// 查找匹配的命名空间
		foreach ($config['autoload']['psr-4'] as $namespace => $psr4Path) {
			$psr4Path = rtrim($psr4Path, '/') . '/';
			$namespace = rtrim($namespace, '\\') . '\\';
			$relativeClassPath = $pluginName . DIRECTORY_SEPARATOR . $psr4Path;
			// 检查文件是否在该 PSR-4 路径下
			if (strpos($relativePath, $relativeClassPath) === 0) {
				$relativeClassPath = substr($relativePath, strlen($relativeClassPath));
				$relativeClassPath = str_replace('.php', '', $relativeClassPath);
				$className = $namespace . str_replace('/', '\\', $relativeClassPath);
				
				return $className;
			}
		}
		
		return null;
	}
	
	/**
	 * 加载所有插件的依赖和自动加载
	 */
	private static function loadAllPlugins($pluginManager): void {
		if (self::$pluginsLoaded) {
			return; // 已经加载过，直接返回
		}
		$pluginDir = Env::get('github.localPath', __runtime__ . '/github');
		if (!is_dir($pluginDir)) return;
		// 扫描插件目录
		$iterator = new DirectoryIterator($pluginDir);
		foreach ($iterator as $fileInfo) {
			if ($fileInfo->isDir() && !$fileInfo->isDot()) {
				$pluginName = $fileInfo->getFilename();
				
				// 安装插件依赖并生成 autoload
				if($pluginManager->installPluginDeps($pluginName) === true) {
				
					// 加载插件的自动加载
					$pluginManager->loadPluginAutoload($pluginName);
				}
			}
		}
	}


	/**
	 * 菜单优化：去重+按长度排序（保留原逻辑，优化代码简洁性）
	 */
	public static function getMenu() {
		// 去重（用array_unique替代array_flip，语义更清晰）
		$uniqueMenu = array_unique(self::$menu);
		// 按字符串长度排序（保留原逻辑）
		usort($uniqueMenu, fn($a, $b) => mb_strlen($a) - mb_strlen($b));
		return $uniqueMenu;
	}


	// 以下方法保留原逻辑，仅优化细节
	public static function clean() {
		self::$list = [];
		self::$menu = []; // 清理菜单缓存（原逻辑遗漏）
		self::$pluginsLoaded = false; // 新增：重置插件加载状态
		return true;
	}
	/**
	 * 提取应用文档信息（带缓存）
	 */
	private static function appDocs(string $model, ?string $docs) {
		static $composerCache = [];
		$default = [
			'plugin' => '',
			'name' => '',
			'version' => '',
			'displayName' => '',
			'description' => '',
			'author' => '',
			'license' => '',
			'homepage' => '',
			'creationDate' => '',
			'lastUpdate' => '',
		];
		$array = [];
		
		// 1. 从类注释中提取
		if (!empty($docs)) {
			preg_match_all('/@(.+)/', $docs, $matches);
			if (!empty($matches[1])) {
				foreach ($matches[1] as $v) {
					list($key, $value) = array_pad(explode(' ', $v, 2), 2, '');
					$array[$key] = trim($value);
				}
			}
		} else {
			// 2. 从 composer.json 中补充（使用缓存）
			if (!isset($composerCache[$model])) {
				$composerCache[$model] = self::getComposerInfoForClass($model);
			}
			
			if ($composerCache[$model]) {
				$array = array_merge($composerCache[$model], $array);
			}
		}
		self::$appDocs[$model] = array_merge($default, $array);
	}
	
	/**
	 * 获取 composer.json 信息（优化版）
	 */
	private static function getComposerInfoForClass(string $className): ?array {
		static $pluginConfigs = [];
		
		$pluginDir = Env::get('github.localPath', __runtime__ . '/github');
		if (!is_dir($pluginDir)) {
			return null;
		}
		
		// 一次性加载所有插件配置
		if (empty($pluginConfigs)) {
			$pluginManager = self::initPluginManager();
			$iterator = new DirectoryIterator($pluginDir);
			foreach ($iterator as $fileInfo) {
				if ($fileInfo->isDir() && !$fileInfo->isDot()) {
					$pluginName = $fileInfo->getFilename();
					$config = $pluginManager->readPluginConfig($pluginName);
					if ($config) {
						$pluginConfigs[$pluginName] = $config;
					}
				}
			}
		}
		
		// 查找匹配的插件
		foreach ($pluginConfigs as $pluginName => $config) {
			if (isset($config['autoload']['psr-4'])) {
				foreach ($config['autoload']['psr-4'] as $namespace => $path) {
					$namespace = rtrim($namespace, '\\') . '\\';
					if (strpos($className, $namespace) === 0) {
						return [
							'plugin' => $pluginName,
							'name' => $config['name'] ?? $pluginName,
							'version' => $config['version'] ?? '',
							'displayName' => $config['displayName'] ?? ($config['name'] ?? $pluginName),
							'description' => $config['description'] ?? '',
							'author' => self::formatAuthor($config['author'] ?? ''),
							'license' => $config['license'] ?? '',
							'homepage' => $config['homepage'] ?? ($config['support']['sourceCode'] ?? ''),
							'creationDate' => $config['creationDate'] ?? '',
							'lastUpdate' => $config['lastUpdate'] ?? ''
						];
					}
				}
			}
		}
		
		return null;
	}
	
	/**
	 * 格式化作者信息
	 */
	private static function formatAuthor($author): string {
		if (is_array($author)) {
			if (isset($author['name'])) {
				return $author['name'];
			} elseif (!empty($author)) {
				return $author[0]['name'] ?? (string)$author[0];
			}
		}
		return (string)$author;
	}
}
