# QQ 官方机器人 API v2 · PHP(Swoole) 实现规范摘要

> 依据：本地离线文档库 `/storage/emulated/0/model/qqbot-api-v2-docs/docs/`（抓取自 <https://bot.q.qq.com/wiki/develop/api-v2/>，抓取日 2026-10-27，共 192 页）。
> 本文所有结论均可在文末「复核索引」列出的 md 文件里逐条对照；凡文档未写明的点，标注为 **【文档未载】** 并给出可落地建议。
> 面向读者：用 PHP 8 + Swoole（协程 HTTP Client / 协程 WebSocket / 常驻进程）实现 QQ 官方机器人服务端。

---

## 0. 一句话速览

| 环节 | 结论 |
| --- | --- |
| 鉴权 | `POST https://api.bot.qq.com/app/getAppAccessToken`，body `{"appId","clientSecret"}`，拿 `access_token`（约 7200s），之后所有 OpenAPI 带 `Authorization: QQBot {access_token}` |
| 收事件（推荐 Webhook） | 平台用 **Ed25519** 对 `X-Signature-Timestamp + raw_body` 签名，公钥由 **AppSecret 重复填充成 32 字节 seed** 派生；握手 op=13 要回 `{plain_token, signature}` |
| 收事件（WebSocket） | `GET /gateway` 取 wss 地址 → 收 op=10 Hello(心跳毫秒) → 发 op=2 Identify(`QQBot {token}`, intents, shard) → 收 READY(session_id) → 周期发 op=1 心跳 → 断线 op=6 Resume(`seq=s`) |
| 发消息 | 群 `POST /v2/groups/{group_openid}/messages`；单聊 `POST /v2/users/{user_openid}/messages`；频道 `POST /channels/{channel_id}/messages` |
| 被动回复 | 带 `msg_id`（事件 `d.id`）或 `event_id`（事件最外层 `id`）；同一 `msg_id` 多次回复必须递增 `msg_seq` |
| 富媒体 | 先 `POST .../files`（URL / 二进制≤5MB / 分片合并）拿 `file_info` → `msg_type=7` + `media.file_info` 发送 |
| 域名 | 2026-08-10 起官方统一为 `https://api.bot.qq.com`（**本地文档全文无 `api.sgroup.qq.com` / `sandbox.api.sgroup.qq.com` 字样**，见第 6.1 节说明） |

---

## 1. 鉴权：AppID / AppSecret → AccessToken

### 1.1 取 Token（唯一入口）

| 项 | 值 |
| --- | --- |
| URL | `https://api.bot.qq.com/app/getAppAccessToken` |
| Method | `POST` |
| Content-Type | `application/json` |
| 请求体 | `appId`（string，必填）、`clientSecret`（string，必填。即管理端 AppSecret） |
| 返回体 | `access_token`（string）、`expires_in`（number，秒，目前 ≤7200；示例里是字符串 `"7200"`，PHP 端按 int 转换） |
| 失败体 | `code`（number）、`message`（string，仅供人工排查） |

> **重要**：失败时 **HTTP 仍是 200**，必须按 body 里的 `code` 判定成败，不要只看 HTTP 状态码，也不要靠 `message` 判错类型。（`dev-prepare/access-token.md`）

请求示例：

```http
POST /app/getAppAccessToken HTTP/1.1
Host: api.bot.qq.com
Content-Type: application/json
Content-Length: 76

{"appId":"102083127","clientSecret":"DG5g3B4j9X2KOErGxxxxxxxx"}
```

成功响应：

```json
{ "access_token": "AK7xxxxxxxxxxxxxxxxxxxxxxxxxxxx", "expires_in": "7200" }
```

失败响应（HTTP 200）：

```json
{ "code": 100016, "message": "invalid appid or secret" }
```

业务错误码（`dev-prepare/access-token.md`）：

| code | message | 含义 / 处置 |
| --- | --- | --- |
| 100001 | Too many requests | 取 token 频率过高，退避重试 |
| 100007 | appid invalid | AppID 无效，或机器人被封禁/已删除 |
| 100016 | invalid appid or secret | AppID 或 AppSecret 不匹配 |
| 10004 | 机器人不存在 | AppID 不存在 |

### 1.2 有效期与刷新策略（照抄官方语义）

- 默认生命周期 **7200 秒（2 小时）**；到期必须自行刷新。
- **有效期内重复获取返回同一个 token**（不会每次换新）。
- 在临近过期 **60 秒** 窗口内再次获取，会拿到**新 token**，老 token 在这 60 秒内**仍然有效**（平滑切换窗口）。
- 官方建议：服务端定时刷新。

**PHP/Swoole 落地建议（可直接实现）**

1. token 缓存在 Redis（key 例：`qqbot:{appid}:access_token`，value=token，TTL=expires_in）。
2. 进程内再加一层内存缓存 + 到期时间戳（Swoole 常驻，避免每请求打 Redis）。
3. 刷新任务用 `Swoole\Timer::tick(30000, ...)` 每 30s 检查：若 `now >= expire_at - 120` 则异步刷新（提前 2 分钟，落在官方 60s 平滑窗口之前，安全）。
4. 取 token 用协程 client，务必加**并发互斥**（`Swoole\Coroutine\Channel` 或 Redis 分布式锁 `SET NX EX`），否则重启瞬间 N 个协程同时打 `getAppAccessToken`，易触发 100001。
5. 业务侧收到 401 / `err_code` 为鉴权类错误时，**主动作废缓存并重取一次**再重试原请求（最多重试 1 次，避免风暴）。

### 1.3 调用 OpenAPI 的鉴权头

```http
Authorization: QQBot {ACCESS_TOKEN}
```

注意是 **`QQBot` + 一个空格 + token**（不是 `Bot`，也不是 `Bearer`）。
（`dev-prepare/api-call-guide.md`、`dev-prepare/access-token.md`、`dev-prepare/interface-framework/api-use.md`）

```bash
curl -X GET 'https://api.bot.qq.com/users/@me' \
  -H 'Authorization: QQBot {ACCESS_TOKEN}'
```

> 文档明确：**禁止在前端使用访问凭证**，只能服务端调用。

### 1.4 OpenAPI 响应结构（所有 /v2 与 /channels 类接口通用）

成功：直接返回业务数据（如 `{"id","timestamp"}`）。
失败：`{"err_code":40034005,"message":"回复消息msg_id已过期","trace_id":"..."}`。

- `err_code`：错误码，**成功时为 0**（文档表述："成功时为 0"）；判断失败请看 `err_code`，不要看 `message`。
- `trace_id`：链路追踪；也可从响应头 `X-Tps-trace-ID` 取。
- HTTP 状态码语义：200 成功 / 204 成功但无 body（删除类）/ 201,202 异步成功但可能带 error body（需特殊处理）/ 401 认证失败 / 404 无此 API / 405 方法不允许 / 429 限频 / 500、504 处理失败。

---

## 2. Webhook（HTTP 回调）模式

### 2.1 回调地址要求

- 必须是 **HTTPS** 回调地址。
- 允许端口：**80、443、8080、8443**（其他端口平台不会回调）。
- 在管理端 <https://q.qq.com/qqbot/#/developer/webhook-setting> 配置地址并勾选要监听的事件类型。
（`dev-prepare/event-emit/webhook.md`）

### 2.2 签名算法（Ed25519）——最容易被 PHP 实现踩坑的地方

**平台侧所用密钥的派生规则（必须逐字复刻）**：

1. `seed = AppSecret`（Bot Secret）。
2. 循环 `while (strlen(seed) < 32) seed = seed . seed;`（即重复拼接，注意是 **repeat(seed,2)** 语义：每次把自身拼一遍）。
3. 截断：`seed = substr(seed, 0, 32)`。
4. 用该 32 字节 seed 生成 Ed25519 密钥对；**公钥用于验签，私钥用于握手签名**。

Go 原文（`dev-prepare/interface-framework/sign.md`）：

```go
seed := botSecret
for len(seed) < ed25519.SeedSize {   // 32
    seed = strings.Repeat(seed, 2)
}
rand := strings.NewReader(seed[:ed25519.SeedSize])
publicKey, privateKey, err := ed25519.GenerateKey(rand)
```

**验签参数（请求头 + body）**

| 字段 | 说明 | 示例 |
| --- | --- | --- |
| `X-Signature-Ed25519` | 十六进制签名字符串（64 字节 → 128 hex 字符） | `3ecd...` |
| `X-Signature-Timestamp` | 签名时间戳（秒） | `1725442341` |
| HTTP Body | **原始字节**（不能是 re-encode 后的 JSON） | `{"op":0,"d":{},"t":"..."}` |

**签名体拼接顺序：`timestamp + raw_body`**（先时间戳、再原始 body，无分隔符）。

**验签步骤（sign.md 原文步骤，含安全检查）**

1. 取 `X-Signature-Ed25519`，为空 → 失败。
2. `hex_decode` → `sig`；解码失败 → 失败。
3. 校验 `strlen(sig) == 64` 且 `(sig[63] & 0xE0) == 0`（防止非法点/高位污染，官方示例显式做了这一步）。
4. 取 `X-Signature-Timestamp`，为空 → 失败。
5. `msg = timestamp . rawBody`。
6. `ed25519_verify(publicKey, msg, sig)`。

**PHP（sodium）等价实现**

```php
final class QqBotSign
{
    /** 由 AppSecret 派生 seed（32 字节） */
    public static function seed(string $secret): string
    {
        $seed = $secret;
        while (strlen($seed) < SODIUM_CRYPTO_SIGN_SEEDBYTES) { // 32
            $seed .= $seed;
        }
        return substr($seed, 0, SODIUM_CRYPTO_SIGN_SEEDBYTES);
    }

    /** @return array{0:string,1:string} [publicKey(32B), secretKey(64B)] */
    public static function keypair(string $secret): array
    {
        $kp = sodium_crypto_sign_seed_keypair(self::seed($secret));
        return [sodium_crypto_sign_publickey($kp), sodium_crypto_sign_secretkey($kp)];
    }

    /** 校验平台回调（Webhook 事件推送） */
    public static function verify(string $secret, string $rawBody, string $sigHex, string $timestamp): bool
    {
        if ($sigHex === '' || $timestamp === '') return false;
        $sig = @hex2bin($sigHex);
        if ($sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) return false;
        if ((ord($sig[63]) & 0xE0) !== 0) return false;          // 官方示例的同款前置校验
        [$pub] = self::keypair($secret);
        return sodium_crypto_sign_verify_detached($sig, $timestamp . $rawBody, $pub);
    }

    /** 回调地址验证握手：签名体是 event_ts + plain_token */
    public static function validationSignature(string $secret, string $eventTs, string $plainToken): string
    {
        [, $sk] = self::keypair($secret);
        return bin2hex(sodium_crypto_sign_detached($eventTs . $plainToken, $sk));
    }
}
```

**官方给的 DEMO 向量（可写成单测）**（`sign.md`）：

```text
secret    : naOC0ocQE3shWLAfffVLB1rhYPG7
seed      : naOC0ocQE3shWLAfffVLB1rhYPG7naOC
publicKey : hex = d7c362fe78aef81ff23287b493628b5db02a3c4fe30b215e4d19609b5d76673a
body      : { "op": 0,"d": {}, "t": "GATEWAY_EVENT_NAME"}
timestamp : 1725442341
期望 sig  : 865ad13a61752ca65e26bde6676459cd36cf1be609375b37bd62af366e1dc25a8dc789ba7f14e017ada3d554c671a911bfdf075ba54835b23391d509579ed002
```

握手向量（`webhook.md` 示例）：

