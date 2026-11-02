# Milky 协议规范摘要（面向 PHP 实现者）

> 目标读者：要用 PHP 实现 Milky **应用端（Bot 侧 / 适配器）** 的开发者。
> 版本锚定：**Milky 1.3（1.3.0）**。所有结论均来自本地官方仓库与生成物，文末附复核路径。

## 0. 一句话结论

Milky 只有 **一个方向** 的主动能力：**协议端（QQ 端）开 HTTP 服务器**，应用端 **POST /api/{api}** 调 API；事件由协议端 **推送** 给应用端，三选一：**SSE（GET /event）**、**WebSocket（ws://host/event）**、**WebHook（POST 到你的地址）**。

- **没有** OneBot11 那种 WS 上的 action/echo 请求-响应协议；**WS 通道只推送事件，不能用来调 API**。
- **没有** 正向 WS / 反向 WS 之分，**没有** http-post 上报的反向模式。
- **没有** message_id 这种东西，消息定位用 **(message_scene, peer_id, message_seq)** 三元组。
- **没有** CQ 码，消息是强类型 segment 数组。

---

## 1. 传输层

### 1.1 总览

| 能力 | 方向 | 方法 / 路径 | 鉴权位置 |
|---|---|---|---|
| API 调用 | 应用端 → 协议端 | `POST /api/{api_name}` | 请求头 `Authorization: Bearer {access_token}` |
| 事件推送 · SSE | 协议端 → 应用端 | `GET /event`，响应 `text/event-stream` | 同上，或 query `?access_token=` |
| 事件推送 · WebSocket | 协议端 → 应用端 | `GET /event` + `Upgrade: websocket` | 同上，或 query `?access_token=` |
| 事件推送 · WebHook | 协议端 → 应用端 | `POST {你配置的 URL}` | 协议端带 `Authorization: Bearer {access_token}`，**你负责校验** |

关键细节：**SSE 与 WebSocket 共用同一个 `/event` 路径**。协议端必须按这个规则分流：请求头 `Upgrade` 存在且值为 `websocket`（大小写不敏感）→ 走 WebSocket；否则 → 走 SSE。因此应用端**不需要**也不应该用不同路径。

所有传输数据一律 **UTF-8**。

### 1.2 API 调用（HTTP，唯一的调 API 方式）

**形态**

    POST /api/{api_name}
    Content-Type: application/json
    Authorization: Bearer {access_token}     # 若协议端配置了 access_token

    { ...JSON 请求体... }

- 路径参数就是 API 名（snake_case），例如 `/api/send_private_message`、`/api/get_group_list`。共 **65 个** API。
- **只接受 POST**。GET 形态协议未定义。
- **即使无参数也必须传空 JSON 对象 `{}`**，不能省略 body。
- `Content-Type` 必须是 `application/json`，否则 **415**。

**HTTP 状态码（只有这 4 种）**

| 状态码 | 含义 |
|---|---|
| `200` | **无论业务成功还是失败，都是 200**，靠 body 里的 `status`/`retcode` 判断 |
| `401` | 鉴权凭据未提供或不匹配 |
| `404` | 请求的 API 不存在 |
| `415` | Content-Type 不支持（非 `application/json`） |

**响应体（统一信封）**

```jsonc
// 成功
{
  "status": "ok",
  "retcode": 0,
  "data": { "message_seq": 23333, "time": 1234567890 }
}

// 成功但无返回数据 —— data 必须是空对象 {}，不是 null、不是 []
{ "status": "ok", "retcode": 0, "data": {} }

// 失败
{
  "status": "failed",
  "retcode": -400,
  "message": "user_id (-1) 不是一个合法的 QQ 号"
}
```

字段约束（来自 OpenAPI `ApiResponse`）：`status` 必填，枚举 `ok` | `failed`；`retcode` 必填，integer，**0 表示成功**；`data` 任意（成功且无数据时为 `{}`）；`message` **仅失败时**出现，可空。

**retcode 约定**

| retcode | 含义 |
|---|---|
| `0` | 成功 |
| `-400` | 参数解析失败（缺字段、类型错、QQ 号不合法等） |
| `-403` | 协议端未处于登录状态 |
| 其他 | 由协议端自行决定，**应用端不得据具体数值分支**，只能用 0 / 非 0 判断 |

> PHP 落地：**永远不要用 HTTP 状态码判断业务成败**，一定要读 `status` 与 `retcode`。同时要处理 401/404/415（这三种没有信封）。

**curl 示例**

    POST /api/send_private_message HTTP/1.1
    Host: 127.0.0.1:3010
    Content-Type: application/json
    Authorization: Bearer 123456

    {"user_id":123456789,"message":[{"type":"text","data":{"text":"Hello, world!"}}]}

### 1.3 SSE 连接

    GET /event
    Authorization: Bearer 123456
    # 或（不支持自定义 header 的环境）
    GET /event?access_token=123456

响应：`Content-Type: text/event-stream`，连接**保持不断**，事件即推即送。每条事件的 SSE 帧格式固定：

    event: milky_event
    data: {"time":1234567890,"self_id":123456789,"event_type":"message_receive","data":{...}}
    <空行结束>

注意：帧里 **`event:` 字段恒为 `milky_event`**（不是 `message`）；`data:` 后是一整行 JSON（文档示例把 JSON 美化成多行、每行都加 `data:` 前缀，那是示例排版；按 SSE 规范多条 `data:` 行会以换行拼成一份 payload，PHP 端把 `data:` 行拼接后 `json_decode` 即可，两种都能吃）。**必须以空行结尾**才是完整帧。

### 1.4 WebSocket 连接

    ws://{IP}:{端口}/event
    Authorization: Bearer 123456
    # 或
    ws://{IP}:{端口}/event?access_token=123456

握手样例（应用端发起）：

    GET /event HTTP/1.1
    Host: 127.0.0.1:3010
    Upgrade: websocket
    Connection: Upgrade
    Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==
    Sec-WebSocket-Version: 13
    Authorization: Bearer 123456

握手成功（`101 Switching Protocols`）后，**协议端单向推送事件**，每帧就是一条 JSON 文本（与 WebHook body、SSE data 完全相同，无外层包装）：

