# 日志 04：协议能力补完（Milky 动作覆盖 / QQ 官方 API / 配置健壮性）

## 一、Milky：把「能用」推进到「够用」

之前 Milky 只映射了最常用的十几条动作，业务里一调 `_send_group_notice`、`set_group_leave`,
`get_essence_msg_list` 就直接 `-404`。这次按 `api/*.php` 的实际调用面统计，一共 94 个 action：

- **70 个**有 Milky 对应接口（新增约 30 条映射）
- **24 个**属于 Milky 协议确实没有的能力，现在通过 `Adapter::UNSUPPORTED` 常量**逐条给出原因**

典型新增：

| OneBot | Milky | 备注 |
|---|---|---|
| `_send_group_notice` / `_del_group_notice` | `send_group_announcement` / `delete_group_announcement` | 群公告 |
| `set_group_leave` / `quit_group` | `quit_group` | |
| `get_essence_msg_list` | `get_group_essence_messages` | **必须传 `page_index`/`page_size`，不是 `limit`** |
| `set_qq_profile` / `set_qq_avatar` / `set_group_portrait` | `set_nickname` / `set_avatar` / `set_group_avatar` | |
| `get_private_file_url` | `get_private_file_download_url` | |
| `fetch_custom_face` | `get_custom_face_url_list` | |
| `get_image` / `get_record` | `get_resource_temp_url` | 临时链接会过期 |
| `can_send_image` / `can_send_record` / `check_url_safely` / `clean_cache` | 本地 no-op | 协议端无此接口，返回成功避免业务报错 |

顺手修掉两个真实 bug：

1. `get_group_msg_history` 与 `get_friend_msg_history` 原来是**两段重复代码**，合并后统一走
   `get_history_messages` + `start_message_seq`；
2. `deriveSseUrl()` 原来写的是 `preg_replace('/^http/i', 'http', ...)` —— 恒等替换，
   等于什么都没做，现在改为基于 `milky.api` 正确拼接 `/event`。

另外 `handleEvent()` 里 `$key !== ''` 的判断原来缩进错乱且逻辑分散，现在把「空键不入表」明确成一层。

## 二、QQ 官方机器人：把 OpenAPI 补到实际可用

原来只有发消息 / 撤回 / 成员列表 / login，实际运营根本不够。这次对照 `qqbot-api-v2-docs/` 补齐：

```
群信息      GET  /v2/groups/{openid}/info
成员列表    GET  /v2/groups/{openid}/members            (cursor 分页)
成员详情    GET  /v2/groups/{openid}/members/{member}
批量踢人    POST /v2/groups/{openid}/batch_remove_members (≤20)
禁言        POST /v2/groups/{openid}/restrict_chat_setting
机器人群态  GET  /v2/groups/{openid}/bot_state
入群申请    GET  /v2/groups/{openid}/join_request_list
入群审批    POST /v2/groups/{openid}/approval_join_request/{member}
黑名单      GET/POST /v2/groups/{openid}/member_blacklist
菜单        GET/PUT  /v2/menu                            (仅单聊)
面板        GET/POST /v2/panels
分享链接    POST /v2/generate_url_link
```

两个语义坑：

- **禁言**：OneBot 用秒数 `duration`，官方要 RFC3339 的 `mute_expire_at`。转换时**钳到 30 天**
  （官方最大禁言时长），`duration = 0` 映射为 `op=del` 立即解除。
- **踢人**：官方没有「单个踢人」，只有批量接口，`user_id` 会包成单元素数组。

## 三、QQ 官方事件转换补全

新增：`C2C_MSG_RECEIVE`、`C2C_MSG_REJECT`、`GROUP_MSG_RECEIVE`、`GROUP_MSG_REJECT`、
`SUBSCRIBE_MESSAGE_STATUS`。

其中 `GROUP_ADD_ROBOT` / `GROUP_DEL_ROBOT` 之前被错误地映射成「群成员进出」——
但这两个事件的 `user_id` 其实是**机器人自己**，`op_member_openid` 才是操作人。
现在拆成 `botJoinGroup()` / `botLeaveGroup()`，`sub_type` 用 `invite` / `kick_me`，
这样插件里「我被踢了」的判断才不会被误判成「有人进群」。

## 四、Gateway 三处致命修正

1. **`op=9`（会话失效）原来只清空了 `session_id` 却不断开连接** ——
   连接会停在「已连上但未鉴权」的状态，此后永远收不到事件。现在主动 `reconnect()`，
   让外层重连循环走 Identify。
2. `waitHello()` 原来读不到 Hello 也返回 `45000`，于是 `Identify` 会发给一个没有 Hello 的连接，
   然后心跳空转。现在返回 `-1`，`gatewayLoop()` 直接断开重连。
3. `Identify` / `sendPayload` 原来返回 `void`，发送失败也无从得知。现在返回 `bool`，
   失败即断开，不再「假装已登录」。

顺带发现 `waitHello()` 里我一度写出了**连续两次 `recv()`**（第一次的结果被丢弃），
这会导致 Hello 包被吃掉一半。已删除。

## 五、配置健壮性

`Config.ini` 里所有 `#` 注释统一改成 `;`。PHP 的 `parse_ini_file` **只认 `;`**：
`#` 开头但含 `=` 的行会被当成键值对（例如 `; poke = 1` 一旦写回 `# poke = 1` 就会多出一个键），
含 `|` 的行则直接报 `unexpected '|'`。

`Protocol\Core\Config::get()` 现在同时接受 `protocol.qqbot.api` 与 `qqbot.api` 两种写法，
避免调用方因为前缀不一致而静默读到默认值。

## 六、测试面

`tests/` 下 5 个脚本，全部不依赖 Swoole / Redis：

```bash
php tests/protocol_test.php   # 事件转换 + 配置读取
php tests/qqbot_sign.php      # Ed25519 签名向量
php tests/event_flow.php      # Milky 幂等 + QQ 被动回复 msg_seq 状态机
php tests/milky_actions.php   # 94 个 action 的映射覆盖（70 有映射，其余必须有原因）
php tests/local.php           # 协程私有存储
```

`milky_actions.php` 会直接统计真实覆盖率，并在覆盖率跌破 70 时失败——
以后有人新增 `api/*.php` 调用却没补映射，CI 会立刻发现。

## 七、提交记录

```
deb3552 feat(milky): 补齐 action 映射覆盖率至 70/94，未支持项给出具体原因
5408bbf feat(qqbot): 补齐群信息/成员/禁言/入群审批/黑名单/菜单面板等官方 API
4ea6c7b fix(qqbot): op=9 断线重登、Hello/Identify 失败立即重连、事件转换补全
2cca04b feat(milky): 补全 group_disband / peer_pin_change 事件转换
8dbe85a fix(config): Config.ini 的 # 注释统一改为 ;
8713486 feat(config): Config::get 兼容带 protocol. 前缀的键名
```

## 八、仍然只能在真实环境验证的部分

本机没有 Swoole 扩展，以下只能靠静态检查 + 逻辑单测：

- WS 握手与 `onOpen/onMessage/onClose` 派发
- 正向 WS 的 `upgrade()/recv()/push()`
- QQ Gateway 的 Hello/Identify/Heartbeat 时序
- Milky SSE 流式解析

上线前按 `docs/README-protocol.md` 第七节逐项自测。