```text
appid  : 11111111
secret : DG5g3B4j9X2KOErG
请求头 : User-Agent: QQBot-Callback    X-Bot-Appid: 11111111
请求体 : {"d":{"plain_token":"Arq0D5A61EgUu4OxUvOp","event_ts":"1725442341"},"op":13}
应答体 : {"plain_token":"Arq0D5A61EgUu4OxUvOp","signature":"87befc99c42c651b3aac0278e71ada338433ae26fcb24307bdc5ad38c1adc2d01bcfcadc0842edac85e85205028a1132afe09280305f13aa6909ffc2d652c706"}
```

**我实际复算的结果（重要）**

| 向量 | 复算结果 |
| --- | --- |
| `sign.md` 的 publicKey 派生（secret `naOC0ocQE3shWLAfffVLB1rhYPG7` → seed `naOC0ocQE3shWLAfffVLB1rhYPG7naOC`） | **完全一致**：`d7c362fe78aef81ff23287b493628b5db02a3c4fe30b215e4d19609b5d76673a` ✔ |
| `webhook.md` 的 op=13 握手签名（secret `DG5g3B4j9X2KOErG`，签名体 `event_ts + plain_token`） | **逐字节一致**：`87befc99...d652c706` ✔ —— **可直接当单测** |
| `sign.md` 的「body + timestamp → 期望 sig」 | **无法复现**。文档里写的 body（含空格）与多种去空格/换行变体都算不出 `865ad13a...579ed002`。推断该示例的 body 原文与排版后的文本不一致，**它只是格式演示，不是可复现向量** |

→ 落地结论：**用 `webhook.md` 的握手向量做 CI 断言**最可靠；签名算法本身（seed 派生 + `timestamp.body`）已被它证明正确。

**PHP 实现要点 / 常见坑**

| 坑 | 说明 / 处置 |
| --- | --- |
| body 被框架改写 | Swoole `$request->rawContent()` 取原始 body；**绝对不要** `json_decode` 后再 `json_encode` 去验签（空格、转义、\u 编码都会变） |
| Header 大小写 | Swoole 的 header key 全小写：`$request->header['x-signature-ed25519']`；做一次大小写兼容查找 |
| 签名时间戳未校验新鲜度 | 文档未要求时间窗，但强烈建议自加 ±300s 容差防重放（**属加固，非平台要求**） |
| sodium 未装 | `ext-sodium` 自 PHP 7.2 起默认随 PHP 发布；若缺失需 `pecl install libsodium`；不要自己手撸 Ed25519 |
| 公钥来源 | **没有**"从平台下载公钥"这一步：公钥是本地用 AppSecret 现算的（这是最容易误解的一点） |
| secret 泄漏 | secret 即签名私钥种子，泄漏等于可伪造回调；按密钥管理（env / 加密配置，不入库明文） |

### 2.3 回调地址验证（握手）流程

平台配置地址后会发一次验证请求（op=13）：

```http
POST /your/webhook HTTP/1.1
User-Agent: QQBot-Callback
X-Bot-Appid: 11111111
Content-Type: application/json

{"op":13,"d":{"plain_token":"Arq0D5A61EgUu4OxUvOp","event_ts":"1725442341"}}
```

机器人必须返回 **HTTP 200**，body：

```json
{"plain_token":"Arq0D5A61EgUu4OxUvOp","signature":"87befc99...c706"}
```

- `signature = hex(Ed25519_sign(privateKey, event_ts . plain_token))`（注意顺序：**event_ts 在前，plain_token 在后**；与"验签用 timestamp+body"是对称的）。
- `plain_token` 原样回显。
- 计算代码见上文 `validationSignature()`。

### 2.4 事件推送报文结构（op=0 Dispatch）

```json
{
  "id": "event_id",
  "op": 0,
  "d": { },
  "s": 42,
  "t": "GATEWAY_EVENT_NAME"
}
```

| 字段 | 含义 |
| --- | --- |
| `id` | 事件 id（**被动回复可用 event_id**，见第 4.1 节） |
| `op` | opcode，见第 2.5 节 |
| `s` | 下行消息序列号（WebSocket 心跳/Resume 用；Webhook 也带） |
| `t` | 事件类型（op=0 时有效） |
| `d` | 事件内容（结构随 `t` 变化） |

（`dev-prepare/event-emit/payload.md`、`dev-prepare/interface-framework/reference.md`）

### 2.5 opcode 全表（Webhook 与 WebSocket 共用一张表）

| op | 名称 | 接入方式 | 方向 | 说明 |
| --- | --- | --- | --- | --- |
| 0 | Dispatch | webhook + websocket | 收 | 服务端推送事件 |
| 1 | Heartbeat | websocket | 发/收 | 心跳 |
| 2 | Identify | websocket | 发 | 鉴权 |
| 6 | Resume | websocket | 发 | 恢复连接 |
| 7 | Reconnect | websocket | 收 | 服务端要求重连 |
| 9 | Invalid Session | websocket | 收 | identify/resume 参数有错 |
| 10 | Hello | websocket | 收 | 建连后第一条，含 `heartbeat_interval` |
| 11 | Heartbeat ACK | websocket | 收/回 | 心跳成功回执 |
| 12 | HTTP Callback ACK | webhook | 回 | **仅 HTTP 回调模式的回包**，表示机器人已收到数据 |
| 13 | 回调地址验证 | webhook | 收 | 平台验证回调地址 |

（`dev-prepare/event-emit/payload.md` 表格含"接入方式"列；`dev-prepare/interface-framework/opcode.md` 为精简版）
**注意：本地文档里没有 op=3/4/5/8（Discord 的那套在这里不存在）。**

### 2.6 收到事件后要回应什么

文档层面的硬事实只有两条：

1. 平台把事件 `POST` 到你的回调地址，你的服务端**需要返回 HTTP 200**（否则平台会判定投递失败）。
2. opcode **12 = HTTP Callback ACK**，"仅用于 http 回调模式的回包，代表机器人收到了平台推送的数据"，方向是客户端 `Reply`。

**【文档未载】**：文档没有给出 op=12 回包的**具体 body 形状**，也没写"必须是 200 还是 204"。
**落地建议（业界通行、与官方 SDK 行为一致）**：

```http
HTTP/1.1 200 OK
Content-Type: application/json

{"op":12,"d":{}}
```

实现上建议：
- **先快速 ACK**（<3s，最好 <500ms），把事件丢进 Redis/内存队列再异步处理；平台侧有超时重投，同一事件会重复到达，必须**幂等**（用 `d.id` 或 `t+id` 去重，SETNX + TTL 24h）。
- 若你的框架只能返回空 body，返回 `200` + 空 JSON `{}` 实测亦可被接受（多数 SDK 如此）；稳妥起见带上 `{"op":12,"d":{}}`。
- **不要**在 webhook 响应里返回业务数据；回复用户消息要走 OpenAPI（第 4 章）。
- 验签失败一律返回 `401`，不落库、不处理。

### 2.7 回调事件类型总表（含 Intent 位与触发条件）

> Intent 位是 **WebSocket Identify 用**；Webhook 模式在管理端勾选事件，不需要传 intents。下表同时给出两套信息，便于同一套业务代码双模运行。

| 事件 `t` | Intent | 触发时机 | 关键字段（`d`） | 文档 |
| --- | --- | --- | --- | --- |
| `GROUP_AT_MESSAGE_CREATE` | `GROUP_AND_C2C_EVENT` (1<<25) | 群里 @机器人（content 已去掉@前缀） | `id, author{member_openid,member_role,username,bot}, content, group_openid, timestamp, message_type, message_scene{source,ext}, attachments[], mentions[], ark_data, msg_elements[]` | `autogen/event/group_at_message_create.md` |
| `GROUP_MESSAGE_CREATE` | 1<<25 | 开启"接收所有消息"后的**群内全量消息** | 同上；content 中 @某人 → `<@member_openid>`，@全体 → `<@all>` | `autogen/event/group_message_create.md` |
| `C2C_MESSAGE_CREATE` | 1<<25 | 用户单聊机器人 | `id, author{user_openid}, content, timestamp, message_type, message_scene, attachments, ark_data, msg_elements` | `autogen/event/c2c_message_create.md` |
| `INTERACTION_CREATE` | `INTERACTION` (1<<26) | 按钮点击/快捷菜单/消息反馈/清空会话/进出故事集/切模型/授权 | `id, type, scene, chat_type, timestamp, guild_id?, channel_id?, user_openid?, group_openid?, group_member_openid?, data{type,resolved{button_data,button_id,user_id,feature_id,message_id,feedback_opt,checked,action,message_scene,authorize_data}}, version, application_id` | `autogen/event/interaction_create.md` |
| `GROUP_ADD_ROBOT` | 1<<25 | 机器人被拉进群 | `timestamp, group_openid, op_member_openid` | `autogen/event/group_add_robot.md` |
| `GROUP_DEL_ROBOT` | 1<<25 | 机器人被移出群 | `timestamp, group_openid, op_member_openid` | `autogen/event/group_del_robot.md` |
| `GROUP_MSG_RECEIVE` / `GROUP_MSG_REJECT` | 1<<25 | 群管理员在资料页开/关通知 | `timestamp, group_openid, op_member_openid` | `autogen/event/group_msg_receive.md` / `group_msg_reject.md` |
| `GROUP_MEMBER_ADD` | `GROUP_MEMBER_EVENT` (1<<24) | 新成员入群 | `timestamp, group_openid, member_openid` | `autogen/event/group_member_add.md` |
| `GROUP_MEMBER_REMOVE` | 1<<24 | 成员退群/被移出 | `timestamp, group_openid, member_openid, user_openid` | `autogen/event/group_member_remove.md` |
| `GROUP_JOIN_REQUEST` | 1<<24 | 用户申请加群（**仅机器人是管理员时**） | `group_openid, join_request_id, risk_tips, union_openid, member_openid, username, apply_at, apply_source(self_apply/invited), invited_by, bot, verify_info{method,verify_message,review_qa_list}, auto_approved{strategy_id}` | `autogen/event/group_join_request.md` |
| `FRIEND_ADD` | 1<<25 | 用户添加机器人好友 | `timestamp, openid, scene, scene_param, author{union_openid}, short_code` | `autogen/event/friend_add.md` |
| `FRIEND_DEL` | 1<<25 | 删除好友 | `timestamp, openid, author{union_openid}` | `autogen/event/friend_del.md` |
| `C2C_MSG_RECEIVE` / `C2C_MSG_REJECT` | 1<<25 | 用户开/关主动消息推送 | `timestamp, openid` | `autogen/event/c2c_msg_receive.md` / `c2c_msg_reject.md` |
| `SUBSCRIBE_MESSAGE_STATUS` | 1<<25 | 订阅消息模板授权状态变更 | `group_openid?, openid?, result[{template_id,custom_template_id,op(1允许/2拒绝),subscribe_id,subscribe_ts,update_ts}]` | `autogen/event/subscribe_message_status.md` |
| `AT_MESSAGE_CREATE` | `PUBLIC_GUILD_MESSAGES` (1<<30) | 频道内 @机器人/回复机器人（公域） | Message 对象（`author, channel_id, content, guild_id, id, member, timestamp, seq`） | `server-inter/channel/message/event.md` |
| `MESSAGE_CREATE` | 1<<9（私域）或 1<<30 | 频道内全部消息 | 同上 | 同上 |
| `DIRECT_MESSAGE_CREATE` | `DIRECT_MESSAGE` (1<<12) | 频道私信（**不支持沙箱**） | 同上 | 同上 |
| `MESSAGE_AUDIT_PASS` / `MESSAGE_AUDIT_REJECT` | `MESSAGE_AUDIT` (1<<27) | 主动消息审核结果 | `audit_id, audit_time, channel_id, create_time, guild_id, message_id` | 同上 |
| `MESSAGE_REACTION_ADD` / `REMOVE` | `GUILD_MESSAGE_REACTIONS` (1<<10) | 表情表态 | `user_id, emoji{id,type}, channel_id, guild_id, target{id,type}` | `server-inter/message/trans/emoji.md` |
| `GUILD_CREATE/UPDATE/DELETE`、`CHANNEL_CREATE/UPDATE/DELETE` | `GUILDS` (1<<0) | 频道/子频道生命周期 | Guild / Channel 对象 | `autogen/event/guild_*.md`、`channel_*.md` |
| `GUILD_MEMBER_ADD/UPDATE/REMOVE` | `GUILD_MEMBERS` (1<<1) | 频道成员变更 | Member 对象 | `server-inter/channel/role/guild_member.md` |