```json
{
  "time": 1234567890,
  "self_id": 123456789,
  "event_type": "message_receive",
  "data": {
    "message_scene": "friend",
    "peer_id": 123456789,
    "message_seq": 23333,
    "sender_id": 123456789,
    "time": 1234567890,
    "segments": [
      { "type": "text", "data": { "text": "Hello, world!" } }
    ],
    "friend": { "user_id": 123456789, "nickname": "Alice", "sex": "unknown", "qid": "", "remark": "", "category": { "category_id": 0, "category_name": "我的好友" } }
  }
}
```

**WS 上没有任何 API 调用能力**：不存在 `{"action":"send_msg","params":{...},"echo":"xxx"}` 这种报文，也不存在 `request_id`。文档对 WS 的全部描述只有「建立连接后推送事件」。若你的架构想「一条 WS 打通收发」，**必须**另外开 HTTP 客户端去 `POST /api/...`。

**心跳 / 超时**：协议**未规定**应用层心跳（无 ping/pong 报文约定，也无 `heartbeat` 字段/事件）。实践建议：

- 用 WebSocket 协议层 ping/pong（RFC 6455）保活，周期 30~60s；
- 读超时设 90s 左右，超时即重连；
- 重连采用指数退避，并接受**事件可能丢失**（协议无补发、无 seq 游标；要补齐历史只能调 `get_history_messages`）。

### 1.5 WebHook

**存在**，且与其它两种并列。语义：**协议端在事件发生时，主动 POST 到你配置的地址**（可以配置多个 WebHook 地址）。

    POST http://example.com/webhook
    Content-Type: application/json
    Authorization: Bearer {access_token}      # 协议端带上，应用端负责校验

    { ...与 WS 推送完全一致的事件 JSON... }

与「HTTP 上报」的区别：WebHook 方向是**协议端 → 应用端**，且**上报的内容只有事件，没有 API 调用**。API 调用永远是应用端 → 协议端 `POST /api/{api}`，方向相反。协议端配置了多个 WebHook 时，同一条事件会**重复投递到每一处** —— 你的 WebHook 端必须**幂等**（按 `(self_id, event_type, peer_id, message_seq)` 去重）。

WebHook 响应：文档未定义应用端必须返回什么；返回 2xx 即可，处理要快（耗时逻辑丢队列），否则会拖慢协议端。

### 1.6 三种推送方式的选型（PHP 视角）

| 方式 | 协议端要能 | 应用端要能 | 备注 |
|---|---|---|---|
| SSE | 长连接、`text/event-stream` | 常驻进程（PHP-FPM 下不可行） | 断线重连协议**未定义**游标，只能整体重连 |
| WebSocket | 支持 Upgrade | 常驻 + WS 客户端库（workerman / swoole / ratchet） | 延迟最低，推荐 |
| WebHook | 能出网 POST 到你的 URL | 有公网/内网可达的 HTTP 入口 | **最适合传统 PHP-FPM**：来一个请求处理一个事件，无需常驻 |

---

## 2. 事件（Event）

### 2.1 顶层结构（**所有** 21 类事件都一样，只有 4 个字段）

```jsonc
{
  "time": 1234567890,        // int64，事件 Unix 时间戳（秒）
  "self_id": 123456789,      // uint32，机器人 QQ 号
  "event_type": "message_receive",  // string，判别字段（discriminator）
  "data": { ... }            // object，按 event_type 决定结构
}
```

- 四个字段全部 **必填**。
- `time`：秒级整数，范围 0 ~ 9007199254740991（2^53-1）。
- `self_id`：**QQ 号类型 uint32**，范围 10001 ~ 4294967295。
- `data`：**永远是对象**（不会缺席），内容随 `event_type` 变。

### 2.2 分类：Milky **没有** OneBot11 的 post_type 四分法

不存在 `message` / `notice` / `request` / `meta_event` 四类，也**没有 `meta_event` 心跳事件**，没有 `post_type`、`sub_type`、`message_type` 字段。**21 个 `event_type` 平铺在同一层**，全部靠 `event_type` 字符串分派。

按语义大致可归为四组（仅供理解，**协议里没有这层分类**）：

| 语义组 | event_type |
|---|---|
| 生命周期 | `bot_offline` |
| 消息 | `message_receive`、`message_recall` |
| 请求（需审批） | `friend_request`、`group_join_request`、`group_invited_join_request`、`group_invitation` |
| 通知 | `peer_pin_change`、`friend_nudge`、`friend_file_upload`、`group_admin_change`、`group_essence_message_change`、`group_member_increase`、`group_member_decrease`、`group_disband`、`group_name_change`、`group_message_reaction`、`group_mute`、`group_whole_mute`、`group_nudge`、`group_file_upload` |

### 2.3 全部 21 个事件与 data 字段

| `event_type` | 中文名 | `data` 字段 |
|---|---|---|
| `bot_offline` | 机器人离线 | `reason`:string |
| `message_receive` | 消息接收 | → IncomingMessage 联合体（见 §2.4） |
| `message_recall` | 消息撤回 | `message_scene`:friend \| group \| temp; `peer_id`:uint32; `message_seq`:int64; `sender_id`:uint32; `operator_id`:uint32; `display_suffix`:string |
| `peer_pin_change` | 会话置顶变更 | `message_scene`:friend \| group \| temp; `peer_id`:uint32; `is_pinned`:bool |
| `friend_request` | 好友请求 | `initiator_id`:uint32; `initiator_uid`:string; `comment`:string; `via`:string |
| `group_join_request` | 入群请求 | `group_id`:uint32; `notification_seq`:int64; `is_filtered`:bool; `initiator_id`:uint32; `comment`:string |
| `group_invited_join_request` | 群成员邀请他人入群请求 | `group_id`:uint32; `notification_seq`:int64; `initiator_id`:uint32; `target_user_id`:uint32 |
| `group_invitation` | 他人邀请自身入群 | `group_id`:uint32; `invitation_seq`:int64; `initiator_id`:uint32; `source_group_id`:int64 \| null (可选) |
| `friend_nudge` | 好友戳一戳 | `user_id`:uint32; `is_self_send`:bool; `is_self_receive`:bool; `display_action`:string; `display_suffix`:string; `display_action_img_url`:string |
| `friend_file_upload` | 好友文件上传 | `user_id`:uint32; `file_id`:string; `file_name`:string; `file_size`:int64; `file_hash`:string; `is_self`:bool |
| `group_admin_change` | 群管理员变更 | `group_id`:uint32; `user_id`:uint32; `operator_id`:uint32; `is_set`:bool |
| `group_essence_message_change` | 群精华消息变更 | `group_id`:uint32; `message_seq`:int64; `operator_id`:uint32; `is_set`:bool |
| `group_member_increase` | 群成员增加 | `group_id`:uint32; `user_id`:uint32; `operator_id`:int64 \| null (可选); `invitor_id`:int64 \| null (可选) |
| `group_member_decrease` | 群成员减少 | `group_id`:uint32; `user_id`:uint32; `operator_id`:int64 \| null (可选) |
| `group_disband` | 群解散 | `group_id`:uint32; `operator_id`:uint32 |
| `group_name_change` | 群名称变更 | `group_id`:uint32; `new_group_name`:string; `operator_id`:uint32 |
| `group_message_reaction` | 群消息表情回应 | `group_id`:uint32; `user_id`:uint32; `message_seq`:int64; `face_id`:string; `reaction_type`:face \| emoji; `is_add`:bool |
| `group_mute` | 群禁言 | `group_id`:uint32; `user_id`:uint32; `operator_id`:uint32; `duration`:int64 |
| `group_whole_mute` | 群全体禁言 | `group_id`:uint32; `operator_id`:uint32; `is_mute`:bool |
| `group_nudge` | 群戳一戳 | `group_id`:uint32; `sender_id`:uint32; `receiver_id`:uint32; `display_action`:string; `display_suffix`:string; `display_action_img_url`:string |
| `group_file_upload` | 群文件上传 | `group_id`:uint32; `user_id`:uint32; `file_id`:string; `file_name`:string; `file_size`:int64 |

