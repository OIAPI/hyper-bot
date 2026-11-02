<?php
namespace Utils;

class DeviceRegister
{
	/**
	 * 主注册接口
	 */
	private const REGISTER_URL = 'https://log5-applog.fqnovel.com/service/2/device_register/';
	
	/**
	 * 备用注册接口
	 */
	private const BACKUP_REGISTER_URL = 'https://hyper.lkaa.top/api/register';
	
	/**
	 * 缓存文件路径
	 */
	private const CACHE_FILE = __DIR__ . '/../../runtime/device_register_cache.json';
	
	/**
	 * 设备品牌与型号池（品牌 => 型号列表）
	 */
	private const DEVICE_POOL = [
		'OnePlus' => ['PJZ110', 'PHB110', 'NE2210', 'LE2110', 'EB2101'],
		'Xiaomi' => ['2210132C', '2201123C', '23013RK75C', '23021RAA2Y', 'M2101K9C'],
		'Huawei' => ['ALN-AL00', 'MNA-AL00', 'FOA-AL00', 'CET-AL00', 'LIO-AL00'],
		'Samsung' => ['SM-S9180', 'SM-S9080', 'SM-A5460', 'SM-F9460', 'SM-G9980'],
		'OPPO' => ['PGEM10', 'CPH2525', 'CPH2483', 'CPH2499', 'PENM00'],
		'vivo' => ['V2227A', 'V2254A', 'V2283A', 'V2244', 'V2183A'],
		'Realme' => ['RMX3820', 'RMX3740', 'RMX3710', 'RMX3686', 'RMX3360'],
		'Google' => ['GP1BC', 'GVU6C', 'GHL1X', 'G9FPL', 'GR1YH'],
	];
	
	/**
	 * 设备制造商映射（品牌 => 制造商名）
	 */
	private const MANUFACTURER_MAP = [
		'OnePlus' => 'OnePlus',
		'Xiaomi' => 'Xiaomi',
		'Huawei' => 'HUAWEI',
		'Samsung' => 'samsung',
		'OPPO' => 'OPPO',
		'vivo' => 'vivo',
		'Realme' => 'realme',
		'Google' => 'Google',
	];
	
	/**
	 * ROM 版本池
	 */
	private const ROM_VALUES = ['1414', '1415', '1416', '1417', '1418', '1419', '1420'];
	
	/**
	 * 分辨率池
	 */
	private const RESOLUTIONS = [
		'1440*3200', '1080*2400', '1080*2340', '1080*2408',
		'1440*3040', '1080*2412', '1600*900', '2400*1080',
	];
	
	/**
	 * DPI 池
	 */
	private const DPIS = ['320', '400', '420', '440', '480', '560'];
	
	/**
	 * 显示密度池
	 */
	private const DISPLAY_DENSITIES = ['xxhdpi', 'xhdpi', 'xxxhdpi', 'hdpi'];
	
	/**
	 * 语言池
	 */
	private const LANGUAGES = ['zh', 'zh_CN', 'zh-Hans-CN'];
	
	/**
	 * 时区池
	 */
	private const TIMEZONES = ['Asia/Shanghai', 'Asia/Chongqing', 'Asia/Harbin'];
	
	/**
	 * region 池
	 */
	private const REGIONS = ['CN', 'cn'];
	
	/**
	 * access 池（网络类型）
	 */
	private const ACCESS_TYPES = ['wifi', '4g', '5g'];
	
	/**
	 * 显示名称池
	 */
	private const DISPLAY_NAMES = ['番茄小说', '番茄免费小说', '番茄畅听', '番茄'];

	private const CHECK_URL = 'https://log5-applog.fqnovel.com/service/2/app_alert_check/';
	
	/**
	 * 注册设备
	 * 
	 * 优先主接口（完整参数），失败降级到备用接口
	 * 
	 * @param int $maxRetry 每个接口最大重试次数
	 * @return array 成功返回设备数据，失败返回空数组
	 */
	public static function register(int $maxRetry = 1): array
	{
		// 优先：主接口（完整参数）
		$result = self::registerWithPrimary($maxRetry);
		if (!empty($result)) {
			self::check($result);
			return $result;
		}
		/*
		// 降级：备用接口
		error_log('主接口注册失败，降级到备用接口' . PHP_EOL);
		$result = self::registerWithBackup($maxRetry);
		// print_r($result);
		if (!empty($result)) {
			self::check($result);
			return $result;
		}
		
		error_log('主接口和备用接口都失败' . PHP_EOL);
		// exit();
		*/
		return [];
	}
	