**事件体字段详解（群@消息 `GROUP_AT_MESSAGE_CREATE`，最常用）**

```json
{
  "id": "ROBOT1.0_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "author": {
    "id": "A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4",
    "member_openid": "A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4",
    "member_role": "member",
    "username": "小明",
    "bot": false
  },
  "content": " /今日天气 ",
  "group_openid": "B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5",
  "message_type": 0,
  "timestamp": "2026-07-21T10:00:00+08:00",
  "message_scene": {
    "source": "default",
    "ext": ["msg_idx=REFIDX_xxxxxxxxxxxxxxx==", "auth_token=xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"]
  }
}
```

- `author.member_role`：`member` / `admin` / `owner`。
- `message_scene.ext`：`key=value` 字符串数组，含 `msg_idx`（消息索引，**引用回复要用它**）、`ref_msg_idx`（被引用消息索引）、`auth_token`。
- `message_type`：**接收侧**取值 `0=纯文本`、`3=结构化卡片(ark_data)`、`101=并行消息`、`102=聊天记录`、`103=引用消息(msg_elements)`。（注意与**发送侧** `msg_type` 不是一套枚举，见第 5.1 节）
- `attachments[].content_type`：`voice`（语音，另有 `voice_wav_url` 与 `asr_refer_text`）、`image/jpeg`、`image/png`、`image/gif`、`video/mp4`、`file`（群文件）。
- `mentions[]`：被 @ 的用户列表（**不含机器人自身**）。

**互动事件（按钮回调）示例**

```json
{
  "application_id": "101984245",
  "chat_type": 1,
  "data": { "resolved": { "button_data": "eyJjb21tYW5kIjogInNhbXBsZSJ9" }, "type": 11 },
  "group_member_openid": "A1B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4",
  "group_openid": "B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5",
  "id": "06915133-7aef-46ed-94f7-c50939e285ae",
  "scene": "group",
  "timestamp": "2026-07-20T21:53:54+08:00",
  "type": 11,
  "version": 1
}
```

- `type`：11 消息按钮 / 12 单聊快捷菜单 / 13 消息反馈 / 14 清空会话 / 15 进出故事集 / 16 切换模型 / 18 用户授权 / 19 群授权 / 20 群授权状态变更。
- **只有 type=11、12 需要回应**：调用 `PUT /interactions/{interaction_id}` 且 **id 不带 `INTERACTION_CREATE:` 前缀**，否则客户端一直 loading。其他类型无需回应（调了也不报错）。
- 回应超时：指令回调类场景 **3 秒**；同一 `interaction_id` 只能回应一次。
- 回应 body：`{"code":0}`，code 取值 0 成功 / 1 操作失败 / 2 操作频繁 / 3 重复操作 / 4 没有权限 / 5 仅管理员操作。

```http
PUT /interactions/a1b2c3d4-e5f6-7890-abcd-ef1234567890 HTTP/1.1
Authorization: QQBot {ACCESS_TOKEN}
Content-Type: application/json

{"code":0}
```

### 2.8 Swoole Webhook 服务端骨架

```php
use Swoole\Http\Request;
use Swoole\Http\Response;

$http->on('request', function (Request $req, Response $resp) use ($signer, $queue) {
    $path = $req->server['request_uri'] ?? '';
    if ($path !== '/qqbot/webhook') { $resp->status(404); $resp->end(); return; }

    $raw   = $req->rawContent();                       // 必须用原始字节
    $sig   = $req->header['x-signature-ed25519']   ?? '';
    $ts    = $req->header['x-signature-timestamp'] ?? '';

    $payload = json_decode($raw, true);
    if (!is_array($payload)) { $resp->status(400); $resp->end(); return; }

    // 1) op=13 回调地址验证：回显 plain_token + 签名
    if (($payload['op'] ?? null) === 13) {
        $d = $payload['d'] ?? [];
        $resp->header('Content-Type', 'application/json');
        $resp->end(json_encode([
            'plain_token' => $d['plain_token'] ?? '',
            'signature'   => $signer->validationSignature($d['event_ts'] ?? '', $d['plain_token'] ?? ''),
        ], JSON_UNESCAPED_UNICODE));
        return;
    }

    // 2) 普通事件：先验签
    if (!$signer->verify($raw, $sig, $ts)) { $resp->status(401); $resp->end(); return; }

    // 3) 幂等 + 异步：先 ACK 再处理
    $eid = $payload['id'] ?? '';
    if ($eid !== '' && !$queue->seen("qqbot:evt:{$eid}")) {   // SETNX EX 86400
        $queue->push($payload);
    }

    $resp->header('Content-Type', 'application/json');
    $resp->end('{"op":12,"d":{}}');
});
```

> Swoole 注意：`$req->header` 的 key 一律小写；`rawContent()` 只在非 `application/x-www-form-urlencoded` 时可靠，务必让平台以 JSON 推送（默认就是 JSON）。

---
## 3. WebSocket（Gateway）模式

### 3.1 取网关地址

| 接口 | 说明 | 频控 | 返回 |
| --- | --- | --- | --- |
| `GET /gateway` | 通用 WSS 接入点 | **2 QPM / 10 QPM burst** | `{"url":"wss://api.bot.qq.com/websocket/"}` |
| `GET /gateway/bot` | 带分片信息的接入点 | 同族 | `url`、`shards`、`session_start_limit` |

```http
GET /gateway HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
```

```json
{ "url": "wss://api.bot.qq.com/websocket/" }
```

```http
GET /gateway/bot HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
```

```json
{
  "url": "wss://api.bot.qq.com/websocket/",
  "shards": 9,
  "session_start_limit": {
    "total": 1000,
    "remaining": 999,
    "reset_after": 14400000,
    "max_concurrency": 1
  }
}
```

- `session_start_limit.total`：每 24 小时可创建 Session 数
- `remaining`：当前还可创建的 Session 数（**每机器人连接数不能超过它**）
- `reset_after`：重置计数剩余时间（**毫秒**）
- `max_concurrency`：**每 5 秒**可创建的 Session 数（建立连接要按此节流）

（`openapi/wss/url_get.md`、`openapi/wss/shard_url_get.md`、`dev-prepare/event-emit/websocket.md`）

### 3.2 连接 query 参数（v / encoding / compress）

**【文档未载，重要】**：本地这份 API v2 官方文档**通篇没有**提到 WebSocket URL 的 `?v=1&encoding=json&compress=...` 之类 query 参数；`/gateway` 返回的就是裸地址 `wss://api.bot.qq.com/websocket/`，文档示例也是**直接连**。

落地建议：

1. **按文档照做**：拿到 `url` 原样连接，不加任何 query 参数；如需 `v`/`encoding` 之类，属于社区 SDK 兼容旧版 QQ 频道 API 的写法，**以实网 A/B 验证为准**，不要盲抄。
2. 若你的实现想显式声明编码，加上后平台若不认通常直接 400/关闭连接，可用 `4002 无效 payload` 之类的错误码快速判定。
3. **JSON 文本帧**：文档所有示例 payload 都是明文 JSON，帧格式按 RFC6455 文本帧收发即可。

### 3.3 压缩 / zlib / 是否需要 inflate

**【文档未载】**：本地文档没有任何 zlib / inflate / 压缩相关的描述，网关上下行示例都是可读 JSON。

落地建议：

- **默认不开压缩**，按纯文本 JSON 解析；不要凭空加 `compress` 参数。
- 防御性写法：收到帧后先看首字节

```php
// Swoole\WebSocket\Frame: $frame->data, $frame->opcode (1=TEXT, 2=BINARY)
$raw = $frame->data;
$json = json_decode($raw, true);
if ($json === null) {
    // 兜底：某些网关实现会用 zlib 压缩后再 base64，或直接二进制 deflate
    $try = @gzuncompress($raw);          // zlib (deflate, RFC1950)
    if ($try === false) $try = @gzinflate($raw);   // raw deflate (RFC1951)
    if ($try !== false) $json = json_decode($try, true);
}
if (!is_array($json)) { /* 记录日志并丢弃该帧，不要直接断连 */ }
```

- 结论：**正常情况下不需要 inflate**；上面的兜底只是防止上线环境与文档不一致时整条连接被误判为脏数据。

### 3.4 建连 → 鉴权 → Ready

**Step 1：收到 op=10 Hello**（建连后网关下发的第一条消息，含心跳周期，单位 **毫秒**）

```json
{ "op": 10, "d": { "heartbeat_interval": 45000 } }
```

**Step 2：发 op=2 Identify**

```json
{
  "op": 2,
  "d": {
    "token": "QQBot {AccessToken}",
    "intents": 513,
    "shard": [0, 1],
    "properties": {
      "$os": "linux",
      "$browser": "my_library",
      "$device": "my_library"
    }
  }
}
```

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `token` | 是 | 格式 **`QQBot {AccessToken}`**（注意：老版 `interface-framework/reference.md` 里写的是 `Bot {appid}.{app_token}`，那是**已废弃的旧鉴权**；以 `event-emit/websocket.md` 的 `QQBot {AccessToken}` 为准，且 `getting-started.md` 明说 Token 鉴权方式已废弃） |
| `intents` | 是 | 事件订阅位掩码，见 3.6 |
| `shard` | 是 | `[当前片号, 总片数]`；不切片用 **`[0,1]`** |
| `properties` | 否 | 目前无实际作用，可留空或随便填 |

**Step 3：收到 READY**（鉴权成功的标志，拿到 `session_id`）

```json
{
  "op": 0,
  "s": 1,
  "t": "READY",
  "d": {
    "version": 1,
    "session_id": "082ee18c-0be3-491b-9d8b-fbd95c51673a",
    "user": { "id": "6158788878435714165", "username": "群pro测试机器人", "bot": true },
    "shard": [0, 0]
  }
}
```

**Ready 字段说明**

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `d.version` | int | 协议版本（当前 1） |
| `d.session_id` | string | **会话 ID，Resume 必用**，务必持久化（Redis） |
| `d.user.id` | string | 机器人 ID |
| `d.user.username` | string | 机器人昵称 |
| `d.user.bot` | bool | 恒为 true |
| `d.shard` | int[2] | 实际生效的分片（示例里是 `[0,0]`，与请求的 `[0,1]` 不同，**实现时不要拿它反推**） |
| `s` | int | 本条消息的序列号（=1），要记下来给心跳用 |

### 3.5 心跳（Heartbeat）

- 周期 = Hello 里的 `heartbeat_interval`（毫秒，示例 45000 = 45s）。
- 报文：`{"op":1,"d":<最新收到的 s>}`；**首次连接（还没收到任何 s）时 d 传 `null`**。

```json
{ "op": 1, "d": 251 }
```

```json
{ "op": 1, "d": null }
```

- 成功后收到 `{"op":11}`（Heartbeat ACK）。
- 实现要点：
  - 用 `Swoole\Timer::tick($interval * 0.9, ...)`（略早于周期，避免抖动被判超时）。
  - `$seq` 用**原子变量/协程安全的属性**保存，收到每条 op=0 就更新。
  - **连续 2~3 个周期没收到 op=11** 视为连接已死 → 主动 `close` 并走 Resume 流程（文档未规定重试次数，属实现策略）。