### 2.4 `message_receive` 的 data = IncomingMessage（三选一联合体）

判别字段：**`message_scene`**（`friend` | `group` | `temp`）。**注意：三个变体的字段都在 `data` 这一层，不是嵌套在 `message` 子对象里**。

**message_scene = `friend`** — 好友消息： `message_scene`:friend; `peer_id`:uint32; `message_seq`:int64; `sender_id`:uint32; `time`:int64; `segments`:IncomingSegment[]; `friend`:FriendEntity

**message_scene = `group`** — 群消息： `message_scene`:group; `peer_id`:uint32; `message_seq`:int64; `sender_id`:uint32; `time`:int64; `segments`:IncomingSegment[]; `group`:GroupEntity; `group_member`:GroupMemberEntity

**message_scene = `temp`** — 临时会话消息： `message_scene`:temp; `peer_id`:uint32; `message_seq`:int64; `sender_id`:uint32; `time`:int64; `segments`:IncomingSegment[]; `group`:GroupEntity \| null 可空

要点：

- `peer_id` 在 `friend`/`temp` 场景是**对方 QQ 号**，在 `group` 场景是**群号**；`sender_id` 恒为**发送者 QQ 号**（群里即成员 QQ 号）。
- `time`（消息时间）与顶层 `time`（事件时间）都在，别混用。
- 群消息额外带 `group`（GroupEntity）+ `group_member`（GroupMemberEntity）**完整对象**；好友消息带 `friend`。这意味着**收消息时不必再调 get_group_info**。
- `temp` 场景的 `group` 可空。

### 2.5 消息段（Segment）

**发送侧（OutgoingSegment，10 种）** —— 你构造的：

| `type` | 中文名 | `data` 字段 |
|---|---|---|
| `text` | 文本 | `text`:string |
| `mention` | 提及 | `user_id`:uint32 |
| `mention_all` | 提及全体 | `{}`（空对象） |
| `face` | 表情 | `face_id`:string; `is_large`:bool \| null 可选 |
| `reply` | 回复 | `message_seq`:int64 |
| `image` | 图片 | `uri`:string; `sub_type`:normal \| sticker \| null 可选; `summary`:string \| null 可选 |
| `record` | 语音 | `uri`:string |
| `video` | 视频 | `uri`:string; `thumb_uri`:string \| null 可选 |
| `forward` | 合并转发 | `messages`:OutgoingForwardedMessage[]; `title`:string \| null 可选; `preview`:string[] \| null 可选; `summary`:string \| null 可选; `prompt`:string \| null 可选 |
| `light_app` | 小程序 | `json_payload`:string |

**接收侧（IncomingSegment，14 种）** —— 你解析的：

| `type` | 中文名 | `data` 字段 |
|---|---|---|
| `text` | 文本 | `text`:string |
| `mention` | 提及 | `user_id`:uint32; `name`:string |
| `mention_all` | 提及全体 | `{}`（空对象） |
| `face` | 表情 | `face_id`:string; `is_large`:bool |
| `reply` | 回复 | `message_seq`:int64; `sender_id`:uint32; `sender_name`:string \| null 可空; `time`:int64; `segments`:IncomingSegment[] |
| `image` | 图片 | `resource_id`:string; `temp_url`:string; `width`:int64; `height`:int64; `summary`:string; `sub_type`:normal \| sticker |
| `record` | 语音 | `resource_id`:string; `temp_url`:string; `duration`:int64 |
| `video` | 视频 | `resource_id`:string; `temp_url`:string; `width`:int64; `height`:int64; `duration`:int64 |
| `file` | 文件 | `file_id`:string; `file_name`:string; `file_size`:int64; `file_hash`:string \| null 可空 |
| `forward` | 合并转发 | `forward_id`:string; `title`:string; `preview`:string[]; `summary`:string |
| `market_face` | 市场表情 | `emoji_package_id`:int64; `emoji_id`:string; `key`:string; `summary`:string; `url`:string |
| `light_app` | 小程序 | `app_name`:string; `json_payload`:string |
| `xml` | XML  | `service_id`:int64; `xml_payload`:string |
| `markdown` | Markdown  | `content`:string |

**两套不是同一套，别混用**：

- 发送图片/语音/视频传 **`uri`**（支持 `file://`、`http(s)://`、`base64://` 三种 scheme）；接收图片拿到的是 **`resource_id` + `temp_url`**。
- 发送 `reply` 只需 `message_seq`；接收 `reply` 会带回被引用消息的完整 `segments`（1.2 起）。
- 发送 `mention` 只需 `user_id`；接收还带 `name`（去掉 `@` 前缀）。
- `market_face` / `xml` / `markdown` **只能收不能发**（1.3 的 `markdown` 仅接收）。
- `mention_all` 两种都是 `data: {}`（空对象）。