	/**
	 * 通过主接口注册（完整参数）
	 */
	private static function registerWithPrimary(int $maxRetry): array
	{
		for ($i = 0; $i < $maxRetry; $i++) {
			try {
				$deviceInfo = self::normalizeDeviceInfo();
				
				$registerUrl = self::buildRegisterUrl($deviceInfo);
				$headers = self::buildHeaders($deviceInfo);
				$payload = self::buildPayload($deviceInfo);
				
				$curl = new CoroutineCurl;
				$curl->setheaders($headers);
				$curl->post($registerUrl, json_encode($payload, 320));
				
				if (!$curl->result) {
					echo("device_register 主接口第 {$i} 次: 空响应");
					usleep(500000);
					continue;
				}
				
				$jsonData = $curl->array();
				if (is_array($jsonData) && isset($jsonData['device_id_str']) && $jsonData['device_id_str']) {
					self::saveToCache($jsonData);
					return $jsonData;
				}
				
				error_log("device_register 主接口第 {$i} 次失败: " . json_encode($jsonData, 320));
			} catch (\Throwable $e) {
				error_log("device_register 主接口异常: " . $e->getMessage());
			}
			
			usleep(500000);
		}
		
		return [];
	}
	
	/**
	 * 通过备用接口注册（GET 请求）
	 * 
	 * 备用接口返回结构：{code, message, data: {device_id_str, install_id_str, device_token, ...}}
	 */
	private static function registerWithBackup(int $maxRetry): array
	{
		for ($i = 0; $i < $maxRetry; $i++) {
			try {
				$curl = new CoroutineCurl;
				$curl->get(self::BACKUP_REGISTER_URL);
				
				if (!$curl->result) {
					error_log("device_register 备用接口第 {$i} 次: 空响应");
					usleep(500000);
					continue;
				}
				
				$jsonData = $curl->array();
				if (!is_array($jsonData)) {
					error_log("device_register 备用接口第 {$i} 次: JSON解析失败");
					usleep(500000);
					continue;
				}
				
				// 备用接口返回 {code, message, data}
				if (isset($jsonData['code']) && $jsonData['code'] != 1) {
					error_log("device_register 备用接口第 {$i} 次失败: " . ($jsonData['message'] ?? '未知错误'));
					usleep(500000);
					continue;
				}
				
				if (isset($jsonData['device_id_str']) && $jsonData['device_id_str']) {
					self::saveToCache($jsonData);
					return $jsonData;
				}
				
				error_log("device_register 备用接口第 {$i} 次失败: 数据不完整");
			} catch (\Throwable $e) {
				error_log("device_register 备用接口异常: " . $e->getMessage());
			}
			
			usleep(500000);
		}
		
		return [];
	}
	public static function check(array $deviceInfo) : void
	{
		$cookie = Utils::json2cookie($deviceInfo);
		$curl = new CoroutineCurl;
		$curl->addHeaders('Cookie: ' . $cookie);
		$curl->get(self::CHECK_URL);
	}
	/**
	 * 构建主接口注册 URL
	 */
	private static function buildRegisterUrl(array $deviceInfo): string
	{
		$params = [
			'aid' => '1967',
			'version_code' => '68132',
			'channel' => 'googleplay',
			'package' => 'com.dragon.read.oversea.gp',
			'_rticket' => $deviceInfo['rticket'],
			'use_store_region_cookie' => '1',
			'okhttp_version' => '4.2.137.76-fanqie',
		];
		
		return self::REGISTER_URL . '?' . http_build_query($params);
	}
	
	/**
	 * 构建主接口请求头
	 */
	private static function buildHeaders(array $deviceInfo): array
	{
		return [
			'Content-Type: application/json',
			'Accept: application/json',
			'Accept-Encoding: gzip',
			'log-encode-type: gzip',
			'x-ss-req-ticket: ' . $deviceInfo['gen_time'],
			'x-vc-bdturing-sdk-version: 3.7.2.cn',
			'User-Agent: ' . $deviceInfo['user_agent'],
			'Cookie: ' . $deviceInfo['cookie'],
		];
	}
	