### 3.6 事件订阅 Intents（位掩码）

```text
GUILDS                       (1 << 0)   频道/子频道生命周期（基础权限，默认可用）
GUILD_MEMBERS                (1 << 1)   频道成员变更（基础权限，默认可用）
GUILD_MESSAGES               (1 << 9)   频道全部消息（仅私域）
GUILD_MESSAGE_REACTIONS      (1 << 10)  表情表态
DIRECT_MESSAGE               (1 << 12)  频道私信
GROUP_AND_C2C_EVENT          (1 << 25)  群@消息 / 单聊消息 / 好友 / 群增删机器人 / 主动消息开关
INTERACTION                  (1 << 26)  按钮与快捷菜单互动
MESSAGE_AUDIT                (1 << 27)  消息审核结果
FORUMS_EVENT                 (1 << 28)  论坛（仅私域）
AUDIO_ACTION                 (1 << 29)  音频/上麦下麦
PUBLIC_GUILD_MESSAGES        (1 << 30)  频道 @机器人消息（公域，基础权限，默认可用）
GROUP_MEMBER_EVENT           (1 << 24)  群成员加入/退出/入群申请（见事件页标注）
```

> 说明：`dev-prepare/event-emit/payload.md` 的 Intents 清单里**没有列 `(1<<24)`**，但 `autogen/event/group_member_add.md`、`group_member_remove.md`、`group_join_request.md` 三个事件页明确写了 `GROUP_MEMBER_EVENT (1<<24)`。以事件页为准，`1<<24` 需要**单独申请权限**。

**典型组合（PHP 常量）**

```php
final class Intents
{
    public const GUILDS                = 1 << 0;
    public const GUILD_MEMBERS         = 1 << 1;
    public const GUILD_MESSAGES        = 1 << 9;
    public const GUILD_MESSAGE_REACTIONS = 1 << 10;
    public const DIRECT_MESSAGE        = 1 << 12;
    public const GROUP_MEMBER_EVENT    = 1 << 24;
    public const GROUP_AND_C2C_EVENT   = 1 << 25;
    public const INTERACTION           = 1 << 26;
    public const MESSAGE_AUDIT         = 1 << 27;
    public const FORUMS_EVENT          = 1 << 28;
    public const AUDIO_ACTION          = 1 << 29;
    public const PUBLIC_GUILD_MESSAGES = 1 << 30;

    /** 纯 QQ 群 + 单聊机器人（绝大多数群机器人只需要这个） */
    public const QQ_GROUP_ONLY = self::GROUP_AND_C2C_EVENT | self::GROUP_MEMBER_EVENT;
    /** 再带按钮互动 */
    public const QQ_GROUP_WITH_INTERACTION = self::QQ_GROUP_ONLY | self::INTERACTION;
    /** 频道（公域）机器人 */
    public const GUILD_PUBLIC = self::GUILDS | self::PUBLIC_GUILD_MESSAGES | self::GUILD_MEMBERS;
}
```

- 示例 `513 = 1<<9 | 1<<0`（GUILD_MESSAGES + GUILDS）。
- **权限规则**：`GUILDS`、`PUBLIC_GUILD_MESSAGES`、`GUILD_MEMBERS` 是**基础事件，默认可订**；其他都是特殊事件，**需申请**。鉴权时带了无权限的 intents → **直接报错并关闭连接**（错误码 4014）；权限被取消后当前连接不报错但收不到事件，**重连才报错**。
  → 实现建议：intents 做成**配置项**，不要硬编码；启动时若收到 4013/4014 就降级到最小集合重试，并告警。

### 3.7 事件推送 payload（与 Webhook 完全同构）

```json
{
  "id": "event_id",
  "op": 0,
  "s": 42,
  "t": "GROUP_AT_MESSAGE_CREATE",
  "d": { "...": "同 Webhook 的 d" }
}
```

- `op=0` 才看 `t`/`d`；`s` 必须记录（心跳与 Resume 都用它）。
- `t` 取值见第 2.7 节总表；`READY` / `RESUMED` 是网关自身的 t，不是业务事件。

### 3.8 Resume（断线续传）

断线重连后**不要**发 Identify，直接发 op=6：

```json
{
  "op": 6,
  "d": {
    "token": "QQBot {AccessToken}",
    "session_id": "082ee18c-0be3-491b-9d8b-fbd95c51673a",
    "seq": 1337
  }
}
```

- `seq` = 你**已处理完**的最后一条事件的 `s`（文档："推荐开发者在处理过事件之后记录下 s"）。传太大会丢事件，传太小会重复——**重复要靠 msg_id/msg_seq 幂等**。
- 恢复成功后网关先**补发遗漏事件**，全部补完再下发 `RESUMED`：

```json
{ "op": 0, "s": 2002, "t": "RESUMED", "d": "" }
```

- 补发期间**照常更新 s**，否则下一次 Resume 会重放。
- 若收到 `op=9 Invalid Session` 或错误码 4006/4007（session/seq 失效）→ 清掉 session，改发 **Identify** 重新建立会话。

### 3.9 分片（Sharding）

- 请求里 `shard = [id, num]`，`num` 取 `/gateway/bot` 返回的 `shards` 建议值。
- **分片规则（按频道 ID 哈希）**：

```text
shard_id = (guild_id >> 22) % num_shards
```

  → `guild_id` 是**数字**（PHP 里注意 64 位整型；`>>22` 后再取模）。同一个频道的事件固定落在同一片。
- 完整接收需要建立 `num` 条连接（`[0,num]`…`[num-1,num]`）。
- **QQ 群/单聊场景**（`GROUP_AND_C2C_EVENT`）文档未给分片规则 → 实践上直接 `[0,1]` 单片即可。
- 建立多条连接时要遵守 `session_start_limit.max_concurrency`（每 5 秒可建数）。

### 3.10 WebSocket 错误码与处置策略

| code | 含义 | 可 RESUME | 可 IDENTIFY |
| --- | --- | --- | --- |
| 4001 | 无效的 opcode | 否 | 否 |
| 4002 | 无效的 payload | 否 | 否 |
| 4006 | 无效的 session id，无法 resume | 否 | **是** |
| 4007 | seq 错误 | 否 | **是** |
| 4008 | 发送 payload 过快（需遵守连接后返回的频控） | **是** | **是** |
| 4009 | 连接过期 | **是** | **是** |
| 4010 | 无效的 shard | 否 | 否 |
| 4011 | 连接需处理的 guild 过多，请合理分片 | 否 | 否 |
| 4012 | 无效的 version | 否 | 否 |
| 4013 | 无效的 intent | 否 | 否 |
| 4014 | intent 无权限 | 否 | 否 |
| 4900~4913 | 内部错误，请重连 | 否 | **是** |
| 4914 | 机器人已下架，只允许连沙箱环境 | 否 | 否 |
| 4915 | 机器人已封禁 | 否 | 否 |

官方给出的简单处理逻辑（`dev-prepare/error-trace/websocket.md`）：

- **4009 → 重新 Resume**
- **4914 / 4915 → 不要连了，联系官方**
- **其他 → 重新 Identify**

**建议的重连状态机**

```text
CONNECTING ──op10 Hello──> IDENTIFYING ──READY──> READY(运行中)
                                  │                  │
                                  │            心跳超时/close
                                  ▼                  ▼
                             (失败重试)          RESUMING ──RESUMED──> READY
                                                  │
                                          4006/4007/op9 ──> IDENTIFYING
```

重连退避建议（文档未规定，属实现策略）：**1s → 2s → 4s → 8s → 16s → 30s 封顶**，加 **±20% 抖动**；同一 bot 连续 5 次失败则暂停 5 分钟后告警。

### 3.11 Swoole 协程 WebSocket 客户端骨架

```php
use Swoole\Coroutine;
use Swoole\Coroutine\Http\Client;
use Swoole\Timer;

final class QqGatewayClient
{
    private Client $ws;
    private int $seq = 0;
    private ?string $sessionId = null;
    private ?int $heartbeatTimer = null;
    private int $ackedAt = 0;

    public function __construct(
        private string $wssUrl,
        private int $intents,
        private array $shard,          // [0,1]
        private QqTokenProvider $token,
        private QqEventBus $bus,       // 投递事件到队列
        private \Redis $redis,
    ) {}

    public function run(): void
    {
        $this->sessionId = $this->redis->get('qqbot:gw:session') ?: null;
        $this->seq       = (int)($this->redis->get('qqbot:gw:seq') ?: 0);

        while (true) {
            try {
                $this->connectAndServe();
            } catch (\Throwable $e) {
                $this->cleanup();
                Coroutine::sleep($this->backoff());
            }
        }
    }

    private function connectAndServe(): void
    {
        $u = parse_url($this->wssUrl);
        $cli = new Client($u['host'], ($u['scheme'] === 'wss' ? 443 : 80), true);
        $cli->set(['timeout' => -1, 'websocket_mask' => true]);
        if (!$cli->upgrade($u['path'] ?? '/')) {
            throw new \RuntimeException('ws upgrade failed: ' . $cli->errCode);
        }
        $this->ws = $cli;

        while (true) {
            $frame = $cli->recv(60);                 // 协程挂起，不阻塞
            if ($frame === false) {
                if ($cli->errCode === SWOOLE_ERROR_CO_CLOSED) throw new \RuntimeException('closed');
                continue;
            }
            if (!isset($frame->data) || $frame->data === '') continue;

            $p = json_decode($frame->data, true);
            if (!is_array($p)) continue;
            $this->dispatch($p);
        }
    }

    private function dispatch(array $p): void
    {
        switch ($p['op'] ?? -1) {
            case 10: // Hello
                $interval = (int)($p['d']['heartbeat_interval'] ?? 45000);
                $this->identifyOrResume();
                $this->ackedAt = time();
                $this->heartbeatTimer = Timer::tick((int)($interval * 0.9), function () {
                    if (time() - $this->ackedAt > 150) {   // 3 个周期没 ACK
                        $this->ws->close();
                        return;
                    }
                    $this->ws->push(json_encode(['op' => 1, 'd' => $this->seq ?: null]));
                });
                break;

            case 11: // Heartbeat ACK
                $this->ackedAt = time();
                break;

            case 7:  // Reconnect
                $this->ws->close();
                break;

            case 9:  // Invalid Session
                $this->sessionId = null;
                $this->redis->del('qqbot:gw:session');
                $this->ws->close();
                break;

            case 0:  // Dispatch
                if (isset($p['s'])) {
                    $this->seq = (int)$p['s'];
                    $this->redis->set('qqbot:gw:seq', (string)$this->seq);
                }
                $t = $p['t'] ?? '';
                if ($t === 'READY') {
                    $this->sessionId = $p['d']['session_id'] ?? null;
                    if ($this->sessionId) $this->redis->set('qqbot:gw:session', $this->sessionId);
                    return;
                }
                if ($t === 'RESUMED') return;
                $this->bus->push($p);        // 业务事件入队，幂等由消费者做
                break;
        }
    }

    private function identifyOrResume(): void
    {
        if ($this->sessionId !== null) {
            $this->ws->push(json_encode([
                'op' => 6,
                'd'  => [
                    'token'      => 'QQBot ' . $this->token->get(),
                    'session_id' => $this->sessionId,
                    'seq'        => $this->seq,
                ],
            ], JSON_UNESCAPED_UNICODE));
            return;
        }
        $this->ws->push(json_encode([
            'op' => 2,
            'd'  => [
                'token'      => 'QQBot ' . $this->token->get(),
                'intents'    => $this->intents,
                'shard'      => $this->shard,
                'properties' => ['$os' => 'linux', '$browser' => 'hyper', '$device' => 'hyper'],
            ],
        ], JSON_UNESCAPED_UNICODE));
    }

    private function cleanup(): void
    {
        if ($this->heartbeatTimer) { Timer::clear($this->heartbeatTimer); $this->heartbeatTimer = null; }
        if (isset($this->ws)) { @$this->ws->close(); }
    }

    private function backoff(): float
    {
        static $n = 0;
        $n = min($n + 1, 5);
        $base = min(30, 1 << ($n - 1));            // 1,2,4,8,16,30
        return $base * (0.8 + mt_rand(0, 40) / 100); // ±20% 抖动
    }
}
```