### 2.6 其它实体（响应里反复出现的结构）

**FriendEntity** — 好友实体： `user_id`:uint32; `nickname`:string; `sex`:male \| female \| unknown; `qid`:string; `remark`:string; `category`:FriendCategoryEntity

**FriendCategoryEntity** — 好友分组实体： `category_id`:int64; `category_name`:string

**GroupEntity** — 群实体： `group_id`:uint32; `group_name`:string; `member_count`:int64; `max_member_count`:int64; `remark`:string; `created_time`:int64; `description`:string; `question`:string; `announcement`:string

**GroupMemberEntity** — 群成员实体： `user_id`:uint32; `nickname`:string; `sex`:male \| female \| unknown; `group_id`:uint32; `card`:string; `title`:string; `level`:int64; `role`:owner \| admin \| member; `join_time`:int64; `last_sent_time`:int64; `shut_up_end_time`:int64 \| null 可空

**GroupAnnouncementEntity** — 群公告实体： `group_id`:uint32; `announcement_id`:string; `user_id`:uint32; `time`:int64; `content`:string; `image_url`:string \| null 可空

**GroupFileEntity** — 群文件实体： `group_id`:uint32; `file_id`:string; `file_name`:string; `parent_folder_id`:string; `file_size`:int64; `uploaded_time`:int64; `expire_time`:int64 \| null 可空; `uploader_id`:uint32; `downloaded_times`:int64

**GroupFolderEntity** — 群文件夹实体： `group_id`:uint32; `folder_id`:string; `parent_folder_id`:string; `folder_name`:string; `created_time`:int64; `last_modified_time`:int64; `creator_id`:uint32; `file_count`:int64

**FriendRequest** — 好友请求实体： `time`:int64; `initiator_id`:uint32; `initiator_uid`:string; `target_user_id`:uint32; `target_user_uid`:string; `state`:pending \| accepted \| rejected \| ignored; `comment`:string; `via`:string; `is_filtered`:bool

**IncomingForwardedMessage** — 接收转发消息： `message_seq`:int64; `sender_name`:string; `avatar_url`:string; `time`:int64; `segments`:IncomingSegment[]

**GroupEssenceMessage** — 群精华消息： `group_id`:uint32; `message_seq`:int64; `message_time`:int64; `sender_id`:uint32; `sender_name`:string; `operator_id`:uint32; `operator_name`:string; `operation_time`:int64; `segments`:IncomingSegment[]

**群通知 GroupNotification**（`/api/get_group_notifications` 用，判别字段 `type`）：

**type = `join_request`** — 用户入群请求： `type`:join_request; `group_id`:uint32; `notification_seq`:int64; `is_filtered`:bool; `initiator_id`:uint32; `state`:pending \| accepted \| rejected \| ignored; `operator_id`:int64 \| null 可空; `comment`:string

**type = `admin_change`** — 群管理员变更通知： `type`:admin_change; `group_id`:uint32; `notification_seq`:int64; `target_user_id`:uint32; `is_set`:bool; `operator_id`:uint32

**type = `kick`** — 群成员被移除通知： `type`:kick; `group_id`:uint32; `notification_seq`:int64; `target_user_id`:uint32; `operator_id`:uint32

**type = `quit`** — 群成员退群通知： `type`:quit; `group_id`:uint32; `notification_seq`:int64; `target_user_id`:uint32

**type = `invited_join_request`** — 群成员邀请他人入群请求： `type`:invited_join_request; `group_id`:uint32; `notification_seq`:int64; `initiator_id`:uint32; `target_user_id`:uint32; `state`:pending \| accepted \| rejected \| ignored; `operator_id`:int64 \| null 可空

### 2.7 事件推送示例（三种方式同一份报文）

```json
{
  "time": 1234567890,
  "self_id": 123456789,
  "event_type": "message_receive",
  "data": {
    "message_scene": "group",
    "peer_id": 987654321,
    "message_seq": 23333,
    "sender_id": 111222333,
    "time": 1234567880,
    "segments": [
      { "type": "reply", "data": { "message_seq": 23300, "sender_id": 111222333, "sender_name": null, "time": 1234567800, "segments": [ { "type": "text", "data": { "text": "原消息" } } ] } },
      { "type": "mention", "data": { "user_id": 123456789, "name": "机器人" } },
      { "type": "text", "data": { "text": " 你好" } },
      { "type": "image", "data": { "resource_id": "AbCdEf123", "temp_url": "https://multimedia.nt.qq.com.cn/download?...", "width": 640, "height": 480, "summary": "[图片]", "sub_type": "normal" } },
      { "type": "face", "data": { "face_id": "14", "is_large": false } }
    ],
    "group": { "group_id": 987654321, "group_name": "测试群", "member_count": 42, "max_member_count": 200, "remark": "", "created_time": 1500000000, "description": "", "question": "", "announcement": "" },
    "group_member": { "user_id": 111222333, "nickname": "Bob", "sex": "male", "group_id": 987654321, "card": "鲍勃", "title": "", "level": 3, "role": "admin", "join_time": 1600000000, "last_sent_time": 1234567880, "shut_up_end_time": null }
  }
}
```

---

## 3. API 列表（65 个，全部 `POST /api/{name}`）

### 3.1 分类总表

| 分类 | 数量 |
|---|---|
| 系统 API | 17 |
| 消息 API | 9 |
| 好友 API | 6 |
| 群聊 API | 21 |
| 文件 API | 12 |

### 3.2 全量清单（请求字段 / 响应 `data` 字段）


#### 系统 API（17 个）

