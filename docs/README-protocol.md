# 协议接入说明（OneBot11 WS / Milky / QQ 官方机器人）

> 本文档面向部署与二次开发。内部事件模型统一为 **OneBot11**，所有既有插件无需改动。
> 可视化配置与状态见 [WebUI 控制台](README-webui.md)。

---

## 一、总览

| 协议 | 事件入口 | API 出口 | 启用开关 |
|---|---|---|---|
| OneBot11 HTTP（基线） | 原 webhook（`post_type` 直推） | `{cq.api}/{action}` | 始终可用 |
| OneBot11 **正向 WS** | 框架连实现端 | 同一条 WS（action+echo） | `protocol.onebot.forward.enable` |
| OneBot11 **反向 WS** | 实现端连框架 `/onebot/v11/ws` | 同一条 WS | `protocol.onebot.reverse.enable` |
| **Milky** | WebHook / WS / SSE（三选一） | `POST {milky.api}/api/{name}` | `protocol.milky.enable` |
| **QQ 官方机器人** | WebHook（Ed25519 验签）/ Gateway WS | `https://api.bot.qq.com` | `protocol.qqbot.enable` |

状态接口：`GET /api/protocol/status`。

---

## 二、配置

全部配置位于 `Config.ini` 的 `[protocol.*]` 段。

### 配置文件格式约定

配置内核（`Core\Config`）在 PHP 原生 INI 之上做了一层预处理，屏蔽了最常见的几个坑：

| 写法 | 原始 INI 行为 | 本框架行为 |
|---|---|---|
| `# 这是注释` | 解析成键名 `"# 这是注释"` | **当注释忽略**，并在配置页给出告警 |
| `; 这是注释` | 注释 | 注释（推荐写法） |
| `token = abc;def` | 只读到 `abc`，后半截静默丢失 | **完整保留**（自动加引号保护），并给出告警 |
| `note = "  x  "` | 引号内的首尾空白原本能保留 | 保留 |
| `port = 8008` | 字符串 `"8008"` | **int 8008** |
| `uin = 12345678901234567890` | 字符串 | 超出 int 范围时**保留字符串**（避免 QQ 号精度丢失） |
| `[Redis]` 配 `get('redis.host')` | 大小写不同读不到 | **不区分大小写**兜底 |
| 值里含换行 | 把整个小节写坏 | **拒绝写入**并说明原因（INI 无法表达） |

> 新增注释仍建议用 `;`——`#` 虽然兼容，但会在配置页留一条告警。
> 每次 WebUI 写回 `Config.ini` 前会自动备份到 `Config.ini.<时间戳>.bak`（保留最近 5 份）。

> ⚠️ 配置里的**告警不是错误**：它们表示"这行语法合法，但你的本意可能没被正确表达"，
> 可在 WebUI 的「配置」页顶部看到逐条说明。

### 2.1 OneBot11 WebSocket

```ini
[protocol.onebot]
enable = true
; 正向：本框架作为客户端连接实现端（go-cqhttp / Lagrange.OneBot / NapCat 等）
forward.enable = true
forward.url = ws://127.0.0.1:3001
forward.token = your_access_token
; 反向：实现端连接本框架 ws://<host>:<port>/onebot/v11/ws
reverse.enable = true
reverse.path = /onebot/v11/ws
reverse.token = your_access_token
```

- `forward.token` → 请求头 `Authorization: Bearer <token>`（与 go-cqhttp 的 `access_token` 对应）
- `reverse.token` → 校验实现端带来的 `Authorization: Bearer` 或 `?access_token=`；留空表示不校验
- **API 回包优先走「事件来源的那条连接」**：反向 WS 的每个实现端连接各自持有会话，
  把回复推到别的连接会导致消息发错人。没有来源 fd 时（HTTP 入口 / 定时任务）才退化为
  「正向优先 → 任选一个反向会话」
- 断线自动重连（2→30 秒退避）
- `self_id` 可在 `onebot.self_id` 写死，留空则由事件里的 `self_id` 自动学习

> ⚠️ **多 Worker 注意**：WS 会话表是 Worker 进程内的静态变量，`worker_num > 1` 时
> 反向连接只会落在某一个 Worker 上。由 **HTTP 请求触发**的 API 调用若恰好落到其它 Worker，
> 会因为看不到那条连接而回落到 HTTP 协议端。如需「任一 Worker 都能走 WS」，
> 请把 `worker_num` 设为 1（`[http] sw` 段或 Swoole 默认值）。事件本身不受影响——
> 事件总是在持有该连接的 Worker 内处理。