> Swoole 要点：网关是**长连接 + 低频**，用 `Coroutine\Http\Client` 即可；心跳用 `Timer::tick`（注意 tick 回调里做 IO 要再开协程或直接 `push`（Swoole 的 `push` 在协程内可用）；若在非协程定时器里调用，用 `Coroutine::create` 包裹。
> 多分片就是**多开这个 client**，每片一个 `$seq` / `$sessionId`，别共用。

---
## 4. 发送消息

### 4.1 三端 endpoint 总表

| 场景 | Endpoint | 频控（文档） | body 主字段 |
| --- | --- | --- | --- |
| QQ 单聊 | `POST /v2/users/{user_openid}/messages` | 100 QPS（含主动/被动全部） | `msg_type, content, markdown, keyboard, msg_id, event_id, msg_seq, media, message_reference, is_wakeup, input_notify` |
| QQ 群聊 | `POST /v2/groups/{group_openid}/messages` | 100 QPS | 同上（**无 `is_wakeup` / `input_notify`，群消息不支持流式**） |
| 频道子频道 | `POST /channels/{channel_id}/messages` | 单子频道 5 条/秒 | `content, embed, ark, message_reference, image, msg_id, event_id, markdown`（+form-data 的 `file_image`） |
| 频道私信 | `POST /dms/{guild_id}/messages` | 私信：每机器人每天对单用户 2 条主动、累计 200 条主动；被动不限条数 | 与发送子频道消息**一致** |
| 单聊流式 | `POST /v2/users/{user_openid}/stream_messages` | 50 QPS | `input_mode, input_state, index, content_type, content_raw, msg_id/event_id, stream_msg_id, msg_seq, is_wakeup` |

所有请求统一：

```http
POST /v2/groups/B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5/messages HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
Content-Type: application/json; charset=utf-8
X-Union-Appid: {APPID}          # 可选，多 appid 共用网关时用于区分；单机器人可省
```

> 文档示例里 `Content-Type` 写的是 `application/json; charset=utf-8`；频道接口额外支持 `multipart/form-data`（对象/数组字段需序列化为 JSON 字符串）。

### 4.2 群聊 / 单聊请求体全字段

| 字段 | 类型 | 必填 | 说明 |
| --- | --- | --- | --- |
| `msg_type` | int | 否 | **发送侧枚举**：`0` 纯文本(`content`) / `2` Markdown(`markdown`) / `6` 输入中状态(`input_notify`，仅单聊) / `7` 富媒体(`media`) |
| `content` | string | 否 | 文本内容；**传了 `markdown` 后此字段必须为空** |
| `markdown` | object | 否 | `{content, template_id?, custom_template_id?, force_verify_image_resource?}`；传了它则 `content`/`ark` 必须为空 |
| `keyboard` | object | 否 | 内嵌键盘：短形式 `{id}`（平台模板），长形式 `{content:{rows:[...]}}` |
| `msg_id` | string | 否 | **被动回复**的消息 ID，来自事件 `d.id`；群 5 分钟 / 单聊 60 分钟内有效 |
| `event_id` | string | 否 | **被动回复**的事件 ID，来自事件**最外层** `id`；与 `msg_id` 二选一 |
| `msg_seq` | int | 否 | 回复序号，与 `msg_id` 联合去重；不填默认 **1**；**相同 `msg_id+msg_seq` 重复发送会失败** |
| `media` | object | 否 | `{"file_info": "..."}`，`msg_type=7` 时必填，file_info 来自 files 上传接口 |
| `message_reference` | object | 否 | `{"message_id":"..."}` 引用回复；被引用 id 见下方说明 |
| `is_wakeup` | bool | 否 | **仅单聊**：互动召回消息，与 `msg_id`/`event_id` **互斥** |
| `input_notify` | object | 否 | **仅单聊**：`{"input_type":1,"input_second":≤60}`，`msg_type=6` 时用 |

**`event_id` 支持的事件白名单（文档原文）**

- 单聊：`INTERACTION_CREATE`、`C2C_MSG_RECEIVE`、`FRIEND_ADD`
- 群聊：`INTERACTION_CREATE`、`GROUP_ADD_ROBOT`、`GROUP_MSG_RECEIVE`

> 注意：**普通消息事件（`GROUP_AT_MESSAGE_CREATE` / `C2C_MESSAGE_CREATE`）只能用 `msg_id` 回复，不能用 `event_id`。**

**`message_reference.message_id` 从哪来**

- 非机器人发的消息：事件 `d.message_scene.ext` 数组里 `msg_idx=` 后面那段（形如 `REFIDX_xxx==`）
- 机器人自己发的消息：发消息响应里的 `ext_info.ref_idx`

### 4.3 msg_seq 规则（实现重点，必须逐条落地）

**规则原文**（`server-inter/message/overview.md` + 两个发送接口页）：

1. `msg_seq` 与 `msg_id` 联合使用，**避免相同消息 id 回复重复发送**。
2. **不填默认是 1**。
3. **相同的 `msg_id + msg_seq` 重复发送会失败**（错误码 `40054005` "消息被去重"）。
4. 要"对同一条用户消息发多条回复"，就**递增 `msg_seq`**（1, 2, 3…）。
5. 事件可能**重复推送**（同一 `msg_id` 多次到达），业务侧要**结合 `msg_seq` 去重**。

**时效与次数上限**（`server-inter/message/overview.md`「频率与时效规则」）

| 场景 | 被动消息有效期 | 每条消息可回复次数 |
| --- | --- | --- |
| 单聊 | **60 分钟** | **4 次** |
| 群聊 | **5 分钟** | **5 次** |
| 频道 | 5 分钟 | 文档未给（超时即失败） |

**必须落到代码的推论**

- `msg_seq` 的有效范围是 **1..N**（N = 4 单聊 / 5 群聊）。**超过 N 次回复会失败**，所以"分多条回复"要提前规划：不要把一个长回答拆成 10 条。
- `msg_seq` 应当**按 `msg_id` 独立计数**，建议存 Redis：`INCR qqbot:seq:{msg_id}` 并设 TTL（群 300s / 单聊 3600s，再留 60s 余量）。
- **幂等**：`(msg_id, msg_seq)` 作为幂等键，发送前 `SETNX` 占位，失败可释放；平台侧 `40054005` 是"去重"而非"网络错误"，**不要无脑重试**。
- **主动消息**（不带 `msg_id`/`event_id`）**不适用** `msg_seq` 语义，但依然受主动消息频控（见第 6.3 节）。
- 群消息 `msg_seq` 示例里出现过 `2`（因为同一 `msg_id` 已经发过 1 次），照此递增即可。

**推荐实现（PHP）**

```php
final class PassiveReplyGuard
{
    public function __construct(private \Redis $redis, private int $maxPerMsg) {}

    /** 返回本次可用的 msg_seq；超限返回 null（不要发） */
    public function nextSeq(string $msgId, int $ttl = 3600): ?int
    {
        $key = "qqbot:seq:{$msgId}";
        $n = $this->redis->incr($key);
        if ($n === 1) $this->redis->expire($key, $ttl);   // 首次设置 TTL
        return $n <= $this->maxPerMsg ? $n : null;
    }
}
```

### 4.4 响应体

```json
{
  "id": "ROBOT1.0_a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0c1d2e3f4a5b6c7d8e9f0a1b2",
  "timestamp": "2026-07-21T10:00:00+08:00",
  "ext_info": { "ref_idx": "REFIDX_xxxxxxxxxxxxxxx==" }
}
```

| 字段 | 说明 |
| --- | --- |
| `id` | 消息 ID，**撤回时用** |
| `timestamp` | 发送时间，RFC3339 **东八区** |
| `ext_info.ref_idx` | 引用消息索引，对应 `message_scene.ext` 的 `msg_idx`/`ref_msg_idx` |

### 4.5 各类消息报文示例

**① 群聊纯文本（被动回复）**

```http
POST /v2/groups/B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5/messages HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
Content-Type: application/json; charset=utf-8

{
  "msg_type": 0,
  "content": "欢迎使用本群助手，有什么可以帮你的吗？",
  "msg_id": "ROBOT1.0_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "msg_seq": 1
}
```

**② 群聊 Markdown + 自定义键盘**

```json
{
  "msg_type": 2,
  "markdown": {
    "content": "## 每日签到\n\n今日签到成功！获得 **50** 积分\n连续签到 **7** 天"
  },
  "keyboard": {
    "content": {
      "rows": [
        { "buttons": [
            {
              "id": "btn_signin",
              "render_data": { "label": "签到", "style": 1 },
              "action": {
                "type": 2,
                "permission": { "type": 2 },
                "data": "/签到",
                "enter": true
              }
            }
        ] }
      ]
    }
  },
  "msg_id": "ROBOT1.0_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "msg_seq": 1
}
```

**③ 单聊富媒体（msg_type=7）**

```json
{
  "msg_type": 7,
  "media": { "file_info": "AE86C5D3F0E14B238C656C0F6DD1D0479C" },
  "msg_id": "ROBOT1.0_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "msg_seq": 1
}
```

**④ 单聊「输入中」状态（仅单聊，msg_type=6）**

```json
{
  "msg_type": 6,
  "input_notify": { "input_type": 1, "input_second": 60 },
  "msg_id": "ROBOT1.0_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx",
  "msg_seq": 1
}
```

**⑤ 频道文本（含 @ 内嵌格式）**

```http
POST /channels/100010/messages HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
Content-Type: application/json

{ "content": "<@!1234>hello world", "msg_id": "xxxxxx" }
```

```json
{
  "id": "xxxxxx", "channel_id": "xxxxxx", "guild_id": "xxxxxx",
  "content": "<@!1234>hello world",
  "timestamp": "2021-05-13T14:45:45+08:00",
  "tts": false, "mention_everyone": false,
  "author": { "id": "xxxxxx", "username": "abc", "avatar": "", "bot": true },
  "embeds": [{}], "pinned": false, "type": 0, "flags": 0
}
```

**⑥ 频道 markdown**

```json
{ "markdown": { "content": "# 标题 \n## 简介很开心 \n内容[🔗腾讯](https://www.qq.com)" }, "msg_id": "xxxxxx" }
```

**⑦ 流式单聊（分片）**

```json
{
  "input_mode": "replace",
  "input_state": 1,
  "index": 0,
  "content_type": "markdown",
  "content_raw": "正在生成回答，请稍候",
  "msg_id": "ROBOT1.0_xxx",
  "msg_seq": 1
}
```

- `input_mode`：`append`（默认，拼接到 Pending）/ `replace`（当前全量正文，必须以上游已下发前缀开头）
- `input_state`：`1` 生成中 / `10` 生成结束
- 首片响应 `id` **就是后续分片的 `stream_msg_id`**；`index` 从 0 递增
- `content_type`：`text` / `markdown`
- 续片失败 `40007` 表示"已下发内容前缀不可修改"

### 4.6 Markdown 与 Keyboard 结构

**MessageMarkdown**

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `content` | string | 自定义 markdown 文本 |
| `template_id` | int | **【已废弃】** 平台模板 ID |
| `custom_template_id` | string | **【已废弃】** 自定义模板 ID（与 template_id 二选一） |
| `force_verify_image_resource` | bool | true 时图片转存失败即报错不发；默认 false |

> `markdown.md` 说明：**2026-04-23 起，单聊/群聊自定义 Markdown 已对所有机器人开放，无需申请模板**；频道场景仍需内邀开通。