| API | 说明 | 请求字段 | 响应 `data` 字段 |
|---|---|---|---|
| `get_login_info` | 获取登录信息 | `{}`（无参数，仍须传空对象） | `uin`:uint32<br>`nickname`:string |
| `get_impl_info` | 获取协议端信息 | `{}`（无参数，仍须传空对象） | `impl_name`:string<br>`impl_version`:string<br>`qq_protocol_version`:string<br>`qq_protocol_type`:windows \| linux \| macos \| android_pad \| android_phone \| ipad \| iphone \| harmony \| watch<br>`milky_version`:string |
| `get_user_profile` | 获取用户个人信息 | `user_id`:uint32 | `nickname`:string<br>`qid`:string<br>`age`:int64<br>`sex`:male \| female \| unknown<br>`remark`:string<br>`bio`:string<br>`level`:int64<br>`country`:string<br>`city`:string<br>`school`:string |
| `get_friend_list` | 获取好友列表 | `no_cache`:bool（可选 =`false`） | `friends`:`FriendEntity`[] |
| `get_friend_info` | 获取好友信息 | `user_id`:uint32<br>`no_cache`:bool（可选 =`false`） | `friend`:`FriendEntity` |
| `get_group_list` | 获取群列表 | `no_cache`:bool（可选 =`false`） | `groups`:`GroupEntity`[] |
| `get_group_info` | 获取群信息 | `group_id`:uint32<br>`no_cache`:bool（可选 =`false`） | `group`:`GroupEntity` |
| `get_group_member_list` | 获取群成员列表 | `group_id`:uint32<br>`no_cache`:bool（可选 =`false`） | `members`:`GroupMemberEntity`[] |
| `get_group_member_info` | 获取群成员信息 | `group_id`:uint32<br>`user_id`:uint32<br>`no_cache`:bool（可选 =`false`） | `member`:`GroupMemberEntity` |
| `get_peer_pins` | 获取置顶的好友和群列表 | `{}`（无参数，仍须传空对象） | `friends`:`FriendEntity`[]<br>`groups`:`GroupEntity`[] |
| `set_peer_pin` | 设置好友或群的置顶状态 | `message_scene`:friend \| group \| temp<br>`peer_id`:uint32<br>`is_pinned`:bool（可选 =`true`） | `{}` |
| `set_avatar` | 设置 QQ 账号头像 | `uri`:string | `{}` |
| `set_nickname` | 设置 QQ 账号昵称 | `new_nickname`:string | `{}` |
| `set_bio` | 设置 QQ 账号个性签名 | `new_bio`:string | `{}` |
| `get_custom_face_url_list` | 获取自定义表情 URL 列表 | `{}`（无参数，仍须传空对象） | `urls`:string[] |
| `get_cookies` | 获取 Cookies | `domain`:string | `cookies`:string |
| `get_csrf_token` | 获取 CSRF Token | `{}`（无参数，仍须传空对象） | `csrf_token`:string |

#### 消息 API（9 个）

| API | 说明 | 请求字段 | 响应 `data` 字段 |
|---|---|---|---|
| `send_private_message` | 发送私聊消息 | `user_id`:uint32<br>`message`:`OutgoingSegment`[] | `message_seq`:int64<br>`time`:int64 |
| `send_group_message` | 发送群聊消息 | `group_id`:uint32<br>`message`:`OutgoingSegment`[] | `message_seq`:int64<br>`time`:int64 |
| `recall_private_message` | 撤回私聊消息 | `user_id`:uint32<br>`message_seq`:int64 | `{}` |
| `recall_group_message` | 撤回群聊消息 | `group_id`:uint32<br>`message_seq`:int64 | `{}` |
| `get_message` | 获取消息 | `message_scene`:friend \| group \| temp<br>`peer_id`:uint32<br>`message_seq`:int64 | `message`:`IncomingMessage` |
| `get_history_messages` | 获取历史消息列表 | `message_scene`:friend \| group \| temp<br>`peer_id`:uint32<br>`start_message_seq`:int64（可选）<br>`limit`:int64（可选 =`20`） | `messages`:`IncomingMessage`[]<br>`next_message_seq`:int64（可空） |
| `get_resource_temp_url` | 获取临时资源链接 | `resource_id`:string | `url`:string |
| `get_forwarded_messages` | 获取合并转发消息内容 | `forward_id`:string | `messages`:`IncomingForwardedMessage`[] |
| `mark_message_as_read` | 标记消息为已读 | `message_scene`:friend \| group \| temp<br>`peer_id`:uint32<br>`message_seq`:int64 | `{}` |

#### 好友 API（6 个）

| API | 说明 | 请求字段 | 响应 `data` 字段 |
|---|---|---|---|
| `send_friend_nudge` | 发送好友戳一戳 | `user_id`:uint32<br>`is_self`:bool（可选 =`false`） | `{}` |
| `send_profile_like` | 发送名片点赞 | `user_id`:uint32<br>`count`:int64（可选 =`1`） | `{}` |
| `delete_friend` | 删除好友 | `user_id`:uint32 | `{}` |
| `get_friend_requests` | 获取好友请求列表 | `limit`:int64（可选 =`20`）<br>`is_filtered`:bool（可选 =`false`） | `requests`:`FriendRequest`[] |
| `accept_friend_request` | 同意好友请求 | `initiator_uid`:string<br>`is_filtered`:bool（可选 =`false`） | `{}` |
| `reject_friend_request` | 拒绝好友请求 | `initiator_uid`:string<br>`is_filtered`:bool（可选 =`false`）<br>`reason`:string（可选） | `{}` |

#### 群聊 API（21 个）

