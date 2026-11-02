# 日志 03：协程隔离补完 + 安全修正

## 一、这一阶段的核心问题：Swoole 子协程不继承上下文

之前把 `Cache::$param / $server / $adapter` 改成走 `Swoole\Coroutine::getContext()`，
以为并发隔离已经完成。实际上这只是**第一步**：

```php
Coroutine::create(function () {
    // 这里的 getContext() 是一个全新的 ArrayObject，父协程写入的东西全都看不到
});
```

而本项目的调用链恰好是：

```
onRequest（请求协程）
  └─ Cache::set('param', ...) / Cache::set('server', ...)
       └─ RequestHandler::handle()
            └─ Coroutine::create(...)  ← 触发器在这里跑
                 └─ new Controller\Trigger\Message()  ← 读 Cache::get('param') 读到 null
```

**后果**：所有走 Trigger 的业务都会因为「请求参数无效」直接抛异常。

### 修法

给 `Utils\Cache` 增加两个方法：

```php
Cache::snapshot();   // 读取当前协程的 param/server/adapter/... + 协程私有数据
Cache::restore($snap); // 在子协程开头写回
```

调用点：

- `RequestHandler::handle()` 里的 post_type 分支
- `RequestHandler::handleRouteCallback()`
- `EventDispatcher::dispatch()`（WS/常驻事件分发）

`snapshot()` 会**连同 `raw_body` / `headers` 一起带走**，否则 QQ 官方 Webhook
验签在子协程里会读到空 body（这点已用 `.phpext/tests/snap2.php` 单独验证）。

## 二、请求级数据不能放全局缓存

`raw_body`、`headers` 之前用 `Cache::set()` 存进**全局**动态缓存。
两条并发请求（尤其 QQ 官方 Webhook 连续推送）会互相覆盖 body → 验签必然失败。

→ 新增 `Cache::setLocal()` / `Cache::getLocal()`，落在协程私有容器里；
非协程环境（CLI 单测）退化为静态数组，行为一致。

## 三、安全修正：验签不能静默降级

原来的写法：

```php
if (Config::bool('qqbot.webhook.verify', true) && function_exists('sodium_...')) {
    // sodium 缺失 → 整个条件为 false → 直接放行
}
```

即「开了验签但装不上扩展」等于**完全不验签**。现改为：

- `verify = true`（默认）时，缺 `client_secret` 或缺 `sodium` 都直接返回错误，不放行；
- 只有显式 `verify = false` 才跳过，并打 warning 日志。

同时补上了 **Milky WebHook 的鉴权**：Milky 协议端会带 `Authorization: Bearer {access_token}`，
之前完全没有校验（任何人都能伪造事件）。

## 四、WsHub 的三处修正

### 1. `HttpServer` 必须用 `Swoole\WebSocket\Server`
`Swoole\Http\Server` 即使开了 `open_websocket_protocol`，也不会派发
`open/message/close`，且没有 `push()` 方法。已改为 `Swoole\WebSocket\Server`
（它是 `Http\Server` 的子类，HTTP 能力与 `onRequest` 全部保留），
并同步改了 `Utils\Cache::$http` 的类型。

### 2. 鉴权必须放在 `onOpen`
用 `WebSocket\Server` + `open_websocket_protocol = true` 时，握手由 Swoole 自动完成，
**`onRequest` 不会被调用**。所以 `RequestHandler` 里的 token 校验根本跑不到。
→ 在 `WsHub::onOpen()` 里校验 token（支持 `Authorization: Bearer`、`X-Access-Token`、
`?access_token=`），失败时下发错误帧并 `disconnect(fd, 4001)`。
`RequestHandler` 的升级分支保留为「服务端退化为普通 HTTP Server 时的兜底」。

### 3. 反向会话计数把 Milky 端点也算进去了
`reverseCount()` / `pickReverseFd()` 遍历 `$sessions`，而 Milky WS 连接也登记在同一个数组里，
导致 API 调用可能被推到「只用于推送事件的 Milky 连接」上。
→ 加 `role` 过滤，只有 `role = 'reverse'` 的会话参与 OneBot API 下发。

## 五、兼容性细节

- `HttpAdapter` 的响应解析里有 `$json['code'] === 1` 直接访问，
  遇到 OneBot11 标准响应（只有 `retcode` 没有 `code`）会触发未定义索引。
  → 改为先取 `code ?? retcode ?? 0` 再判断。
- `api/message.php` 的 `getMsg/deleteMsg/markMsgAsRead/getForwardMsg` 参数还是 `int`，
  QQ 官方的 `message_id` 是字符串 `ROBOT1.0_xxx` → 全部放宽为 `string|float|int`。
- `BaseApp::isFriend()` 里 `$qq <= 0` 遇到 OpenID 字符串会触发 PHP 8 的字符串-数字比较，
  语义不可控 → 改为「数字才比较、空串直接 false」。
- `Trigger` 的 `$server` 属性类型从 `?RequestHandler` 放宽为 `?object`
  （常驻事件注入的是 `NullResponder`）。

## 六、当前状态

- 全项目 `php -l`：**0 失败**
- `.phpext/tests/protocol_test.php`：**全部通过**（Milky / QQ 官方 / Config）
- `.phpext/tests/qqbot_sign.php`：**全部通过**（Ed25519 签名向量）
- `.phpext/tests/local.php` / `snap.php` / `snap2.php`：协程私有存储与快照验证通过

## 七、已知限制（需要在真实运行环境验证的部分）

本机**没有 Swoole / Redis 扩展**（Termux 源不提供），因此以下内容只能靠静态检查 + 逻辑单测，
无法真正跑起来验证：

1. `Swoole\WebSocket\Server` 的握手、`onOpen/onMessage/onClose` 派发
2. 正向 WS 的 `Client->upgrade()/recv()/push()` 实际行为
3. QQ 官方 Gateway 的 Hello/Identify/Heartbeat 时序
4. Milky SSE 的流式 `recv()` 解析

上线前建议按 `docs/README-protocol.md` 第七节的排障表逐项自测。