	/**
	 * 构建主接口请求体（完整参数）
	 */
	private static function buildPayload(array $deviceInfo): array
	{
		$header = self::buildAppInfo($deviceInfo);
		
		$header['os'] = 'Android';
		$header['os_version'] = $deviceInfo['os_version'];
		$header['os_api'] = $deviceInfo['os_api'];
		$header['device_model'] = $deviceInfo['device_type'];
		$header['device_brand'] = $deviceInfo['device_brand'];
		$header['device_manufacturer'] = $deviceInfo['device_manufacturer'];
		$header['cpu_abi'] = $deviceInfo['cpu_abi'];
		$header['release_build'] = $deviceInfo['release_build'];
		$header['density_dpi'] = self::parseInteger($deviceInfo['dpi'] ?? '320', 320);
		$header['display_density'] = $deviceInfo['display_density'] ?? 'xxhdpi';
		$header['resolution'] = str_replace('*', 'x', $deviceInfo['resolution'] ?? '1600*900');
		$header['language'] = $deviceInfo['language'] ?? 'zh';
		$header['timezone'] = 8;
		$header['access'] = $deviceInfo['access'] ?? 'wifi';
		$header['rom'] = $deviceInfo['rom'] ?? self::randomRom();
		$header['rom_version'] = str_replace('+', ' ', $deviceInfo['rom_version'] ?? 'V433IR+release-keys');
		$header['cdid'] = $deviceInfo['cdid'];
		$header['sig_hash'] = $deviceInfo['sig_hash'];
		$header['openudid'] = $deviceInfo['openudid'];
		$header['clientudid'] = $deviceInfo['clientudid'];
		
		$ipv6Map = [
			'type' => 'client_anpi',
			'value' => $deviceInfo['ipv6_address'],
		];
		$header['ipv6_list'] = [$ipv6Map];
		
		$header['region'] = $deviceInfo['region'] ?? 'CN';
		$header['tz_name'] = $deviceInfo['tz_name'] ?? 'Asia/Shanghai';
		$header['tz_offset'] = 28800;
		$header['sim_serial_number'] = [];
		$header['oaid_may_support'] = false;
		$header['req_id'] = $deviceInfo['req_id'];
		$header['device_platform'] = 'android';
		
		$custom = [
			'host_bit' => 64,
			'account_region' => 'cn',
			'dragon_device_type' => 'phone',
		];
		$header['custom'] = $custom;
		
		$header['apk_first_install_time'] = $deviceInfo['apk_first_install_time'];
		
		return [
			'magic_tag' => 'ss_app_log',
			'header' => $header,
			'_gen_time' => $deviceInfo['gen_time'],
		];
	}
	
	/**
	 * 构建应用信息
	 */
	private static function buildAppInfo(array $deviceInfo): array
	{
		return [
			'display_name' => $deviceInfo['display_name'] ?? '番茄小说',
			'aid' => 1967,
			'channel' => 'googleplay',
			'package' => 'com.dragon.read.oversea.gp',
			'app_version' => '6.8.1.32',
			'version_code' => 68132,
			'update_version_code' => 68132,
			'manifest_version_code' => 68132,
			'app_version_minor' => '6.8.1.32',
			'sdk_version' => $deviceInfo['sdk_version'] ?? '3.7.0-rc.25-fanqie-xiaoshuo-opt',
			'sdk_target_version' => 29,
			'git_hash' => $deviceInfo['git_hash'] ?? '5b6a0d3',
			'sdk_flavor' => $deviceInfo['sdk_flavor'] ?? 'china',
			'guest_mode' => 0,
			'is_system_app' => 0,
			'pre_installed_channel' => '',
			'not_request_sender' => 0,
		];
	}
	