| API | 说明 | 请求字段 | 响应 `data` 字段 |
|---|---|---|---|
| `set_group_name` | 设置群名称 | `group_id`:uint32<br>`new_group_name`:string | `{}` |
| `set_group_avatar` | 设置群头像 | `group_id`:uint32<br>`image_uri`:string | `{}` |
| `set_group_member_card` | 设置群名片 | `group_id`:uint32<br>`user_id`:uint32<br>`card`:string | `{}` |
| `set_group_member_special_title` | 设置群成员专属头衔 | `group_id`:uint32<br>`user_id`:uint32<br>`special_title`:string | `{}` |
| `set_group_member_admin` | 设置群管理员 | `group_id`:uint32<br>`user_id`:uint32<br>`is_set`:bool（可选 =`true`） | `{}` |
| `set_group_member_mute` | 设置群成员禁言 | `group_id`:uint32<br>`user_id`:uint32<br>`duration`:int64（可选 =`0`） | `{}` |
| `set_group_whole_mute` | 设置群全员禁言 | `group_id`:uint32<br>`is_mute`:bool（可选 =`true`） | `{}` |
| `kick_group_member` | 踢出群成员 | `group_id`:uint32<br>`user_id`:uint32<br>`reject_add_request`:bool（可选 =`false`） | `{}` |
| `get_group_announcements` | 获取群公告列表 | `group_id`:uint32 | `announcements`:`GroupAnnouncementEntity`[] |
| `send_group_announcement` | 发送群公告 | `group_id`:uint32<br>`content`:string<br>`image_uri`:string（可选） | `{}` |
| `delete_group_announcement` | 删除群公告 | `group_id`:uint32<br>`announcement_id`:string | `{}` |
| `get_group_essence_messages` | 获取群精华消息列表 | `group_id`:uint32<br>`page_index`:int64<br>`page_size`:int64 | `messages`:`GroupEssenceMessage`[]<br>`is_end`:bool |
| `set_group_essence_message` | 设置群精华消息 | `group_id`:uint32<br>`message_seq`:int64<br>`is_set`:bool（可选 =`true`） | `{}` |
| `quit_group` | 退出群 | `group_id`:uint32 | `{}` |
| `send_group_message_reaction` | 发送群消息表情回应 | `group_id`:uint32<br>`message_seq`:int64<br>`reaction`:string<br>`reaction_type`:face \| emoji（可选 =`"face"`）<br>`is_add`:bool（可选 =`true`） | `{}` |
| `send_group_nudge` | 发送群戳一戳 | `group_id`:uint32<br>`user_id`:uint32 | `{}` |
| `get_group_notifications` | 获取群通知列表 | `start_notification_seq`:int64（可选）<br>`is_filtered`:bool（可选 =`false`）<br>`limit`:int64（可选 =`20`） | `notifications`:`GroupNotification`[]<br>`next_notification_seq`:int64（可空） |
| `accept_group_request` | 同意入群/邀请他人入群请求 | `notification_seq`:int64<br>`notification_type`:join_request \| invited_join_request<br>`group_id`:uint32<br>`is_filtered`:bool（可选 =`false`） | `{}` |
| `reject_group_request` | 拒绝入群/邀请他人入群请求 | `notification_seq`:int64<br>`notification_type`:join_request \| invited_join_request<br>`group_id`:uint32<br>`is_filtered`:bool（可选 =`false`）<br>`reason`:string（可选） | `{}` |
| `accept_group_invitation` | 同意他人邀请自身入群 | `group_id`:uint32<br>`invitation_seq`:int64 | `{}` |
| `reject_group_invitation` | 拒绝他人邀请自身入群 | `group_id`:uint32<br>`invitation_seq`:int64 | `{}` |

#### 文件 API（12 个）

| API | 说明 | 请求字段 | 响应 `data` 字段 |
|---|---|---|---|
| `upload_private_file` | 上传私聊文件 | `user_id`:uint32<br>`file_uri`:string<br>`file_name`:string | `file_id`:string |
| `upload_group_file` | 上传群文件 | `group_id`:uint32<br>`parent_folder_id`:string（可选 =`"/"`）<br>`file_uri`:string<br>`file_name`:string | `file_id`:string |
| `get_private_file_download_url` | 获取私聊文件下载链接 | `user_id`:uint32<br>`file_id`:string<br>`file_hash`:string<br>`is_self_send`:bool（可选 =`false`） | `download_url`:string |
| `get_group_file_download_url` | 获取群文件下载链接 | `group_id`:uint32<br>`file_id`:string | `download_url`:string |
| `get_group_files` | 获取群文件列表 | `group_id`:uint32<br>`parent_folder_id`:string（可选 =`"/"`） | `files`:`GroupFileEntity`[]<br>`folders`:`GroupFolderEntity`[] |
| `move_group_file` | 移动群文件 | `group_id`:uint32<br>`file_id`:string<br>`parent_folder_id`:string（可选 =`"/"`）<br>`target_folder_id`:string（可选 =`"/"`） | `{}` |
| `rename_group_file` | 重命名群文件 | `group_id`:uint32<br>`file_id`:string<br>`parent_folder_id`:string（可选 =`"/"`）<br>`new_file_name`:string | `{}` |
| `delete_group_file` | 删除群文件 | `group_id`:uint32<br>`file_id`:string | `{}` |
| `persist_group_file` | 转存群文件为永久文件 | `group_id`:uint32<br>`file_id`:string | `{}` |
| `create_group_folder` | 创建群文件夹 | `group_id`:uint32<br>`folder_name`:string | `folder_id`:string |
| `rename_group_folder` | 重命名群文件夹 | `group_id`:uint32<br>`folder_id`:string<br>`new_folder_name`:string | `{}` |
| `delete_group_folder` | 删除群文件夹 | `group_id`:uint32<br>`folder_id`:string | `{}` |


### 3.3 最常用 API 详解（含报文）

#### send_private_message / send_group_message —— 发消息

见 §1.2 的 curl 示例。响应：

```json
{ "status": "ok", "retcode": 0, "data": { "message_seq": 23333, "time": 1234567890 } }
```

> **`message_seq` 的类型是 int64（整数）**，PHP 侧就是 `int`。这是发消息拿到的唯一句柄，后续撤回/引用/取消息全靠它。
> **Milky 没有 `message_id`**；也没有 `real_id`/`msg_id` 任何别名。要定位一条消息必须有 **(message_scene, peer_id, message_seq)** 三元组 —— `message_seq` 只在会话内唯一。

#### recall_private_message / recall_group_message —— 撤回

```http
POST /api/recall_group_message
{"group_id": 987654321, "message_seq": 23333}
```

响应 `data: {}`。

#### get_friend_list / get_group_list —— 好友 / 群列表

```http
POST /api/get_friend_list
{"no_cache": false}
```

```json
{ "status":"ok", "retcode":0, "data": { "friends": [ { "user_id": 123456789, "nickname": "Alice", "sex": "female", "qid": "abc123", "remark": "同事", "category": { "category_id": 0, "category_name": "我的好友" } } ] } }
```

```http
POST /api/get_group_list
{"no_cache": false}
```

```json
{ "status":"ok", "retcode":0, "data": { "groups": [ { "group_id": 987654321, "group_name": "测试群", "member_count": 42, "max_member_count": 200, "remark": "", "created_time": 1500000000, "description": "", "question": "", "announcement": "" } ] } }
```

> `no_cache` 是 bool，语义上给 `false` 最稳（OpenAPI 把它标成 nullable，个别协议端会严格校验）。

#### get_group_member_list / get_group_member_info —— 群成员

```http
POST /api/get_group_member_list
{"group_id": 987654321, "no_cache": false}
```