**Keyboard**

```json
{
  "keyboard": {
    "id": "keyboard_id_xxx",                     // 平台模板键盘（短形式）
    "content": { "rows": [ { "buttons": [
      {
        "id": "button_1",                        // 同一键盘内唯一
        "render_data": {
          "label": "确认",                        // 最多 10 字符
          "visited_label": "已确认",
          "style": 1                             // 0 灰线框 / 1 蓝线框 / 3 白底红字 / 4 蓝底白字
        },
        "action": {
          "type": 2,                             // 0 跳转 / 1 回调 / 2 指令
          "permission": { "type": 2,             // 0 指定用户 / 1 管理员 / 2 所有人
                          "specify_user_ids": [], "specify_role_ids": [] },
          "data": "/action_confirm",             // type=1/2 必填
          "enter": true,                         // 指令按钮：点击后直接发送（仅单聊）
          "reply": true,                         // 指令是否带引用回复
          "unsupport_tips": "请升级QQ版本",
          "modal": { "content": "确认执行？", "confirm_text": "确认", "cancel_text": "取消" }
        },
        "group_id": "g1"                          // 同组按钮点击后其余变灰（仅 action.type=1 有效）
      }
    ] } ] }
  }
}
```

- `action.type`：**0 跳转**（http/小程序）/ **1 回调**（平台把 `data` 推给后台，触发 `INTERACTION_CREATE`）/ **2 指令**（自动在输入框插入 `@bot {data}`）
- `modal`：二次确认，`content` ≤40 字且**不能含 URL**，`confirm_text`/`cancel_text` ≤4 字
- `click_limit` 与 `style` 里的 `KeyboardContentStyle.font_size` 属于本地补充/已废弃字段，慎用

### 4.7 主动 / 被动消息判定（三端统一）

| 类型 | 判定 | 说明 |
| --- | --- | --- |
| **主动消息** | **未填** `msg_id` 且 **未填** `event_id` | 机器人主动触达；用户可关闭"允许主动发送"，关闭后必失败 |
| **被动消息（回复用户）** | 填了 `msg_id` | 接口会拉取该消息并校验时间，超时效直接拒绝 |
| **被动消息（响应事件）** | 填了 `event_id` | 支持事件白名单见 4.2 |
| **互动召回消息** | `is_wakeup=true` | 仅单聊；用户主动对话后 30 天内，每个周期（当天 / 1-3 天 / 3-7 天 / 7-30 天，共 4 个）可发 1 条 |

频道侧文档原文：`msg_id`/`event_id` **任意填一个即为被动消息**；频道支持被动回复的事件类型为 `GUILD_MEMBER_ADD `、`GUILD_MEMBER_UPDATE`、`GUILD_MEMBER_REMOVE`、`MESSAGE_REACTION_ADD`、`MESSAGE_REACTION_REMOVE`、`FORUM_THREAD_*`、`FORUM_POST_*`、`FORUM_REPLY_*`。

### 4.8 撤回

| 场景 | 接口 | 限制 |
| --- | --- | --- |
| 单聊 | `DELETE /v2/users/{user_openid}/messages/{message_id}` | 发送超 **2 分钟**不可撤回 |
| 群聊 | `DELETE /v2/groups/{group_openid}/messages/{message_id}` | 同上；机器人是管理员可撤他人消息，普通成员只能撤自己发的 |
| 频道 | `DELETE /channels/{channel_id}/messages/{message_id}?hidetip=false` | `hidetip` 控制是否隐藏小灰条，默认 false。**仅私域机器人可用** |
| 频道私信 | `DELETE /dms/{guild_id}/messages/{message_id}?hidetip=false` | 只能撤自己发的 |

成功返回 **HTTP 200**（无 body / 空 JSON）。群撤回错误码：`40061001` 参数无效、`40062003` 无权限、`40064004` 超 2 分钟、`50065001` 稍后重试。

---

## 5. 富媒体（图片 / 语音 / 视频 / 文件）

### 5.1 文件类型与大小限制

| `file_type` | 类型 | 支持格式 | 软限制 | 硬限制 |
| --- | --- | --- | --- | --- |
| `1` | 图片 | png / jpg（文档另称支持 gif/webp/bmp 展示） | 20 MB | 200 MB |
| `2` | 视频 | mp4 | 30 MB | 200 MB |
| `3` | 语音 | silk（群聊接口另支持 mp3/wav/ogg） | 20 MB | 200 MB |
| `4` | 文件 | 任意 | 200 MB | 200 MB |

**超过软限制会自动降级为"文件类型"上传；超过硬限制直接报错。**

### 5.2 上传接口

| 场景 | Endpoint | 频控 |
| --- | --- | --- |
| 单聊整文件上传/合并 | `POST /v2/users/{user_openid}/files` | 50 QPS |
| 群聊整文件上传/合并 | `POST /v2/groups/{group_openid}/files` | 50 QPS |
| 单聊预上传（分片） | `POST /v2/users/{user_id}/upload_prepare` | 10 QPS |
| 单聊分片完成 | `POST /v2/users/{user_id}/upload_part_finish` | 10 QPS |
| 群聊预上传（分片） | `POST /v2/groups/{group_id}/upload_prepare` | 10 QPS |
| 群聊分片完成 | `POST /v2/groups/{group_id}/upload_part_finish` | 10 QPS |

**请求体（files 接口）**

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `file_type` | int | 1 图片 / 2 视频 / 3 语音 / 4 文件 |
| `url` | string | 公网可访问资源 URL，须 `http` 开头，平台下载转存；**分片合并时可为空** |
| `srv_send_msg` | bool | `true` 上传后**直接发送**并**占用主动消息频次**，返回含消息 `id`；`false` 只返回 `file_info` |
| `upload_id` | string | 分片任务 ID，传了就走"合并"路径，`url` 可空 |
| `file_name` | string | 文件名（分片/二进制上传时必填） |
| `file_data` | string | 直接上传二进制内容，**≤5 MB**，超了用 URL 或分片 |

**响应体**

| 字段 | 说明 |
| --- | --- |
| `file_uuid` | 文件唯一 ID |
| `file_info` | **发消息时 `media.file_info` 直接透传**（内部是序列化二进制，不要解析） |
| `ttl` | `file_info` 有效期（秒），**0 表示长期可用**；过期要重新上传 |
| `id` | 仅 `srv_send_msg=true` 时返回的消息 ID |
| `raw_url` | 文件下载链接（COS 预签名 GET），有效期同 `ttl`；**仅分片合并且 file_type 为 1/2/3 时返回** |

```json
{ "file_uuid": "uuid_a1b2c3d4e5f6", "file_info": "AE86C5D3F0E14B238C656C0F6DD1D0479C", "ttl": 300 }
```

### 5.3 三种上传方式

**方式 A：URL 直传（最简）**

```http
POST /v2/groups/B2C3D4E5F6A1B2C3D4E5F6A1B2C3D4E5/files HTTP/1.1
Host: api.bot.qq.com
Authorization: QQBot {ACCESS_TOKEN}
Content-Type: application/json

{ "file_type": 1, "url": "https://example.com/image.png", "srv_send_msg": false }
```

**方式 B：二进制直传（≤5 MB）**

```json
{ "file_type": 1, "file_name": "111.png", "file_data": "<base64 或原始二进制字符串>" }
```

> 坑：`file_data` 在 JSON body 里传二进制通常要 **base64**；文档示例写的是"xxxx图片的二进制数据"，未明确编码。**建议：≤5MB 且来源是本地文件时，优先走分片上传或先托管到公网 CDN 再走 URL 直传**，避免编码歧义。

**方式 C：分片上传（推荐，大文件/本地文件）**

```text
1) POST /v2/{users|groups}/{openid}/upload_prepare
   body: { file_type, file_size, file_name, md5, sha1, md5_10m }
   → { upload_id, block_size(默认 5MB, 字符串), parts:[{index, presigned_url}], upload_config }

2) 对每个 part：HTTP PUT <presigned_url> 上传分片二进制

3) 每片 PUT 成功后：POST /v2/{users|groups}/{openid}/upload_part_finish
   body: { upload_id, part_index, block_size, md5 }

4) 全部分片完成：POST /v2/{users|groups}/{openid}/files
   body: { file_type, file_name, upload_id, srv_send_msg:false }
   → { file_uuid, file_info, ttl, raw_url? }
```

- `md5_10m` = **文件前 10002432 字节（约 9.54 MB）的 MD5**，用于秒传判断
- `block_size` 与 `file_size` 是**字符串**（文档类型写作 string），PHP 端注意别转 int 再回传
- 上传接口超时建议 **≥5 秒**；并发数与重试由 `upload_config` 下发

### 5.4 用 file_info 发消息

```json
{
  "msg_type": 7,
  "media": { "file_info": "AE86C5D3F0E14B238C656C0F6DD1D0479C" },
  "msg_id": "ROBOT1.0_xxx",
  "msg_seq": 2
}
```

- **单聊与群聊的上传接口互不通用**：单聊上传的文件只能发单聊，群聊只能发群聊。
- `srv_send_msg=true` 可省掉一次发消息调用，但**会计入主动消息频次**（被动场景请用 `false` + 带 `msg_id` 发送）。
- `file_info` 有 `ttl`，**建议上传后立即发送**；缓存 file_info 必须带过期时间。

### 5.5 图片 / 语音 / 视频 / 文件的发送差异（汇总）

| 类型 | 发送方式 | 备注 |
| --- | --- | --- |
| 图片 | `msg_type=7` + `file_type=1` 上传；**频道**可直接 `image` 字段传 URL，或 form-data `file_image` | 群/单聊必须先上传 |
| 语音 | `msg_type=7` + `file_type=3`，silk 格式；接收侧 `attachments[].content_type="voice"` 且有 `voice_wav_url`/`asr_refer_text` | mp3/wav/ogg 由平台转 silk |
| 视频 | `msg_type=7` + `file_type=2`，mp4；接收侧 `content_type="video/mp4"` | 发送后展示封面可播放 |
| 文件 | `msg_type=7` + `file_type=4`；接收侧 `content_type="file"` | 群文件卡片；**频道不支持收文件** |

> 消息类型支持矩阵（`server-inter/message/type/overview.md`）：**文件**在单聊/群聊 收发 ✅、频道 ❌；**Markdown** 单聊 发✅收❌、群聊 收发✅、频道 发✅收❌；**结构化卡片** 单聊/群聊 收✅发❌。

---

## 6. 关键限制与坑

### 6.1 域名：正式 vs 沙箱

**【本地方档未载】**：全文检索 `api.sgroup.qq.com` / `sandbox.api.sgroup.qq.com` / `沙箱`，**只命中 3 处**，且都不是域名说明：

1. `changelog.md` 20260810：「**接口调用域名统一**：所有接口调用域名统一为 `api.bot.qq.com`」 ← 这是权威结论
2. websocket 错误码 `4914`：「机器人已下架，只允许连接**沙箱环境**」
3. `server-inter/channel/message/event.md`：「私信场景无法设置沙箱频道，目前**私信事件不支持沙箱环境**」

**结论（按本地文档）**

| 环境 | 域名 |
| --- | --- |
| 正式 | `https://api.bot.qq.com`（2026-08-10 起**统一**） |
| 沙箱 | **文档未给独立域名**；沙箱是通过管理端把机器人置为沙箱/调试态来体现，错误码 `4914` 提示"只允许连接沙箱环境" |

落地建议：

- 代码里把 base URL 做成配置 `QQBOT_BASE_URL`，**默认值写 `https://api.bot.qq.com`**。
- 老资料里的 `api.sgroup.qq.com` / `sandbox.api.sgroup.qq.com` 属于**频道 API 时代**域名；若要兼容历史部署，做成**可切换**而不是硬编码，并在灰度时验证 `/gateway` 与发消息接口是否可用。
- 沙箱调试优先用**管理端沙箱设置 + 用户白名单**，而不是切域名（文档明确私信不支持沙箱）。