	/**
	 * 构建随机的设备信息（完整版）
	 */
	private static function normalizeDeviceInfo(): array
	{
		$now = (int)(microtime(true) * 1000);
		$deviceInfo = [];
		
		// ============ 品牌 / 型号 / 制造商（一组，要匹配）============
		$brand = array_rand(self::DEVICE_POOL);
		$models = self::DEVICE_POOL[$brand];
		$deviceModel = $models[array_rand($models)];
		
		$deviceInfo['device_brand'] = $brand;
		$deviceInfo['device_type'] = $deviceModel;
		$deviceInfo['device_manufacturer'] = self::MANUFACTURER_MAP[$brand] ?? $brand;
		
		// ============ 系统版本（随机，要匹配 os_api）============
		$androidVersions = [
			['version' => '14', 'api' => 34, 'release' => 'UP1A.230905.007'],
			['version' => '13', 'api' => 33, 'release' => 'TP1A.220624.014'],
			['version' => '12', 'api' => 32, 'release' => 'SP1A.210812.016'],
			['version' => '12', 'api' => 31, 'release' => 'SP1A.210812.015'],
			['version' => '11', 'api' => 30, 'release' => 'RP1A.200720.011'],
			['version' => '10', 'api' => 29, 'release' => 'QP1A.190711.020'],
		];
		$android = $androidVersions[array_rand($androidVersions)];
		$deviceInfo['os_version'] = $android['version'];
		$deviceInfo['os_api'] = $android['api'];
		
		// ============ ROM 版本（随机）============
		$deviceInfo['rom'] = self::randomRom();
		$romVersion = self::randomRomVersion();
		$deviceInfo['rom_version'] = $romVersion;
		$deviceInfo['release_build'] = explode('+', $romVersion)[0] . '_20171120';
		
		// ============ 分辨率 / DPI / 密度（随机）============
		$deviceInfo['resolution'] = self::RESOLUTIONS[array_rand(self::RESOLUTIONS)];
		$deviceInfo['dpi'] = self::DPIS[array_rand(self::DPIS)];
		$deviceInfo['display_density'] = self::DISPLAY_DENSITIES[array_rand(self::DISPLAY_DENSITIES)];
		
		// ============ 语言 / 时区 / region / access（随机）============
		$deviceInfo['language'] = self::LANGUAGES[array_rand(self::LANGUAGES)];
		$deviceInfo['tz_name'] = self::TIMEZONES[array_rand(self::TIMEZONES)];
		$deviceInfo['region'] = self::REGIONS[array_rand(self::REGIONS)];
		$deviceInfo['access'] = self::ACCESS_TYPES[array_rand(self::ACCESS_TYPES)];
		
		// ============ CPU 架构 ============
		$deviceInfo['cpu_abi'] = 'arm64-v8a';
		$deviceInfo['host_abi'] = 'arm64-v8a';
		
		// ============ 各种设备标识（全部随机）============
		$deviceInfo['android_id'] = self::randomHex(16);
		$deviceInfo['openudid'] = self::generateOpenudid($deviceInfo['android_id']);
		$deviceInfo['clientudid'] = self::generateUUID();
		$deviceInfo['sig_hash'] = self::randomHex(32);
		$deviceInfo['cdid'] = self::generateUUID();
		$deviceInfo['req_id'] = self::generateUUID();
		$deviceInfo['ipv6_address'] = self::randomIpv6();
		$deviceInfo['device_id'] = self::randomNumeric(16);
		$deviceInfo['install_id'] = self::randomNumeric(16);
		
		// ============ 显示名称（随机）============
		$deviceInfo['display_name'] = self::DISPLAY_NAMES[array_rand(self::DISPLAY_NAMES)];
		
		// ============ 时间戳 ============
		$deviceInfo['gen_time'] = $now;
		$deviceInfo['rticket'] = $now;
		$deviceInfo['apk_first_install_time'] = $now - random_int(86400000, 31536000000);
		
		// ============ Cookie ============
		$deviceInfo['cookie'] = sprintf(
			'store-region=cn-zj; store-region-src=did; install_id=%s;',
			$deviceInfo['install_id']
		);
		
		// ============ User-Agent（根据随机设备信息拼装）============
		$release = explode('+', $deviceInfo['rom_version'])[0];
		$deviceInfo['user_agent'] = sprintf(
			'com.dragon.read.oversea.gp/68132 (Linux; U; Android %s; %s; %s; Build/%s;tt-ok/3.12.13.4-tiktok)',
			$deviceInfo['os_version'],
			$deviceInfo['language'],
			$deviceInfo['device_type'],
			$release
		);
		
		// ============ 版本信息 ============
		$deviceInfo['aid'] = '1967';
		$deviceInfo['version_code'] = '68132';
		$deviceInfo['version_name'] = '6.8.1.32';
		$deviceInfo['update_version_code'] = '68132';
		
		// ============ 其他随机字段 ============
		$deviceInfo['manifest_version_code'] = 68132;
		$deviceInfo['sdk_version'] = '3.7.0-rc.25-fanqie-xiaoshuo-opt';
		$deviceInfo['sdk_target_version'] = 29;
		$deviceInfo['git_hash'] = self::randomHex(7);
		$deviceInfo['sdk_flavor'] = 'china';
		
		return $deviceInfo;
	}
	
