<?php
namespace Webui\Controller;

use Core\Bot\Accounts;
use Core\Http\Request;
use Core\Http\Response;

/**
 * 机器人账号（多账号）。
 *
 * 「一个协议只能配一个机器人」是 v1 的硬限制：所有协议层代码都直接读 Config.ini 里那唯一一份
 * api / token / self_id。这里把账号变成一等公民：同一协议可以挂多个账号，各自一套地址与凭据，
 * 事件按 self_id（或 ?account=）路由到对应账号，回包也用该账号的 API 地址。
 */
final class AccountsController extends Base
{
    /** 列表 + 可选协议 + 存储路径 */
    public function index(Request $request): Response
    {
        $rows = Accounts::publicList();
        $stats = ['total' => count($rows), 'enabled' => 0, 'protocols' => []];
        foreach ($rows as $row) {
            if (!empty($row['enable'])) {
                $stats['enabled']++;
            }
            $protocol = (string)($row['protocol'] ?? '');
            $stats['protocols'][$protocol] = ($stats['protocols'][$protocol] ?? 0) + 1;
        }
        return $this->ok([
            'accounts'  => $rows,
            'protocols' => Accounts::PROTOCOLS,
            'stats'     => $stats,
            'path'      => Accounts::path(),
        ]);
    }

    /** 新增账号（同一协议可重复） */
    public function create(Request $request): Response
    {
        if ($deny = $this->guard($request, 'protocol.write')) {
            return $deny;
        }
        $result = Accounts::upsert($this->payload($request));
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok([
            'accounts' => Accounts::publicList(),
            'saved'    => Accounts::publicRow($result['data']),
        ], $result['msg']);
    }

    /** 修改账号（id 不可改，改的是其余字段） */
    public function update(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'protocol.write')) {
            return $deny;
        }
        $id = (string)($params['id'] ?? '');
        $current = Accounts::get($id);
        if ($current === null) {
            return $this->fail('账号不存在：' . $id, -1, 404);
        }
        $payload = $this->payload($request);
        $payload['id'] = $id;
        // 未提交的字段沿用原值，避免面板只改一个开关时把 token 清空
        foreach (['protocol', 'self_id', 'api', 'token', 'forward_url', 'forward_token', 'remark', 'name', 'app_id', 'client_secret'] as $key) {
            if (!array_key_exists($key, $payload)) {
                $payload[$key] = $current[$key] ?? '';
            }
        }
        if (!array_key_exists('enable', $payload)) {
            $payload['enable'] = (bool)($current['enable'] ?? true);
        }
        $result = Accounts::upsert($payload);
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok([
            'accounts' => Accounts::publicList(),
            'saved'    => Accounts::publicRow($result['data']),
        ], $result['msg']);
    }

    /** 删除账号（至少保留一个） */
    public function destroy(Request $request, array $params): Response
    {
        if ($deny = $this->guard($request, 'protocol.write')) {
            return $deny;
        }
        $result = Accounts::remove((string)($params['id'] ?? ''));
        if (!$result['ok']) {
            return $this->fail($result['msg'], -1, 400);
        }
        return $this->ok(['accounts' => Accounts::publicList()], $result['msg']);
    }

    /** @return array<string, mixed> */
    private function payload(Request $request): array
    {
        $input = $request->params();
        $keys = ['id', 'name', 'protocol', 'self_id', 'enable', 'api', 'token', 'forward_url', 'forward_token', 'app_id', 'client_secret', 'remark'];
        $out = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $input)) {
                $out[$key] = $input[$key];
            }
        }
        return $out;
    }
}