### 6.2 @ 提及语法（新旧两套，务必分清）

**新语法（官方推荐，`server-inter/message/trans/text-chain.md`）**

| 能力 | 语法 | 支持消息类型 | 条件 |
| --- | --- | --- | --- |
| @用户（群聊） | `<qqbot-at-user id="" />`，`id` 填 **member_openid** | **仅 markdown** | — |
| @用户（频道） | `<qqbot-at-user id="" />`，`id` 填 **user_openid** | 文本 / 图文 / markdown | — |
| @全体（群聊） | `<qqbot-at-everyone />` | 文本 / markdown | 机器人须为**群管理员**，且**每群每天仅 1 次**（自然日） |
| @全体（频道） | `<qqbot-at-everyone />` | 文本 / 图文 / markdown | 需有 @全体 权限 |
| 指令·回车 | `<qqbot-cmd-enter text="xxx" />` | 仅 markdown | text ≤100 字符，**须 urlencode**；群聊/子频道不支持 |
| 指令·参数 | `<qqbot-cmd-input text="xxx" show="xxx" reference="false" />` | 仅 markdown | text/show ≤100 字符，须 urlencode |
| 跳转子频道 | `<#channel_id>` | 仅频道 | 仅当前频道内子频道 |
| 系统表情 | `<emoji:id>` | 仅频道 | 仅 `type=1`；`type=2` 直接写 emoji 字符 |

**旧语法（文档标注"即将弃用"，但接收侧仍会出现）**

| 语法 | 场景 | 说明 |
| --- | --- | --- |
| `<@member_openid>` 或 `<@!user_id>` | 频道发送 / 群消息接收 | 频道发送示例 `"content":"<@!1234>hello world"` |
| `@everyone` | 频道 | 解析为 @所有人 |
| `<@all>` | **群消息接收侧** | `group_message_create.md`：@全体在 content 里呈现为 `<@all>` |

**要点**

- 群@消息事件 `GROUP_AT_MESSAGE_CREATE` 的 `content` **已经自动去掉 @机器人 的前缀**，不需要你再 strip。
- `GROUP_MESSAGE_CREATE`（全量模式）里其它人的 @ 会保留为 `<@member_openid>` / `<@all>`，需要自己解析 `mentions[]` 更稳妥。
- 内嵌格式**只在 `content` 生效**，在 Ark / Embed 中无效。
- 频道侧转义：消息抄送会把 `&`→`&amp;`、`<`→`&lt;`、`>`→`&gt;`；发消息时会转回来（实现解析时注意反转义）。

### 6.3 频控汇总

**消息发送频控**

| 场景 | 维度 | 限制 |
| --- | --- | --- |
| 单聊（主动） | Bot 维度 | 企业/个人认证 **10 qps**；未认证 **5 qps 且 30 qpm** |
| 单聊（主动） | 单关系维度 | **20 qpm**；每用户每天 **1000 条** |
| 单聊（接口） | 全类型 | **100 QPS** |
| 群聊（主动） | Bot 维度 | 企业/个人认证 **60 qpm**；未认证 **30 qpm** |
| 群聊（主动） | 单关系维度 | **20 qpm**；每群每天 **1000 条** |
| 群聊（接口） | 全类型 | **100 QPS** |
| 频道 | 单子频道 | **5 条/秒**（主动+被动合计） |
| 频道（主动） | 每子频道 | 默认 **20 条/天** |
| 频道（主动） | 每频道 | 每天最多对 **2 个子频道**推送主动消息 |
| 频道私信（主动） | 每用户 | **2 条/天**；每机器人累计 **200 条/天**；被动不限 |
| 富媒体上传 | — | 50 QPS（files）/ 10 QPS（prepare、part_finish） |

**其他接口频控（文档明示的）**

| 接口 | 频控 |
| --- | --- |
| `GET /gateway` | **2 QPM / 10 QPM burst** |
| `GET /v2/groups/{gid}/info` | 30 QPM |
| `GET /v2/groups/{gid}/bot_state` | 30 QPM |
| `GET /v2/groups/{gid}/members` | 60 QPM（内邀中） |
| `GET /v2/groups/{gid}/members/{moid}` | 30 QPM（内邀中） |
| `GET /v2/groups/{gid}/member_blacklist` | 30 QPM（内邀中） |
| `GET/POST /v2/groups/{gid}/restrict_chat_setting` | 30 QPM |
| `GET/PUT /v2/menu` | 30 QPM |
| `PUT /interactions/{id}` | 50 QPS |
| `GET /users/@me` | 50 QPS |
| `DELETE /v2/groups/{gid}/messages/{mid}` | 10 QPS |
| `POST /v2/users/{oid}/stream_messages` | 50 QPS |

### 6.4 其他硬限制清单

- **频道发消息要求机器人必须连着 WebSocket 在线**（文档原文："发送消息接口要求机器人接口需要连接到 websocket 上保持在线状态"）。群/单聊无此要求。
- **消息撤回时限 2 分钟**（群/单聊）。
- **被动回复**：群 5 分钟 / 单聊 60 分钟；回复次数 群 5 次 / 单聊 4 次。
- **`msg_id` 与 `event_id` 互斥**（同一个请求别都传）。
- **主动消息开关**：用户在资料卡可关闭，关闭后主动消息必失败（`40034105` / `40054103` 类错误）。
- **互动回应超时 3 秒**（type=11/12），且同一 `interaction_id` 只能回一次。
- **@全体**：群聊每群每天 1 次且需管理员。
- **群消息 Markdown** 已开放；**频道 Markdown** 需内邀。
- **图片转存**：markdown 里引用的公网图必须公网可访问；否则用素材管理上传拿 `qqbot-media://` 引用。
- **`srv_send_msg=true` 占用主动消息频次。**
- **群成员列表/黑名单/群信息**等接口**仅白名单机器人可用**（错误码 `11253`）。

### 6.5 用素材管理（qqbot-media://）给 Markdown 配图

```text
1) POST /v2/media-images/prepare   {file_name, file_size, content_type, md5, sha1}
   → {upload_id(4h), presigned_url, expires_in}
2) PUT  <presigned_url>  (Content-Type 必须与 prepare 时一致，否则 COS 403)
3) POST /v2/media-images/complete {upload_id, md5(=ETag)}
   → {image_id, url: "qqbot-media://image/v1/<token>", ttl: 7776000, preview_url}
4) markdown.content 里写：![说明 #800px #400px](qqbot-media://image/v1/<token>)
```

- 限制：单图 ≤10 MB；png/jpeg/gif/webp；单机器人上限 **100 张**；滑动过期 **90 天**（每次被引用顺延）；prepare 任务 4 小时失效。
- 相关接口：`GET /v2/media-images?cursor=`、`DELETE /v2/media-images/{image_id}/delete`。

---

## 7. 常用 OpenAPI 清单

### 7.1 机器人自身

| 接口 | 说明 |
| --- | --- |
| `GET /users/@me` | 机器人详情：`id, username, avatar, bot, union_openid, union_user_account`，示例里还有 `share_url`、`welcome_msg` |
| `GET /users/@me/guilds` | 机器人所在频道列表（`?limit=` 分页） |
| `POST /v2/generate_url_link` | 生成分享链接 |

### 7.2 QQ 群（`group_openid`）

| 方法 | 路径 | 说明 | 频控 / 备注 |
| --- | --- | --- | --- |
| GET | `/v2/groups/{gid}/info` | 群基本信息 | 30 QPM；**白名单**；返回 `group_openid, group_name, group_finger_memo, group_class_text, group_tags[], group_member_num` |
| GET | `/v2/groups/{gid}/bot_state` | 机器人群内状态 | 30 QPM；返回 `member_openid, joined_at, allow_proactive_msg, recv_msg_setting(all/only_mention/mention_and_context), member_role` |
| GET | `/v2/groups/{gid}/members` | 群成员列表（每次 ≤30 条，游标分页） | 60 QPM；**内邀中** |
| GET | `/v2/groups/{gid}/members/{moid}` | 群成员信息 | 30 QPM；**内邀中** |
| POST | `/v2/groups/{gid}/batch_remove_members` | 群成员批量移除 | — |
| GET | `/v2/groups/{gid}/member_blacklist` | 群黑名单查询 | 30 QPM；**内邀中** |
| POST | `/v2/groups/{gid}/member_blacklist` | 群黑名单操作 | — |
| GET | `/v2/groups/{gid}/restrict_chat_setting` | 查询群禁言状态 | 30 QPM；需管理员 |
| POST | `/v2/groups/{gid}/restrict_chat_setting` | 设置群成员禁言 | 需管理员 |
| GET | `/v2/groups/{gid}/join_request_list` | 入群申请列表 | — |
| POST | `/v2/groups/{gid}/approval_join_request/{moid}` | 入群申请审批 | 需回传 `join_request_id` |
| GET/POST/PATCH/DELETE | `/v2/groups/join_approval_strategy[/{sid}]` | 入群自动审批策略 CRUD | — |
| POST | `/v2/groups/join_approval_strategy/{sid}/execute` | 执行策略 | — |
| POST | `/v2/groups/join_approval_strategy/{sid}/whitelist_users` | 改白名单 | — |

**群成员对象**：`member_openid, username, member_role(member/owner/admin), bot, joined_at(RFC3339), union_openid`。

### 7.3 频道（`guild_id` / `channel_id`）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| GET | `/guilds/{gid}` | 频道详情 |
| GET | `/guilds/{gid}/channels` | 子频道列表 |
| POST | `/guilds/{gid}/channels` | 创建子频道 |
| GET | `/channels/{cid}` | 子频道详情 |
| PATCH | `/channels/{cid}` | 修改子频道 |
| DELETE | `/channels/{cid}` | 删除子频道 |
| GET | `/guilds/{gid}/members?after=&limit=` | 频道成员列表（`limit` 1-400，默认 1；用最后一个 member 的 user id 作为下次 `after`，去重靠 user id） |
| GET | `/guilds/{gid}/members/{uid}` | 成员详情 |
| DELETE | `/guilds/{gid}/members/{uid}` | 删除成员 |
| GET | `/guilds/{gid}/roles` | 身份组列表（另有 POST/PATCH/DELETE） |
| GET | `/channels/{cid}/online_nums` | 子频道在线人数 |
| GET/PUT/DELETE | `/channels/{cid}/permissions/...` | 子频道用户/身份组权限 |

### 7.4 表情表态（**仅频道可用**）

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| PUT | `/channels/{cid}/messages/{mid}/reactions/{type}/{id}` | 发表情表态，成功 **204** |
| DELETE | `/channels/{cid}/messages/{mid}/reactions/{type}/{id}` | 删自己的表态，成功 **204** |
| GET | `/channels/{cid}/messages/{mid}/reactions/{type}/{id}?cookie=&limit=` | 拉表态用户列表（`limit` 默认 20 最大 50，返回 `users[]/cookie/is_end`） |

- `type`：`1` 系统表情 / `2` emoji 表情；表情 ID 见 `openapi/emoji/model.md`（如 `4` 得意、`66` 爱心、`76` 赞）。
- 相关事件 `MESSAGE_REACTION_ADD/REMOVE`；错误码段 `620001~620007`。

### 7.5 互动 / 菜单 / 面板

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| PUT | `/interactions/{interaction_id}` | 互动回应 `{"code":0}`，50 QPS |
| GET / PUT | `/v2/menu` | 查询 / 修改全局自定义菜单（`version`、`menu.items[]`；item `type` = switch/send_message/link/menu，最多 10 个；子菜单最多 5 个） |
| GET / POST | `/v2/panels` | 指令面板列表 / 创建 |
| GET/PUT/DELETE | `/v2/panels/{panel_id}` | 面板详情 / 修改 / 删除 |
| PUT | `/v2/panels/{panel_id}/target` | 修改面板关联对象 |

