<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\Context;
use Core\Bot\Event;
use Protocol\Core\Bridge;
use Protocol\QqBot\Adapter as QqBotAdapter;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

final class BridgeProbeApi extends \Api\app {
    public function useApi(string $api): void { $this->api = $api; }
    public function canBridge(string $url): bool { return $this->shouldBridge($url); }
}

$api = new BridgeProbeApi();
$check('无上下文时 hasAdapter=false', Bridge::hasAdapter() === false);
$api->useApi('https://api.bot.qq.com');
$check('无上下文时协议地址不强制走桥', $api->canBridge('https://api.bot.qq.com/send_group_msg') === false);

final class ProbeAdapter extends \Protocol\Core\Adapter {
    public array $calls = [];
    protected string $name = 'probe';
    public function call(string $action, array $params = [], string $method = 'POST'): object {
        $this->calls[] = [$action, $params];
        return (object)['status' => 'ok', 'retcode' => 0, 'data' => ['message_id' => 'm1'], 'message' => ''];
    }
}

final class ProbeMessageApi extends \Api\message {
    public function useApi(string $api): void { $this->api = $api; }
}

$event = Event::fromArray([
    'post_type' => 'message', 'message_type' => 'group',
    'group_id' => 'G', 'user_id' => 'U', 'raw_message' => 'hi',
]);
Context::run(new Context($event, new QqBotAdapter()), function () use ($api, $check): void {
    $check('QqBot 上下文 hasAdapter=true', Bridge::hasAdapter() === true);
    $check('官方机器人回复走协议桥', $api->canBridge('https://api.bot.qq.com/send_group_msg') === true);
    $check('第三方 URL 仍然直连', $api->canBridge('https://oiapi.net/api/Protobuf/') === false);
});
$check('退出上下文后 hasAdapter=false', Bridge::hasAdapter() === false);

// 端到端：Api\message 发群消息必须交给上下文适配器，而不是直连 HTTP
$probeAdapter = new ProbeAdapter();
$probeApi = new ProbeMessageApi();
$probeApi->useApi('https://api.bot.qq.com');
Context::run(new Context($event, $probeAdapter), function () use ($probeApi, $probeAdapter, $check): void {
    $res = $probeApi->sendGroupMsg('GROUP_OPENID', 'hello');
    $check('sendGroupMsg 走适配器', ($res->status ?? '') === 'ok' && count($probeAdapter->calls) === 1);
    $check('action 正确', ($probeAdapter->calls[0][0] ?? '') === 'send_group_msg');
    $check('group_id 原样传入', ($probeAdapter->calls[0][1]['group_id'] ?? '') === 'GROUP_OPENID');
});

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