```json
{ "status":"ok", "retcode":0, "data": { "members": [ { "user_id": 111222333, "nickname": "Bob", "sex": "male", "group_id": 987654321, "card": "鲍勃", "title": "", "level": 3, "role": "admin", "join_time": 1600000000, "last_sent_time": 1234567880, "shut_up_end_time": null } ] } }
```

```http
POST /api/get_group_member_info
{"group_id": 987654321, "user_id": 111222333, "no_cache": false}
```

→ `data: { "member": GroupMemberEntity }`

#### upload_private_file / upload_group_file —— 上传文件

```http
POST /api/upload_group_file
{"group_id": 987654321, "parent_folder_id": "/", "file_uri": "base64://aGVsbG8=", "file_name": "hello.txt"}
```

```json
{ "status":"ok", "retcode":0, "data": { "file_id": "/a1b2c3-hello.txt" } }
```

- `file_uri` 同样支持 `file://`、`http(s)://`、`base64://`。
- `parent_folder_id` 可选，默认 **`"/"`**（根目录）。
- 返回的 `file_id` 是 **string**（不是数字），后续移动/重命名/删除/取下载链接都用它。

```http
POST /api/upload_private_file
{"user_id": 123456789, "file_uri": "file:///tmp/a.zip", "file_name": "a.zip"}
```

→ `data: { "file_id": "..." }`（私聊文件还需 `file_hash` 才能取下载链接，见 `get_private_file_download_url`）

#### get_history_messages —— 拉历史（补事件丢失的唯一手段）

```http
POST /api/get_history_messages
{"message_scene":"group","peer_id":987654321,"start_message_seq":23333,"limit":20}
```

响应 `data`: `messages`（IncomingMessage[]，**按 message_seq 升序**）、`next_message_seq`（可空，用于翻页）。`limit` 默认 20，**最多 30**。

#### 其它值得注意的

- `get_message`：`{message_scene, peer_id, message_seq}` → `data: { message: IncomingMessage }`
- `get_resource_temp_url`：`{resource_id}` → `data: { url }`（**图片/语音/视频的 `temp_url` 会过期，长期存储请存 `resource_id` 并靠这个 API 续链**）
- `get_forwarded_messages`：`{forward_id}` → `data: { messages: IncomingForwardedMessage[] }`
- `send_group_message_reaction`：`{group_id, message_seq, reaction, reaction_type("face"|"emoji", 默认 "face"), is_add(默认 true)}`
- `get_group_notifications`：分页拉群通知（`notification_seq` **降序**，序列号不连续）
- `set_group_member_mute`：`duration` 秒，0 = 取消禁言

---

## 4. 鉴权（access_token）

**只有一个机制**：协议端配置里的 `access_token`（字符串，留空 = 不鉴权）。协议端负责校验，应用端负责携带。

**携带方式（三处，格式统一）**

1. 请求头：`Authorization: Bearer {access_token}` —— **API 调用、SSE、WS 都用这个**。
2. query 参数：`?access_token={access_token}` —— 文档明确支持于**事件端点 `/event`**（"在不支持提供自定义 Header 的环境中"）。API 端点 `/api/...` 文档**只**描述了 header 方式，不要指望 `/api?access_token=` 一定被支持。
3. WebHook 方向反转：**协议端**在推送 POST 里带 `Authorization: Bearer {access_token}`，**你的 WebHook 端点必须自己校验**。

**校验语义**：完全匹配（字符串相等），不匹配或缺失 → `401`（事件端点则是拒绝握手 / 断开）。文档未定义 token 的编码、长度、时效；建议随机长字符串。

> PHP 落地：用 `hash_equals($expected, $given)` 比较，避免时序侧信道；从 `$_SERVER['HTTP_AUTHORIZATION']` 取（注意 Apache/CGI 下可能被吃掉，需 `SetEnvIf Authorization` 或 `getallheaders()`）。

**没配 access_token 时**：协议端不做校验，任何请求都放行 —— 这也是文档反复强调「必须部署在内网 / 用防火墙」的原因。

---

## 5. 与 OneBot 11 的关键差异（迁移对照表）

| 维度 | OneBot 11 | Milky 1.3 | 影响 |
|---|---|---|---|
| **传输模型** | 应用端跑 WS/HTTP 服务端，协议端反向连/上报（正向+反向 WS、http-post） | **协议端**开 HTTP 服务器，应用端连它；**只有 HTTP POST 调 API** | PHP 侧要写 HTTP 客户端，不是写接收端（WebHook 除外） |
| **API 调用报文** | WS 上 `{"action":"send_msg","params":{},"echo":"id"}`；HTTP 上 `POST /send_msg` | **WS 不能调 API**；只有 `POST /api/{api}`，**无 action、无 echo、无 request_id** | 不能复用 OneBot 的 WS 收发框架 |
| **API 命名** | `send_msg`、`get_group_member_list` | 全部 snake_case 且**带场景后缀**：`send_private_message` / `send_group_message`（**没有统一的 send_msg**） | 无 `message_type` 参数，私聊/群聊是两个端点 |
| **响应包** | `{"status":"ok","retcode":0,"data":{…},"echo":…}` | `{"status":"ok","retcode":0,"data":{…}}`，**无 echo**；失败带 `message` | 结构极像但少了 echo |
| **HTTP 状态码** | 通常恒 200 | 200 / 401 / 404 / 415 | 401/404/415 没有 JSON 信封，要单独处理 |
| **消息 ID** | `message_id`（int32，撤回时全局可用） | **无 message_id**，只有 `message_seq`（int64，**会话内唯一**） | 任何"按 id 撤回"逻辑都要带 `message_scene` + `peer_id` |
| **ID 类型** | int32/int64 混杂，可配置 string 形式 | **强类型**：QQ 号/群号 = uint32（10001..4294967295）；seq/time = int64；file_id / forward_id / resource_id / announcement_id / folder_id = **string** | PHP 别把 file_id 强转 int |
| **消息格式** | `message` 可为 CQ 码字符串**或**段数组；有 `raw_message`/`message_type`/`post_type` | **只有段数组**，接收字段叫 `segments`（不是 `message`），**无 CQ 码、无 raw_message** | 发送字段叫 `message`（OutgoingSegment[]），接收叫 `segments` —— 不对称，注意 |
| **段字段** | `{"type":"image","data":{"file":"…"}}` | 发送 `{"type":"image","data":{"uri":"…","sub_type":"normal","summary":…}}`；接收 `{"data":{"resource_id","temp_url","width","height","summary","sub_type"}}` | `file` → `uri`；多了 sub_type/summary |
| **at 段** | `at` + `qq` | `mention` + `user_id`；全体是 `mention_all` | 需改名映射 |
| **回复段** | `reply` + `id` | `reply` + `message_seq` | 且接收时 reply 内嵌完整被引用消息 |
| **事件分类** | `post_type` ∈ message/notice/request/meta_event，+ `message_type`/`notice_type`/`request_type`/`sub_type` | **无分层**，21 个 `event_type` 平铺；`data` 承载所有细节 | 分派逻辑重写；心跳事件**不存在** |
| **事件字段** | 顶层平铺（`user_id`、`group_id`、`message`…） | 全部收进 `data`；顶层只有 4 个字段 | 取字段路径要加 `.data` |
| **心跳/元事件** | `meta_event` + `heartbeat`/`lifecycle` | **没有**；只有 `bot_offline` | 在线检测只能靠"多久没收到事件" + 主动调 `get_login_info` 探活 |
| **鉴权** | `Authorization: Bearer` 或 `?access_token=`（各处都支持） | header 为主；query 仅文档明确给 `/event` | |
| **文件上传** | `upload_group_file`（`file` 参数，多为本地路径） | `upload_group_file`（`file_uri`，支持 file:// / http(s):// / base64://） | 参数名与 URI scheme 不同 |
| **兼容策略** | — | 协议端向后兼容、应用端**向前兼容**（见 §6） | 必须容忍未知字段/未知枚举 |