	/**
	 * 随机 ROM 版本号
	 */
	private static function randomRomVersion(): string
	{
		$prefix = self::randomRom();
		$letters = ['A', 'B', 'C', 'D', 'E', 'F', 'R', 'S', 'T', 'U', 'V'];
		$letter = $letters[array_rand($letters)];
		$number = random_int(100, 999);
		$suffixes = ['IR', 'MR', 'TR', 'PR'];
		$suffix = $suffixes[array_rand($suffixes)];
		
		return sprintf('%s%s%s%s+release-keys', $prefix, $letter, $number, $suffix);
	}
	
	/**
	 * 解析整数
	 */
	private static function parseInteger(string $value, int $defaultValue): int
	{
		return is_numeric($value) ? (int)$value : $defaultValue;
	}
	
	/**
	 * 随机 ROM
	 */
	private static function randomRom(): string
	{
		return self::ROM_VALUES[array_rand(self::ROM_VALUES)];
	}
	
	/**
	 * 随机数字
	 */
	private static function randomNumeric(int $length): string
	{
		$sb = '';
		for ($i = 0; $i < $length; $i++) {
			$digit = random_int(0, 9);
			if ($i === 0 && $digit === 0) {
				$digit = 1;
			}
			$sb .= $digit;
		}
		return $sb;
	}
	
	/**
	 * 随机十六进制
	 */
	private static function randomHex(int $length): string
	{
		$chars = '0123456789abcdef';
		$sb = '';
		for ($i = 0; $i < $length; $i++) {
			$sb .= $chars[random_int(0, strlen($chars) - 1)];
		}
		return $sb;
	}
	
	/**
	 * 随机 IPv6
	 */
	private static function randomIpv6(): string
	{
		$parts = [];
		for ($i = 0; $i < 8; $i++) {
			$parts[] = sprintf('%04X', random_int(0, 65535));
		}
		return implode(':', $parts);
	}
	
	/**
	 * 生成 OpenUDID
	 */
	private static function generateOpenudid(string $androidId): string
	{
		$md5_1 = md5($androidId);
		$md5_2 = md5($md5_1);
		return strtolower($md5_1 . substr($md5_2, 0, 8));
	}
	
	/**
	 * 生成 UUID
	 */
	private static function generateUUID(): string
	{
		if (function_exists('random_bytes')) {
			$data = random_bytes(16);
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40);
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80);
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}
		
		return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand(0, 0xffff), mt_rand(0, 0xffff),
			mt_rand(0, 0xffff),
			mt_rand(0, 0x0fff) | 0x4000,
			mt_rand(0, 0x3fff) | 0x8000,
			mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
		);
	}
	
		/**
	 * 保存到缓存
	 */
	private static function saveToCache(array $deviceInfo): void
	{
		$dir = dirname(self::CACHE_FILE);
		if (!is_dir($dir)) {
			mkdir($dir, 0755, true);
		}
		
		file_put_contents(self::CACHE_FILE, json_encode($deviceInfo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
	}
	
	/**
	 * 从缓存读取
	 */
	public static function get(): array
	{
		if (!file_exists(self::CACHE_FILE)) {
			return self::register();
		}
		
		$content = file_get_contents(self::CACHE_FILE);
		if ($content === false) {
			return self::register();
		}
		
		$data = json_decode($content, true);
		
		if (is_array($data) && !empty($data['device_id_str'])) {
			return $data;
		}
		
		@unlink(self::CACHE_FILE);
		return self::register();
	}
	
	/**
	 * 清除缓存
	 */
	public static function clear(): void
	{
		if (file_exists(self::CACHE_FILE)) {
			@unlink(self::CACHE_FILE);
		}
	}
}