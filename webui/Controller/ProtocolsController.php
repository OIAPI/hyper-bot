<?php
namespace Webui\Controller;

use Core\Config;
use Core\Http\Request;
use Core\Http\Response;
use Protocol\Core\Adapter;
use Protocol\Milky\Adapter as MilkyAdapter;
use Protocol\OneBot\Adapter as OneBotAdapter;
use Protocol\QqBot\Adapter as QqBotAdapter;

/** 协议：状态 / 配置读取与修改 / 动作能力矩阵 / 连通性自测 */
final class ProtocolsController extends Base {
    public function index(Request $request): Response {
        return $this->ok($this->metrics->protocols());
    }

    /** 单个协议的完整配置（敏感字段已脱敏） */
    public function show(Request $request, array $params): Response {
        $name = (string)($params['protocol'] ?? '');
        if (!in_array($name, ['onebot', 'milky', 'qqbot'], true)) {
            return $this->fail('未知协议', -404, 404);
        }
        return $this->ok([
            'name'     => $name,
            'config'   => Config::section('protocol.' . $name),
            'status'   => $this->metrics->protocols()[$name] ?? [],
            'endpoints'=> $this->endpointList($name),
        ]);
    }

    /** 修改协议配置项（白名单，逐项写回 Config.ini） */
    public function update(Request $request, array $params): Response {
        $name = (string)($params['protocol'] ?? '');
        if (!in_array($name, ['onebot', 'milky', 'qqbot'], true)) {
            return $this->fail('未知协议', -404, 404);
        }
        $changes = (array)$request->input('config', []);
        $allowed = $this->allowedKeys($name);
        $applied = [];
        foreach ($changes as $key => $value) {
            $key = (string)$key;
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if (Config::set('protocol.' . $name . '.' . $key, $value)) {
                $applied[] = $key;
            }
        }
        return $this->ok(['applied' => $applied], '已保存，重启服务后生效');
    }

    /** 动作矩阵：Milky 支持哪些 OneBot action，哪些不支持及原因 */
    public function actions(Request $request, array $params): Response {
        $name = (string)($params['protocol'] ?? 'milky');
        if ($name === 'milky') {
            return $this->ok([
                'unsupported' => MilkyAdapter::unsupportedList(),
                'supported'   => MilkyAdapter::supportedList(),
            ]);
        }
        if ($name === 'qqbot') {
            return $this->ok(['unsupported' => QqBotAdapter::unsupportedList()]);
        }
        return $this->ok(['unsupported' => [], 'supported' => []]);
    }

    /** 端点清单（给运维看的「该配哪个地址」） */
    public function endpoints(Request $request, array $params): Response {
        $name = (string)($params['protocol'] ?? '');
        if (!in_array($name, ['onebot', 'milky', 'qqbot'], true)) {
            return $this->fail('未知协议', -404, 404);
        }
        return $this->ok($this->endpointList($name));
    }

    private function endpointList(string $name): array {
        $host = Config::string('http.host', '127.0.0.1');
        $port = Config::int('http.port', 8008);
        $base = (str_starts_with($host, '0.0.0.0') ? '127.0.0.1' : $host) . ':' . $port;
        $map = [
            'onebot' => [
                ['label' => '反向 WS（实现端连本框架）', 'url' => "ws://{$base}" . Config::string('onebot.reverse.path', '/onebot/v11/ws'), 'kind' => 'ws'],
                ['label' => 'HTTP 事件直推', 'url' => "http://{$base}/api/protocol/webhook", 'kind' => 'http'],
            ],
            'milky' => [
                ['label' => 'WebHook（协议端填这个）', 'url' => "http://{$base}" . Config::string('milky.webhook.path', '/api/protocol/milky/webhook'), 'kind' => 'http'],
                ['label' => '出站事件 WS（可选）', 'url' => "ws://{$base}" . Config::string('milky.ws.server_path', '/milky/event'), 'kind' => 'ws'],
            ],
            'qqbot' => [
                ['label' => 'WebHook（管理端填这个，需公网 HTTPS）', 'url' => 'https://<公网域名>' . Config::string('qqbot.webhook.path', '/api/protocol/qqbot/webhook'), 'kind' => 'http'],
                ['label' => 'Gateway（出站长连接）', 'url' => Config::string('qqbot.api', 'https://api.bot.qq.com') . '/gateway', 'kind' => 'ws'],
            ],
        ];
        return $map[$name] ?? [];
    }

    /** 允许通过 WebUI 修改的键（严禁 token / secret 以外的东西乱改） */
    private function allowedKeys(string $name): array {
        return match ($name) {
            'onebot' => ['enable', 'forward.enable', 'forward.url', 'forward.token', 'reverse.enable', 'reverse.path', 'reverse.token', 'self_id'],
            'milky'  => ['enable', 'api', 'token', 'timeout', 'event.mode', 'webhook.path', 'ws.url', 'sse.url', 'ws.server_path'],
            'qqbot'  => ['enable', 'app_id', 'client_secret', 'api', 'token_url', 'event.mode', 'webhook.path', 'webhook.verify', 'intents', 'gateway.shard', 'gateway.shard_id', 'gateway.shard_count', 'gateway.resume', 'self_id'],
            default  => [],
        };
    }
}