---

## 6. 联合类型的容错规则（**必读**，写 PHP 反序列化时照做）

官方兼容性约定（协议端向后兼容、应用端向前兼容），应用端遇到未知子类型时必须：

| 场景 | 规定行为 |
|---|---|
| 除消息事件以外的事件推送，遇到未知 `event_type` | **不得抛错**，忽略或打 warning |
| 消息事件推送 / 取消息类 API 响应中，遇到未知 segment `type` | **不得抛错**，降级成 `{"type":"text","data":{"text":"<应用端自定义的提示>"}}`（内容应体现"该消息段在当前 Milky 版本不受支持"） |
| 其它联合类型（如 `GroupNotification`）在**数组**里遇到未知子类型 | **跳过该元素**，不抛错 |
| 其它联合类型是**单个元素**时遇到未知子类型 | 可以抛错 |

同理，**未知字段一律保留/忽略，不要因为多了字段就报错**。这也是 1.2 引入 `ZRobustArray` 的动机。

---

## 7. PHP 实现要点（踩坑清单）

1. **空对象 `{}` vs 空数组 `[]`**：PHP 的 `json_encode([])` 输出 `[]`，而协议要求无返回值的 `data` 是 `{}`。用 `json_encode(['data' => new stdClass()])`；请求侧无参数时同样要发 `{}`（`json_encode(new stdClass())`）。
2. **不要用 HTTP 状态码判断成败**：200 可能是失败；401/404/415 才是真正的"调用方式错"。
3. **所有时间戳都是秒**，不是毫秒；`time` 在事件顶层、消息 data、各实体里都有，语义不同别串。
4. **int64 溢出**：`message_seq` 上界 2^53-1，PHP 64 位 int 安全；但**千万别**把 `file_id` 之类当数字（string，常含 `/`）。
5. **请求必须 `Content-Type: application/json`**，否则 415；curl 用 `CURLOPT_POSTFIELDS` 传**已编码的字符串**（传数组会变成 multipart，Content-Type 变成 `multipart/form-data`）。
6. **分派表驱动**：把 `event_type` → handler、`segment type` → 解析函数做成数组 map，未知键走 §6 的容错分支。
7. **WebHook 幂等 + 快返回**：先 200 再入队；按 `(self_id, event_type, peer_id, message_seq)` 去重（多 WebHook 会重复投递）。
8. **WS/SSE 必须常驻进程**（swoole / workerman / ReactPHP），PHP-FPM 下只能用 WebHook。
9. **资源链接会过期**：图片/语音/视频的 `temp_url` 是临时的，持久化请存 `resource_id`，用 `get_resource_temp_url` 换新链。
10. **撤销/引用要三元组**：`message_scene` + `peer_id` + `message_seq`，单靠 seq 会撤错会话。
11. **`no_cache` 建议显式传 `false`**，别依赖默认值（OpenAPI 标了 nullable，个别协议端会严格校验）。
12. **token 比较用 `hash_equals`**；CGI 环境下注意 `Authorization` 头可能丢失。

---

## 8. 复核路径（本文全部结论的来源）

| 内容 | 文件 |
|---|---|
| 传输方式、鉴权、SSE/WS/WebHook 报文与状态码 | `/storage/emulated/0/model/milky/docs/content/guide/communication.md` |
| 65 个 API 的路径、请求/响应 schema、BearerAuth、ApiResponse、Event oneOf | `/storage/emulated/0/model/.milky/openapi_openapi.json` |
| 21 个事件的 data 字段、14/10 种消息段、全部实体、整数范围约束 | `/storage/emulated/0/model/.milky/json-schema_schema.json` |
| 权威 IR 定义（字段注释、`since`、默认值、可空性） | `/storage/emulated/0/model/milky/protocol/src/ir/common.ts`、`protocol/src/ir/api/{system,message,friend,group,file}.ts` |
| TS 扁平类型（可直接对照命名） | `/storage/emulated/0/model/.milky/typescript_static.txt` |
| 版本/变更历史（1.1 / 1.2 / 1.3 各加了什么） | `/storage/emulated/0/model/milky/docs/content/changelog.md` |
| 兼容性与容错规则（§6） | `/storage/emulated/0/model/milky/docs/content/guide/compatibility.md` |
| 仓库文件树 | `/storage/emulated/0/model/.milky/tree.json` |
| 官方站点（对照用） | https://milky.ntqqrev.org/guide/communication |

> 说明：官方生成物把 `*_ZodInput` 变体（发送侧可省略默认值字段）单独生成了一份，本文已折叠，只保留"必填 / 可选 / 默认值"的语义，避免 PHP 侧被两份重复类型搞混。