### 7.6 频道内容（次要，备查）

公告 `/guilds/{gid}/announces`、`/channels/{cid}/announces/{mid}`；精华 `/channels/{cid}/pins[/{mid}]`；日程 `/channels/{cid}/schedules[/{sid}]`；论坛 `/channels/{cid}/threads[/{tid}]`；音频 `/channels/{cid}/audio`、`/channels/{cid}/mic`。

### 7.7 文档里**不存在**的接口

- **好友列表查询**：文档只有 `FRIEND_ADD` / `FRIEND_DEL` **事件**，**没有**"获取好友列表"接口。
- **频道 = 群成员**：群成员接口是 `/v2/groups/...`（QQ 群），频道成员是 `/guilds/...`（频道），两套体系不同，openid 体系也不同（`user_openid` / `group_openid` / `member_openid` 各 AppID 下均不同，**不能跨 bot 关联同一人**）。

---

## 8. 错误码速查（实现必背）

### 8.1 鉴权 / 权限类

| code | 含义 |
| --- | --- |
| 11241 / 11242 / 11243 | 缺 token / token 校验系统错误 / **token 校验未通过** |
| 11251 / 11261 / 11275 | appid 错 / 缺 appid / 无 appid |
| 11253 | **应用无接口访问权限**（需申请） |
| 11254 | 接口被封禁 |
| 11264 | 频道权限未通过（需授权） |
| 11265 | 机器人已被封禁 |
| 4914 / 4915 | （WS）只允许沙箱 / 已封禁 |

### 8.2 发消息高频错误

| code | 含义 | 处置 |
| --- | --- | --- |
| `40034005` | **回复消息 msg_id 已过期** | 尽快回复；检查是否超过 5 分钟/60 分钟 |
| `40034024` | msg_id 无效或越权 | 检查取自哪个事件 |
| `40034025` / `40034026` / `40034027` | event_id 无效 / 已过期 / 该事件不支持回复 | 对照 4.2 白名单 |
| `40034128` | 被动回复**时间或次数超限** | msg_seq 或时效 |
| `40054005` | **消息被去重**（msg_id+msg_seq 重复） | 换 msg_seq，不要重试 |
| `40034100` | 主动消息超频控 | 降频/等待配额 |
| `40034105` | 主动消息无权限（用户关闭） | 尊重用户开关 |
| `40054002` | 机器人被禁言 | 等待解禁 |
| `40054003` / `40034101` | 机器人不是群成员 | 先拉群 |
| `40054010` | 不允许发送 URL | 去链接 |
| `40054007` | 消息长度超限 | 截断 |
| `22006` | msg_type 与 content 不匹配 | 检查枚举 |
| `304036` / `40034127` | 无 Markdown 模板权限 | 已放开（2026-04-23），频道除外 |
| `40034011` | 无效 markdown 内容 | 检查语法 |
| `40034008` / `40034009` / `40034010` | markdown 有空值 / 有换行 / 模板参数含 markdown 语法 | — |
| `40034029` | 内联键盘行列超限 | 减少按钮 |
| `850019` | 不支持的文件格式 | 检查 file_type |
| `850031` | 文件超大小限制 | 压缩/降级 |
| `40093002` | 超过当天发送文件容量上限 | 次日再试 |

### 8.3 互动类

| code | 含义 |
| --- | --- |
| 630001 | param invalid |
| 630003 | AppID 与 interaction_id 不匹配（**用错 Bot Token**） |
| 630007 | data too large |
| 630008 | interaction 预处理失败 |

### 8.4 撤回类

`306001` 参数错 / `306002` msgid 错 / `306003` 获取消息失败（可重试）/ `306004` 无权限 / `306005` 撤回失败（可重试）/ `306006` 获取子频道失败（可重试）。

---

## 9. PHP(Swoole) 实现检查清单（Checklist）

- [ ] `access_token` 进程内缓存 + Redis 缓存，提前 120s 刷新，并发加锁，401 时失效重取一次
- [ ] Authorization 头是 `QQBot {token}`（不是 `Bot`/`Bearer`）
- [ ] Webhook：`rawContent()` 原始字节验签；seed 派生复刻 `repeat→32B→seed_keypair`；签名体 `timestamp.body`
- [ ] Webhook：op=13 回 `{plain_token, signature(event_ts+plain_token)}`；普通事件回 **200 + `{"op":12,"d":{}}`**
- [ ] 事件幂等：`SETNX qqbot:evt:{id} EX 86400`
- [ ] WebSocket：op=10 取 `heartbeat_interval` → op=2 Identify（`token`/`intents`/`shard`）→ 存 `session_id`
- [ ] 心跳 `0.9 * interval`，`d` = 最新 `s`（首帧 `null`），连续 3 次无 op=11 主动重连
- [ ] 断线走 op=6 Resume（`token`/`session_id`/`seq`）；4006/4007/op9 → 改 Identify
- [ ] 每片独立 `seq`/`session_id`，多分片多连接
- [ ] intents 可配置；遇 4013/4014 降级重试并告警
- [ ] 被动回复：`msg_id`（群 5min/5 次、单聊 60min/4 次）；`msg_seq` 按 `msg_id` 自增并 TTL；`(msg_id,msg_seq)` 幂等
- [ ] 主动 vs 被动判定：有无 `msg_id`/`event_id`
- [ ] 富媒体：先上传拿 `file_info`（注意 `ttl` 与"单聊/群聊不通用"），再 `msg_type=7` + `media.file_info`
- [ ] 群/单聊 与 频道 三套 endpoint 分开封装，字段差异（`embed`/`ark`/`image` 只在频道）
- [ ] @ 提及用新语法 `<qqbot-at-user id="member_openid" />` / `<qqbot-at-everyone />`（旧 `<@!id>` 兼容解析）
- [ ] 撤回 2 分钟限制；频道发消息需 WS 在线
- [ ] 记录 `X-Tps-trace-ID` / `trace_id` 到日志，便于提工单

---

## 10. 复核索引（本文引用的具体文档路径）

所有路径相对 `/storage/emulated/0/model/qqbot-api-v2-docs/docs/`：

| 主题 | 文档 |
| --- | --- |
| 鉴权 / token 获取 / 有效期 / header | `dev-prepare/access-token.md`、`dev-prepare/interface-framework/api-use.md`、`dev-prepare/api-call-guide.md` |
| 统一域名（2026-08-10） | `changelog.md` |
| 签名算法（Ed25519 / seed 派生 / DEMO 向量） | `dev-prepare/interface-framework/sign.md` |
| Webhook 配置 / 端口 / op=13 握手 | `dev-prepare/event-emit/webhook.md` |
| Payload 结构 / opcode 全表 / Intents | `dev-prepare/event-emit/payload.md` |
| opcode 精简表 | `dev-prepare/interface-framework/opcode.md` |
| WebSocket 全流程（Hello/Identify/Ready/心跳/Resume/分片） | `dev-prepare/event-emit/websocket.md`、`dev-prepare/interface-framework/reference.md` |
| WS 错误码与处置 | `dev-prepare/error-trace/websocket.md`、`gateway/error/error.md` |
| 网关地址接口 | `openapi/wss/url_get.md`、`openapi/wss/shard_url_get.md`、`autogen/api/gateway.get.md` |
| 消息收发概述 / 主动被动 / 时效频控 / 撤回 | `server-inter/message/overview.md` |
| 发送群聊消息（全字段/错误码） | `autogen/api/v2_groups_group_openid_messages.post.md` |
| 发送单聊消息（全字段/错误码） | `autogen/api/v2_users_user_openid_messages.post.md` |
| 流式单聊 | `autogen/api/v2_users_user_openid_stream_messages.post.md` |
| 发送子频道消息 / 内嵌格式 / 撤回 / 私信 | `server-inter/channel/message/send.md`、`format.md`、`recall.md`、`dms.md` |
| 频道消息事件 / 审核事件 | `server-inter/channel/message/event.md` |
| 消息类型矩阵 | `server-inter/message/type/overview.md` |
| Markdown 消息 | `server-inter/message/type/markdown.md` |
| @ 提及新语法 / 指令 | `server-inter/message/trans/text-chain.md` |
| 按钮与互动 | `server-inter/message/trans/overview.md`、`autogen/event/interaction_create.md`、`autogen/api/interactions_interaction_id.put.md` |
| 富媒体概述 / 类型限制 / file_info | `server-inter/message/rich-media.md` |
| 上传 / 预上传 / 分片完成 | `autogen/api/v2_users_user_openid_files.post.md`、`autogen/api/v2_groups_group_openid_files.post.md`、`autogen/api/v2_users_user_id_upload_prepare.post.md`、`autogen/api/v2_users_user_id_upload_part_finish.post.md` |
| 素材管理 qqbot-media:// | `server-inter/message/material-management.md` |
| 群@消息事件 / 群全量消息 / 单聊消息事件 | `autogen/event/group_at_message_create.md`、`group_message_create.md`、`c2c_message_create.md` |
| 群/好友/开关类事件 | `autogen/event/group_add_robot.md`、`group_del_robot.md`、`group_member_add.md`、`group_member_remove.md`、`group_join_request.md`、`friend_add.md`、`friend_del.md`、`c2c_msg_receive.md`、`c2c_msg_reject.md`、`group_msg_receive.md`、`group_msg_reject.md`、`subscribe_message_status.md` |
| 群信息 / 群状态 / 群成员 / 黑名单 / 禁言 | `autogen/api/v2_groups_group_openid_info.get.md`、`v2_groups_group_openid_bot_state.get.md`、`v2_groups_group_openid_members.get.md`、`v2_groups_group_openid_members_member_openid.get.md`、`v2_groups_group_openid_member_blacklist.get.md`、`v2_groups_group_openid_restrict_chat_setting.get.md` |
| 撤回群/单聊消息 | `autogen/api/v2_groups_group_openid_messages_message_id.delete.md`、`autogen/api/v2_users_user_openid_messages_message_id.delete.md` |
| 频道成员 / 身份组 / 权限 | `openapi/member/get_members.md`、`openapi/member/get_member.md`、`openapi/member/delete_member.md`、`server-inter/channel/role-group/` |
| 表情表态 / 表情对象 | `server-inter/message/trans/emoji.md`、`openapi/emoji/model.md`、`openapi/reaction/model.md` |
| 机器人详情 / 频道列表 | `autogen/api/users_me.get.md`、`autogen/api/users_me_guilds.get.md` |
| 自定义菜单 / 指令面板 | `autogen/api/v2_menu.get.md`、`v2_panels*.md` |
| 错误码总表 | `openapi/error/error.md`、`openapi/error/data/model.md` |
| 接口索引（全部 47 个接口页） | `autogen/README.md`、`docs/SUMMARY.md` |

> 原始 HTML 备份：`/storage/emulated/0/model/qqbot-api-v2-docs/_raw/pages/`（用于核对 markdown 转换时可能丢失的细节，如 `_raw/pages/dev-prepare/event-emit/webhook.html`）。

---

## 11. 待实网验证的 3 个空白点（文档未载，别当结论用）

1. **WebSocket 建连 query 参数**（`v/encoding/compress`）：文档未提，网关返回裸 URL。建议先裸连，实测不通再试 `?v=1&encoding=json`。
2. **zlib 压缩**：文档无任何压缩描述。按纯 JSON 处理，代码里保留 `gzuncompress/gzinflate` 兜底即可。
3. **Webhook op=12 回包 body 形状**：只有"ACK"语义，无 schema。用 `{"op":12,"d":{}}` + HTTP 200 最稳（与 Go/Python 官方 SDK 行为一致）。