### 2.2 Milky

```ini
[protocol.milky]
enable = true
api = http://127.0.0.1:3010
token = your_token
timeout = 30
; 事件接入方式：webhook / ws / sse
event.mode = webhook
webhook.path = /api/protocol/milky/webhook
ws.url =
sse.url =
; 本框架对外提供 Milky WS 服务时的路径（可选，一般不用）
ws.server_path = /milky/event
```

事件方式三选一：

1. **webhook**：把协议端的 webhook 地址填为 `http://<host>:<port>/api/protocol/milky/webhook`
2. **ws**：框架主动连 `ws://{milky.api 的 host}:{port}/event`（可用 `ws.url` 覆盖）
3. **sse**：框架主动 `GET {milky.api}/event`（`text/event-stream`）

> Milky 的 WS **只推送事件，不能调 API**；所有 API 一律走 `POST /api/{name}` + `Authorization: Bearer`。

### 2.3 QQ 官方机器人

```ini
[protocol.qqbot]
enable = true
app_id = 你的AppID
client_secret = 你的AppSecret
api = https://api.bot.qq.com
token_url = https://api.bot.qq.com/app/getAppAccessToken
; 事件接入方式：webhook / ws
event.mode = ws
webhook.path = /api/protocol/qqbot/webhook
webhook.verify = true
; intents 可用整数，或事件名逗号分隔（GROUP_AND_C2C_EVENT,GROUP_MEMBER_EVENT）
intents =
gateway.shard = false
gateway.shard_id = 0
gateway.shard_count = 1
gateway.resume = true
```

- **webhook**：在 QQ 机器人管理端把回调地址填为 `https://<公网域名>/api/protocol/qqbot/webhook`。
  平台会先发 `op=13` 校验请求，框架自动返回 `{"plain_token","signature"}`。
  之后每条事件都会校验 `X-Signature-Ed25519`（依赖 `sodium` 扩展）。
- **ws**：框架走 Gateway（`GET /gateway` → wss，Hello → Identify → Heartbeat，断线 Resume）。

---

