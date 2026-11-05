<?php
namespace Api;

use Utils\CoroutineCurl;
use Utils\Env;

class app {
	protected $config, $api;
	protected $curl;
	public function __construct() {
		// 主动发消息同样走账号凭据：有事件上下文用当前账号；
		// 没有上下文时按 OneBot → QQ 官方 → Milky 的顺序找启用账号
		$account = \Core\Bot\AccountContext::current()
			?? \Core\Bot\Accounts::forProtocol('onebot')
			?? \Core\Bot\Accounts::firstEnabled('qqbot')
			?? \Core\Bot\Accounts::firstEnabled('milky');
		$protocol = strtolower((string)($account['protocol'] ?? ''));
		$this->api = trim((string)($account['api'] ?? ''));
		if ($this->api === '') {
			$this->api = match ($protocol) {
				'qqbot' => \Protocol\QqBot\Token::apiBase(),
				'milky' => (string)(\Core\Config::string('protocol.milky.api', '') ?: Env::get('cq.api')),
				default => (string)Env::get('cq.api'),
			};
		}
		$this->curl = new CoroutineCurl();
		$this->curl->timeout(120);
		$this->curl->addheaders('Authorization', 'Bearer ' . ((($account['token'] ?? '') ?: Env::get('cq.auth'))));
		$this->curl->addheaders('content-type', 'application/json');
	}
	/**
	 * 统一入口：若 URL 指向协议端、且当前有事件来源适配器
	 * （或默认后端本身就不是 HTTP），则通过协议桥转发；
	 * 否则保持原有直连行为（第三方接口必须直连，不能被当成 OneBot action）。
	 */
	protected function get($url, ?array $data = []) : object {
		if ($this->shouldBridge($url)) {
			return \Protocol\Core\Bridge::call($this->actionOf($url), $data ?: [], 'GET');
		}
		if($data) {
			if(str_contains($url, '?')) {
				$url .= '&' . http_build_query($data);
			} else {
				$url .= '?' . http_build_query($data);
			}
		}
		$this->curl->get($url);
		return ($this->curl->error ? $this->curl : $this->curl->object()) ?? new \stdClass();
	}
	protected function post($url, ?array $data = []) : object {
		if ($this->shouldBridge($url)) {
			return \Protocol\Core\Bridge::call($this->actionOf($url), is_array($data) ? $data : [], 'POST');
		}
		$data = is_array($data) || is_object($data) ? json_encode($data, 320) : '{}';
		$this->curl->post($url, $data);
		return ($this->curl->error ? $this->curl : $this->curl->object()) ?? new \stdClass();
	}

	/**
	 * 这个 URL 是否属于「当前账号的协议端」。
	 *
	 * 不能只看 {cq.api}：QQ 官方机器人 / Milky 的 api 是账号自己的地址。
	 * 有事件上下文时，回复必须交给来源适配器，不能直连 HTTP。
	 */
	protected function shouldBridge(string $url) : bool {
		$base = rtrim((string)$this->api, '/');
		if ($base === '' || !str_starts_with($url, $base)) {
			return false;
		}
		if (\Protocol\Core\Bridge::hasAdapter()) {
			return true;
		}
		return \Protocol\Core\Bridge::fallback()->name() !== 'http';
	}

	/**
	 * 从完整 URL 提取 OneBot action 名（{cq.api}/send_group_msg → send_group_msg）
	 */
	protected function actionOf(string $url) : string {
		$path = parse_url($url, PHP_URL_PATH) ?: $url;
		$segments = array_values(array_filter(explode('/', (string)$path), fn($v) => $v !== ''));
		return $segments === [] ? '' : (string)end($segments);
	}
}