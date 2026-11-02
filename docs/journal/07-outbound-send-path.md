# 日志 07：发送（出站）链路的审计与修复

日期：2026-11-03

## 背景

上一轮把「接收」补齐了：OneBot11 正向/反向 WS、Milky、QQ 官方机器人的事件
都能转成标准 OneBot11 事件，插件零改动可用。但用户问了一句：

> 只适配了接收吗？发送呢？

这句话点到了要害。接收侧有适配器，发送侧其实也有（`Bridge` 从 6 轮前就存在），
**但从来没有被端到端审计过**。这一轮专门把「发出去」这条链路逐段查了一遍，
发现四处会静默出错的地方——都不报异常，只是消息发到了错误的账号、或者发了两条。

## 出站链路长什么样

```
插件  $this->api->message->sendMsg(...)
        ↓
Api\message::sendMsg()         拼 OneBot11 参数
        ↓
Api\app::post()                判断走桥还是直连
        ↓
Protocol\Core\Bridge::call()  选后端：事件适配器 or 默认后端
        ↓
Protocol\{OneBot,Milky,QqBot}::Adapter::call()   翻译成各协议
```

这条链本身是对的。问题出在「上下文」和「兜底」两处。

## 四个静默错误

### 1. 子协程丢了上下文，插件回复跑到别的账号（最严重）

`controller/Trigger/Message.php` 匹配到路由后这样执行插件：

```php
Coroutine::create(function () use ($route, $routeClass, $routeFunc) { ... });
```

Swoole 的 `Coroutine::create` **不继承父协程上下文**，而 `Cache` 把
`adapter`（当前事件来源）、`api`、`param`、`server` 都放在协程上下文里。
于是插件协程里 `Bridge::hasAdapter()` 恒为 false，`Api\app::post()`
静默回落到 `{cq.api}` 这个旧 HTTP 协议端。

后果：部署换成 Milky 或官方机器人之后，**入站事件正常处理，插件的回复却跑到
旧协议端另一个账号上**。全程无异常、无告警。

修复：进入子协程前 `Cache::snapshot()`，子协程开头 `Cache::restore()`。
`RequestHandler` 与 `EventDispatcher` 早就这么做了，三个 Trigger 漏了。

### 2. 默认后端写死 HTTP

定时任务（`TaskManager`）、WebUI 主动发消息、CLI 工具这三类出站没有入站事件，
`Context::current()` 为 null，于是永远走 `{cq.api}`。
也就是说「主动推送」永远留在旧协议上。

修复：`Bridge::fallback()` 改为按 `[protocol] default` 构造后端
（`http`/`onebot`/`milky`/`qqbot`），并且**只有该后端 enable=false 时才回落 HTTP**
——避免配置写着 milky、实际没启用，把所有发送打到不存在的地址。

### 3. WS 超时被当成「没连上」，导致重复发送

`WsHub::callApi()` 原来用 `null` 同时表达两种含义：

- 根本没有可用连接 → 可以安全回落 HTTP
- 已发出但等不到 echo（超时） → **绝不能回落**

`Adapter::call()` 拿到 `null` 一律回落 HTTP。`send_group_msg` 是非幂等的，
协议端已经发出去了，再发一次就是两条。

修复：返回值改三态 `array|false|null`。`false` = 已发出未收到响应，
Adapter 直接返回失败并提示「请勿重试以避免重复发送」，不回落。

### 4. 响应形态不一致，插件读 `->data->message_id` 拿到 null

旧 HTTP 直连用的是 `json_decode()` 默认形态（`data` 是 stdClass），
而新适配器内部用数组。`app/Plugins/Group.php:80` 这类代码写的是：

```php
'id' => $result->data->message_id,
```

在 Milky 下 `data` 是 `['message_id' => ...]`，`->message_id` 取不到，静默 null。

修复：`Bridge` 统一归一化返回体——关联数组转 stdClass，列表保持数组，
使「旧 HTTP 部署」和「新协议部署」对业务代码完全一致。

## 顺带修的业务层笔误

- `api/message.php` 两个表情接龙 URL 多了前导点，
  `actionOf()` 会把 action 解析成 `.join_group_emoji_chain`；
- `api/cqHttp.php::getClientKey()` 用了没声明的 `$domain`（PHP 8 直接报警告）。

## 两处健壮性增强

**只把协议端 URL 当 action**：`Api\app::get/post` 以前只要绑定了适配器，
就把**任何** URL 的末段当 OneBot action。于是 `sendPb()` 里对第三方
`http://oiapi.net/api/Protobuf/` 的调用会被压成 `Protobuf` 转发给 QQ 协议端。
新增 `Bridge::routes()`：仅 `{cq.api}` 前缀的调用走桥，第三方接口照常直连。

**自动补齐会话参数**：OneBot11 的 `delete_msg` 只带 `message_id`，
但 Milky 的撤回需要 `message_scene + peer_id`。`Bridge::enrich()` 从当前事件
上下文补 `group_id/user_id/message_type`，私聊撤回不会被当成群撤回。
（显式传参优先，不覆盖调用方意图。）

## 验证

新增两个测试，全部用静态 + 桩适配器，不依赖 swoole：

- `tests/outbound_test.php`（25 项）：Bridge 转发、handles() 过滤、异常兜底、
  三协议参数映射、响应形态归一化、会话参数补齐
- `tests/outbound_gaps.php`（21 项）：第三方 URL 不走桥、WS 超时三态、
  Trigger 上下文恢复、默认后端按配置切换

全量 10 个测试 PASS，jsdom E2E（登录 + 8 个视图 + 群登记 + 插件开关）全部通过。

## 发送能力现状（回答「发送做了什么」）

| 协议 | 发送实现 | 覆盖 |
| --- | --- | --- |
| OneBot11 | `WsHub::callApi` + echo 关联 | 正向 WS / 反向 WS（会话优先）/ 无连接回落 HTTP |
| Milky | `Api::call` POST `{api}/api/{name}` | 92 个 action 映射：消息、撤回、合并转发、文件、群管、戳一戳、表情回应 |
| QQ 官方 | `Adapter::request` 调 OpenAPI | 群/私聊/C2C、markdown、keyboard、富媒体（先上传再 msg_type=7）、被动回复 msg_id+msg_seq 自动分配与次数上限 |

## 遗留

- **截图`Markdown.php`、`sendPb()` 等第三方服务仍直连**（这是对的），
  但它们读 `$res->code`，与新响应的 `retcode` 不同。旧 HTTP 路径下不受影响，
  但若将来这些接口也走桥，需要一并适配。
- **QQ 官方不支持 5 个读接口**（群列表/好友列表/历史消息/全员禁言），
  这是官方限制，已在 `UNSUPPORTED` 里给出明确原因而不是笼统 -404。
- **Milky 无 message_id**，只有会话内 `message_seq`，跨会话撤回仍需业务侧提供 peer。