## 三、端点清单

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/protocol/webhook` | OneBot11 事件 HTTP 直推（扩展形态，等价于原 webhook） |
| POST | `/api/protocol/milky/webhook` | Milky WebHook |
| POST | `/api/protocol/qqbot/webhook` | QQ 官方 WebHook（含 op=13 验证） |
| GET | `/api/protocol/status` | 各协议运行状态 |
| WS | `/onebot/v11/ws` | OneBot 反向 WS（路径可配） |
| WS | `/milky/event` | Milky WS 服务端（可选，路径可配） |

---

## 四、ID 语义差异（务必注意）

这是三种协议最容易踩坑的地方：

| 字段 | OneBot11 | Milky | QQ 官方机器人 |
|---|---|---|---|
| `user_id` / `group_id` | int64 数字 | 数字（与 OneBot 一致） | **字符串 OpenID**（`ROBOT1.0_xxx`） |
| `message_id` | int | **无**，用会话内 `message_seq` 顶替 | 字符串（`d.id`） |
| 权限表键 `memberPermission.{id}` | 数字 | 数字 | OpenID 字符串 |
| `self_id` | 机器人 QQ 号 | 数字 | 由 `qqbot.self_id` 或 `app_id` 哈希派生 |

因此：

- 框架已把 `Api\*` 与 `BaseApp` 的 ID 参数类型拓宽为 `string|float|int`，插件无需强转。
- 若插件要做纯数字运算（取模、取号段），**必须先判断是否为 OpenID 字符串**。
- QQ 官方的 `message_id` 是字符串，撤回需要「`group_id`/`user_id` + `message_id`」组合。

---

## 五、API 映射速查

### 5.1 Milky（OneBot action → Milky API）

| OneBot | Milky |
|---|---|
| `send_msg` / `send_group_msg` / `send_private_msg` | `send_group_message` / `send_private_message` |
| `delete_msg` / `recall_msg` | `recall_group_message` / `recall_private_message` |
| `get_msg` | `get_message` |
| `get_group_msg_history` | `get_history_messages` |
| `get_friend_list` / `get_group_list` / `get_group_member_list` | 同名 |
| `set_group_kick` / `set_group_ban` / `set_group_whole_ban` | `kick_group_member` / `set_group_member_mute` / `set_group_whole_mute` |
| `set_group_card` / `set_group_name` / `set_group_admin` | `set_group_member_card` / `set_group_name` / `set_group_member_admin` |
| `upload_group_file` / `upload_private_file` | 同名 |
| `get_group_essence_msg_list` / `get_essence_msg_list` | `get_group_essence_messages`（注意用 `page_index`/`page_size`） |
| `set_essence_msg` / `delete_essence_msg` | `set_group_essence_message`（`is_set` 由动作名决定） |
| `_get_group_notice` / `_send_group_notice` / `_del_group_notice` | `get_group_announcements` / `send_group_announcement` / `delete_group_announcement` |
| `set_group_leave` / `quit_group` | `quit_group` |
| `set_qq_profile` / `set_qq_avatar` / `set_group_portrait` | `set_nickname` / `set_avatar` / `set_group_avatar` |
| `get_friend_info` / `get_stranger_info` | `get_friend_info` / `get_user_profile` |
| `get_group_root_files` / `get_group_files_by_folder` | `get_group_files` |
| `get_private_file_url` / `get_group_file_url` | `get_private_file_download_url` / `get_group_file_download_url` |
| `fetch_custom_face` | `get_custom_face_url_list` |
| `get_image` / `get_record` | `get_resource_temp_url`（临时链接会过期，长期请存 `resource_id`） |

**Milky 协议确实没有对应能力的操作**（返回 `retcode = -404` 并附带具体原因，而不是笼统的 not found）：
`_get_model_show`、`_set_model_show`、`get_ai_characters`、`get_ai_record`、`send_group_ai_record`、
`delete_unidirectional_friend`、`get_unidirectional_friend_list`、`ocr_image`、`send_group_sign`、
`set_group_anonymous`、`set_group_anonymous_ban`、`set_restart` 等。

> 作为对照：`api/*.php` 里一共用到 94 个 action，其中 70 个可以映射到 Milky；
> 剩余 24 个属于「Milky 没有该接口」或「协议端私有能力」，已在 `Milky\Adapter::UNSUPPORTED` 里逐条列出原因。

### 5.2 QQ 官方机器人

| OneBot | 官方 API |
|---|---|
| `send_group_msg` / `send_private_msg` | `POST /v2/groups/{openid}/messages` / `POST /v2/users/{openid}/messages` |
| `delete_msg` | `DELETE /v2/groups|users/{openid}/messages/{id}` |
| `get_group_member_list` | `GET /v2/groups/{openid}/members` |
| `get_group_member_info` | `GET /v2/groups/{openid}/members/{member_openid}` |
| `get_group_info` | `GET /v2/groups/{openid}/info` |
| `get_login_info` | `GET /users/@me` |
| `set_group_kick` | `POST /v2/groups/{openid}/batch_remove_members`（单次 ≤20） |
| `set_group_ban` | `POST /v2/groups/{openid}/restrict_chat_setting`（`duration` 秒 → `mute_expire_at`） |
| `get_group_system_msg` | `GET /v2/groups/{openid}/join_request_list` |
| `set_group_add_request` | `POST /v2/groups/{openid}/approval_join_request/{member}` |
| `get_group_member_blacklist` / `set_group_member_blacklist` | `GET`/`POST /v2/groups/{openid}/member_blacklist` |
| `get_group_bot_state` | `GET /v2/groups/{openid}/bot_state` |
| `get_menu` / `set_menu` | `GET`/`PUT /v2/menu`（仅单聊场景） |
| `generate_url_link` | `POST /v2/generate_url_link` |
| `interaction_reply` | `PUT /interactions/{interaction_id}` |
| `get_group_list` / `get_friend_list` / `*_msg_history` / `set_group_whole_ban` | **不支持**（官方未开放，返回 -501） |
| 富媒体 | `POST /v2/groups|users/{openid}/files` → 以 `msg_type=7` 发送 |

**被动回复**：官方要求回复必须带 `msg_id + msg_seq`。框架在收到消息事件时自动登记上下文
（群聊 5 分钟 / 5 次，单聊 60 分钟 / 4 次），发送时自动填充并递增 `msg_seq`。

**已转换的事件**：

| 官方事件 | OneBot 事件 |
|---|---|
| `GROUP_AT_MESSAGE_CREATE` / `GROUP_MESSAGE_CREATE` | `message` / `group` |
| `C2C_MESSAGE_CREATE` | `message` / `private` |
| `AT_MESSAGE_CREATE` / `MESSAGE_CREATE` / `DIRECT_MESSAGE_CREATE` | `message` / `channel`（频道场景） |
| `GROUP_ADD_ROBOT` | `notice` / `group_increase` / `invite`（`user_id` 是机器人自己） |
| `GROUP_DEL_ROBOT` | `notice` / `group_decrease` / `kick_me` |
| `GROUP_MEMBER_ADD` / `GROUP_MEMBER_REMOVE` | `notice` / `group_increase`·`group_decrease` |
| `GROUP_JOIN_REQUEST` | `request` / `group` / `add`（`flag` = `join_request_id`） |
| `FRIEND_ADD` / `FRIEND_DEL` | `request` / `friend` · `notice` |
| `C2C_MSG_RECEIVE` / `C2C_MSG_REJECT` | `notice` / `private_msg_receive` / `enable`·`disable` |
| `GROUP_MSG_RECEIVE` / `GROUP_MSG_REJECT` | `notice` / `group_msg_receive` / `enable`·`disable` |
| `SUBSCRIBE_MESSAGE_STATUS` | `notice` / `subscribe_message_status` |
| `INTERACTION_CREATE` | `notice` / `interaction`（用 `interaction_reply` 回应） |
| `READY` / `RESUMED` | `meta_event` / `lifecycle` / `enable`·`resume` |

---

## 六、与自己协议端的对接示例

### go-cqhttp / NapCat（OneBot11 反向 WS）

```yaml
# 实现端配置
servers:
  - ws-reverse:
      universal: ws://127.0.0.1:8008/onebot/v11/ws
      reconnect-interval: 3000
      access-token: your_access_token
```

### Lagrange.OneBot（OneBot11 正向 WS）

```ini
[protocol.onebot]
enable = true
forward.enable = true
forward.url = ws://127.0.0.1:8081
```

---

## 七、排障

| 现象 | 排查 |
|---|---|
| `Class "Protocol\...\..." not found` | 执行 `composer dump-autoload`（`protocol/` 是新增 PSR-4 命名空间） |
| 配置读取始终为默认值 | 检查 `Config.ini` 是否有 `#` 注释含 `=`；改用 `;` |
| OneBot 反向 WS 握手 400 | 确认 `open_websocket_protocol => true`、路径与 `reverse.path` 完全一致 |
| QQ 官方验签一直失败 | 依赖 `ext-sodium`；`client_secret` 必须与机器人后台一致；body 必须是原始字节 |
| QQ 官方回消息 40054005 | `msg_id+msg_seq` 重复，检查是否多个实例共用同一被动上下文 |
| 事件重复处理 | Milky 多地址投递会被去重缓存拦截（键 = self_id+event_type+peer+seq） |
| WS 连接上了但 API 调不到 | 多 Worker 下会话表不共享；把 `worker_num` 设为 1，或让实现端改用 HTTP |
| 反向 WS 连上后事件不触发 | 检查 `[type]` 段是否订阅了对应 `post_type`；以及 `onebot.reverse.enable` |
| Milky WS 收到帧但无事件 | Milky WS 只推送事件、无 action；若连的是本框架的 `/milky/event`，那是**出站**端点 |
| QQ 官方回消息 40054005 | `msg_id+msg_seq` 重复；同一 event 被多实例消费也会触发 |
| QQ 官方 `op=9` 后收不到事件 | 已改为主动断开重连；若仍异常检查 token 是否过期 |

---

## 八、自检脚本

```bash
# 语法检查（全项目）
for f in $(find . -path ./vendor -prune -o -name '*.php' -print); do php -l "$f"; done

# 协议自检（不依赖 Swoole / Redis）
php tests/protocol_test.php   # Milky 事件/消息段转换、QQ 官方事件转换、配置读取
php tests/qqbot_sign.php      # QQ 官方 Webhook Ed25519 签名（需要 ext-sodium）
php tests/event_flow.php      # Milky 幂等去重、QQ 官方被动回复 msg_seq 状态机
php tests/local.php           # Cache 协程私有存储（raw_body / headers）
```

全部通过时退出码为 0，可直接接入 CI；详见 [tests/README.md](../tests/README.md)。
