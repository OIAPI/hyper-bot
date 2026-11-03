# WebUI 控制台

基于新内核（`Core\`）的可视化管理界面，位于 `/webui/`。

## 启动

架构默认是 **swoole**（常驻协程）：反向 WS、常驻 Gateway 客户端、多进程事件并行都只有这个架构支持。
优先级：命令行 `--arch` > 环境变量 `HYPER_ARCH` > `Config.ini [runtime] arch` > 默认 `swoole`。

没装 `ext-swoole` 的机器不会起不来——会自动降级成 php 内置服务器，并在启动横幅里写明
「未安装 ext-swoole，已降级为 php 内置服务器」。想显式用内置服务器或只跑面板，传
`--arch=php` / `--arch=webui`，或把 `runtime.arch` 配成 `php`。

### 独立运行（无需 Swoole，适合本地/救援）

```bash
bash bin/hyper.sh start --port=8092            # 推荐
# 或 php bin/hyper serve --arch=php --port=8092
# 或指定监听地址
bash bin/hyper.sh start --host=0.0.0.0 --port=8092
```

启动时会打印访问地址与账号信息：

```
  Hyper Bot WebUI
  ─────────────────────────────────────
  地址   http://127.0.0.1:8092/webui/
  存储   Core\Store\FileStore
  配置   /…/Config.ini
  账号   admin
  密码   xxxx（已写入 runtime/webui/initial-password.txt）
  ─────────────────────────────────────
```

**首次启动的密码是随机生成的**，不接受默认口令。文件写不进去时密码会直接打印在
控制台——务必先记下来再关窗口。登录后可在「配置 → 账号」里修改。

### 跟随主服务（Swoole）

```bash
php bin/start.php     # 或你常用的启动入口
```

`Webui\Bridge` 会在 `RequestHandler` 里接管 `/webui/*`，静态资源由 Swoole 的
`enable_static_handler`（`document_root = public`）直接返回。
访问 `http://<host>:<port>/webui/` 即可。

## 页面

| 页面 | 作用 |
|---|---|
| 概览 | 运行时、依赖、协议状态、最近事件 |
| 群管理 | 登记/编辑/移除群，单群插件开关 |
| 插件 | 插件清单、触发点、重新扫描 |
| 消息记录 | 按日期查看 `Utils\MessageLog` 落盘的消息 |
| 订阅 | 事件通道开关、消息范围、通知类型多选与全选、自定义订阅类型 |
| 协议接入 | OneBot11 / Milky / QQ 官方机器人 的配置与能力矩阵 |
| 日志 | 实时尾部 + 历史按日期，按级别过滤 |
| 配置 | Config.ini 的表单视图、原文视图、键值写回、账号 |
| 系统 | 目录体积、已注册路由、缓存清理 |

## 架构

前端是**无构建的原生 ES Modules**，没有 npm、没有 CDN，离线可用：

```
public/webui/
├── index.html
└── assets/
    ├── app.css            设计令牌 + 组件
    ├── main.js            持久化 shell + 路由 + 登录态
    ├── lib/{dom,api,ui}.js
    └── views/*.js         8 个视图 + 登录
```

- `lib/dom.js`：`h('div.card', props, children)` 直接建真实节点，无虚拟 DOM。
- `lib/api.js`：统一 fetch 封装，401 自动触发登出回调。
- `lib/ui.js`：Toast / Modal / 确认框 / 格式化。

## 消息订阅

订阅决定哪些上游事件进入框架。**默认全开**：全新部署（Config.ini 里三套订阅键都没有）时
message / notice / request / meta_event 全开，消息范围 all，通知勾选全部目录项 —— 装完就能收到事件，
不需要先去改 ini。

可订阅目录不再写死在 ini 里，由 core/Bot/SubscriptionCatalog.php 提供，三个来源：

| 来源 | 说明 |
|---|---|
| builtin | 内置 46 项，覆盖 OneBot 11 / Milky / QQ 官方转换器能产出的全部 notice_type |
| plugin | 插件类注释中的 @subscribe 声明，Registry 加载时自动收录 |
| custom | 面板「添加」按钮写入，持久化在 runtime/subscription-custom.json |

Config.ini 只保存「用户选中了哪些」（[subscription] 小节），不再承担「有哪些可订阅」的职责；
老部署里 v1 的 [type] / [message] / [notice] 仍然能读，但显式清空的 notice_types 不会被复活。

接口：

| 方法 | 路径 | 说明 |
|---|---|---|
| GET | /webui/api/subscription | 当前订阅 + 完整目录 |
| PUT | /webui/api/subscription | 保存选择结果 |
| GET | /webui/api/subscription/catalog | 目录（分组 / 来源 / 统计） |
| POST | /webui/api/subscription/catalog | 添加自定义类型，body: type / label / group |
| DELETE | /webui/api/subscription/catalog/{type} | 删除自定义类型（内置与插件的不可删） |
| GET | /webui/api/subscription/groups | 按群覆盖列表 |
| PUT | /webui/api/subscription/groups/{group} | 设置某个群的覆盖（mute / message / notice_types） |
| DELETE | /webui/api/subscription/groups/{group} | 删除某个群的覆盖 |

权限：读取需登录；增删需要 settings.write（owner / admin），viewer 返回 403。

插件声明写法（类注释，二选一：空格或竖线分隔）：

    @subscribeGroup 我的插件
    @subscribe my_event 我的事件

### 请求与元事件细分

message / notice 之外，request 与 meta_event 也可以按类型细分（默认不细分 = 全收）：

| 配置键 | 取值 | 说明 |
|---|---|---|
| subscription.request_types | friend / group | 为空表示所有请求都接收 |
| subscription.meta_event_types | lifecycle / heartbeat | 为空表示所有元事件都接收 |

注意这里的「空」与 notice_types 语义相反：notice_types 为空 = 全关（历史行为），
细分列表为空 = 不限制（因为整类开关已经打开，用户没勾就是不想被限制）。

### 按群覆盖

全局订阅之外，可以给单个群单独设置，落在 runtime/subscription-groups.json：

| 字段 | 说明 |
|---|---|
| mute | true = 该群任何事件都不推进框架（等价整群静音） |
| message | false = 该群的消息事件丢弃（通知仍按 notice_types 判定） |
| notice_types | 非空 = 该群只接收这些通知类型；空 = 跟随全局 |

判定顺序：通道开关 → 自身 / 机器人前缀 → 按群覆盖 → 消息范围 → 通知白名单。
群号只允许可打印字符（长度 <= 64），通知白名单必须是目录里已有的类型，未知类型返回 400。

### 架构提示

只有 swoole / php 架构会真正接收上游事件流；webui-only 架构下订阅配置照常保存，
但事件不会进来。面板会在订阅页顶部显示一条橙色提示，接口里对应字段是
arch / receives_events / arch_hint。

### 机器人前缀过滤

`ignore_robots` 打开时，`user_id` 以任一前缀开头的账号被视为官方机器人并丢弃。
`robot_prefix` 支持配置**多个**前缀：

    [subscription]
    robot_prefix = 3889,4000

- 分隔符：英文逗号、中文逗号、分号、空白都可以，例：`3889, 4000；5000`
- 保存时统一归一化为英文逗号形式并去重，配置文件里不会出现 `3889,,3889`
- 留空（`robot_prefix =`）= 不按前缀忽略，只保留 `ignore_self` 的自过滤
- 老配置里的单个 `3889` 行为不变

### 日志

| 类 | 文件 | 职责 |
|---|---|---|
| `Core\Log\Logger` | `runtime/log/<Y-M>/<Y-m-d>.jsonl` | 结构化日志（level / channel / context），带内存环形缓冲供面板实时读取 |
| `Utils\Logger` | `runtime/log/logger.log` | 终端彩色输出 + 文件追加，协程通道异步刷盘 |
| `Utils\MessageLog` | `runtime/message/Y/m/d/<type>.log` | 事件审计（JSON Lines），在订阅过滤**之前**写，被丢弃的事件也能查 |

三者的行为约束：

- **轮转**：`Utils\Logger` 单文件超过 8MB 轮转，只保留最近 5 个备份；
  `Utils\MessageLog` 单文件超过 32MB 轮转，保留最近 3 个。旧实现只增不删，日志目录会无限增长。
- **脱敏**：`MessageLog` 写入前递归屏蔽 password / token / secret / authorization / cookie 等键，值为 `******`。
- **截断**：单条消息超过 256KB 会被截断并标注 `[truncated N bytes]`，避免面板一次载入几 MB 的单行。
- **读取**：`MessageLog::read($date, $type, $limit)` 支持 `Ymd` 与 `Y-m-d`，非协程环境（php 架构）同样可用。
- 日志写失败**绝不影响业务**：所有写入路径都吞掉异常，只落到 `error_log`。
- **面板读两套**：JSONL 与 `logger.log`（含轮转备份）都读，纯文本按 `[时间] [级别] [通道] 内容` 解析后与 JSONL 合并、按时间倒序。只读 JSONL 会导致「业务日志一大堆，日志页永远空白」。
- **消息记录默认给最近有数据的那天**：不传日期时按 `runtime/message/*/*/*` 里最新的目录返回，并在响应里带 `date` 与 `dates`，避免打开页面就是空的。

## 常见故障：面板 500 / 登录页说没初始化管理员

先看日志里有没有这类行：

```
[app] http kernel exception {"path":"/webui/api/overview/counts","error":"protocol error, got '{' as reply type byte"}
```

这是 **Redis 连得上、但对端不是 Redis**（端口被 Nginx/面板占用、走了 HTTP 反向代理、host/port 配错）。旧版会因此每个请求都 500，连登录页都进不去。

- 2.1.5 起：存储层自动降级到文件存储（`runtime/store`），面板可继续用，系统页的「存储」会标注「主存储故障，已降级文件存储」
- 想彻底解决：`store.driver = file`，或把 `Redis.host / Redis.port` 改回真正的 Redis
- 自检：`php bin/hyper doctor` 会真的 PING 一次并说明「连上了但不是 Redis」

### Redis / KeyDB「明明能连，面板就是不用」

以前这种情况日志里一个字都没有：连接成功但 AUTH、SELECT、PING 任一步失败，代码静默回退文件存储。现在：

- 回退时会打一条 WARNING，写明具体哪一步失败以及原因
- `php bin/hyper redis` 逐步诊断，逐行告诉你卡在哪：

```
TCP 连接    OK
AUTH        FAIL  WRONGPASS invalid username-password pair  ← 密码与服务器 requirepass 不一致
```

常见原因：

| 现象 | 原因 |
|---|---|
| AUTH FAIL | `Config.ini` 的 `Redis.auth` 与服务器 `requirepass` 不一致 |
| 容器里设了密码却不生效 | 启动参数写成了 `--require pass xxx`，正确的是 `--requirepass xxx` |
| 没设密码却配了 auth | 旧版直接失败；现在会识别「no password is set」并继续用 |
| SELECT FAIL | `Redis.select` 超出库范围（默认只有 0-15）或该库被禁用 |
| PING FAIL | 端口上不是 Redis（被 nginx / 其他 HTTP 服务占用） |
| 诊断全 OK 但仍不用 | `Config.ini` 里 `[store] driver` 被写成了 `file` |

KeyDB 与 Redis 协议兼容，直接按 Redis 配置即可（`docker run ... keydb-server --requirepass 你的密码`）。

诊断命令最后会有一行 `面板存储`，直接告诉你内核实际用的是哪个：

```
面板存储   OK    Redis（Core\Store\RedisStore）  store.driver=auto
面板存储   OK    文件存储（runtime/store）  store.driver=file
```

看到「文件存储」而上面各步都 OK，说明 `[store] driver` 被固定成了 `file`；改回 `auto` 即可。

### 为什么会被「踢下线」

面板的登录态是服务端会话。2.1.6 起会话**默认存在本地文件**（`runtime/store/session`，配置项 `[webui] session_store = file`），不再跟业务缓存共用 Redis：

- 以前会话和缓存共用 Redis：Redis 重启、被 LRU 淘汰、或某次请求降级到文件存储，之前写进去的 token 就查不到 → 接口 401 → 前端跳登录页，看起来就是「莫名其妙被踢」
- 现在 Redis 抖动只影响缓存，会话还在；只有 12 小时有效期到了才会要求重新登录
- 存储后端异常时，会话校验失败会返回 **503（存储异常）** 而不是 401，前端不会把人踢回登录页
- 多机部署需要共享会话时，把 `session_store` 改回 `store`，并保证 Redis 稳定

### 日志一直刷「反向 WS 客户端断开」

这是**实现端在反复连你**，不是你在连它。框架的 WS 服务端一直监听 `/onebot/v11/ws`，只有当 `onebot.reverse.enable = true` 时才接受会话；关闭时会立刻断开并记录一条提示。

- 2.1.8 起：同一个来源每分钟最多一条 WARNING，其余降为 DEBUG，不再刷屏
- 想彻底不出现：在 OneBot 实现端把 WS 反向地址删掉（NapCat 的「WebSocket 服务端」、Lagrange 的 `--ws-reverse`），或者反过来把 `onebot.reverse.enable` 打开真正用它

### 能收消息，但发不出去

发消息走的是协议端的 **HTTP API**，和收消息的通道是两条路：

| 收 | 发 |
|---|---|
| 实现端 POST 到 `/onebot/v11/http`，或连反向 WS | 框架调 `{protocol.onebot.api}/send_group_msg` |
| 由 `protocol.onebot.reverse.enable` 控制 | 由 `protocol.onebot.api` + `token` 决定 |

所以「能收」只能证明事件通道通，发不出去通常是：

1. `[protocol.onebot] api` 没填或端口不对（默认 `http://127.0.0.1:3001`）
2. 实现端的 HTTP 服务没开，或 token 与 `[protocol.onebot] token` 不一致
3. 用的是旧配置里的 `[cq] api` —— 仍然支持，`cq.api` 会映射到 `protocol.onebot.api`（2.1.8 修正：之前误当成纯遗留隐藏了）

面板「连接」页选 OneBot，点 **测试发送**，会真的调一次 `get_login_info` 并显示：

```json
{ "ok": false, "via": "http", "api": "http://127.0.0.1:3001",
  "error": "HTTP 协议端调用失败：Connection refused",
  "hint": "协议端返回失败：检查 onebot.api 是否指向实现端的 HTTP 端口，以及 token 是否一致" }
```

看到 `Connection refused` 就是地址/端口不对；看到 `401` 是 token 不一致；成功则返回机器人的 QQ 号与昵称。

### 群列表有群号却没有群名

群名有两个来源：事件里带的 `group_name`，以及主动调用 `get_group_list`。以前只被动学习，没发过言的群就一直是空名字。

- 群管理页点 **同步群名**（`POST /webui/api/groups/sync`），框架会调 `get_group_list` 把名字写进 `webui:groups`
- 需要 `group.write` 权限；没有可用接口时会直接告诉你原因（没配 api、协议端拒绝等）

## 忘记密码（重置面板账号）

面板账号存在 `runtime/webui/users.json`，密码是 bcrypt 哈希。下面三种情况都会让「原来那个密码」失效：

1. 首次启动且 `webui.password` 为空 —— 系统生成随机密码，写在 `runtime/webui/initial-password.txt`；
2. 换了发布包并清空或替换了 `runtime/` 目录 —— 账号被重新初始化；
3. 用不同的工作目录启动 —— `__runtime__` 指向了别处的 users.json。

不用删文件，直接重置：

```bash
php bin/hyper passwd admin '你的新密码'   # 用户不存在会自动创建
php bin/hyper passwd --list               # 看有哪些账号、角色、绑定了哪些第三方
php bin/hyper passwd admin                # 不给密码则生成随机强密码并回显
```

登录页报「用户名或密码错误」时也会提示这条命令。重置后请到「账号设置」再改一次。

排查「明明重置了却登不上」，先确认两边看的是同一个文件：

```bash
php bin/hyper passwd --path                 # 账号库路径、是否存在/可读、属主、账号数
php bin/hyper passwd --verify admin '密码'  # 直接用 users.json 里的哈希校验，绕过网页
```

- `--verify` 返回「密码正确」但网页仍登不上 → 问题在会话/接口层，不在密码；
- `--path` 显示「存在但不可读」→ 属主或权限不对。CLI 用 root 建的文件是 `0600 root`，而 php-fpm 以 www 运行就读不到，此时面板会报「账号文件不可用」而不是「密码错误」。修法：`chown -R www:www runtime`（把 www 换成实际的 FPM 用户）。

**「每次重启密码都不一样」**：这是账号库不可读/不可写导致的——读不到就以为没有账号，于是在内存里重建 admin，密码只对当前进程有效，重启即换。`php bin/hyper passwd --path` 会同时打印「属主 / 可写 / 运行用户」，三者不一致（典型：CLI 用 root 建 0600，面板以 www 跑）就是它。修法：

```bash
chown -R www:www runtime        # www 换成 php-fpm 的实际用户
chmod 600 runtime/webui/users.json
```

账号目录不可写时，系统会直接报「账号文件写入失败」，不再静默生成临时账号。账号文件存在但没有账号（`[]`）时会先备份成 `users.json.bak-<时间>` 再重建，事后可查。

**首次启动会自动建号**：`users.json` 不存在时，第一个请求会创建 `admin`（owner），随机密码写入 `runtime/webui/initial-password.txt`（0600），启动横幅与日志会打印该路径。不想用随机密码就先跑 `php bin/hyper passwd admin '密码'`。

> 注意：`webui.password` 只在 **users.json 不存在**时用于初始化，之后改 Config.ini 不会改密码。
> 账号文件存在但内容损坏（不是合法 JSON）时，系统**不会**重建覆盖，而是报错让人去修，避免把原有账号和第三方绑定冲掉。

## 版本号

版本号只有一个来源：仓库根目录的 `VERSION` 文件。启动横幅、WebUI 系统页、`/webui/api/health` 都读它；发布包文件名也带上它，形如 `hyper-bot-v2.1.0-<short-sha>.zip`。

发版时只改 `VERSION` 一处（补丁号自增），`core/Version.php` 只保留一个兜底值。

## 安全

- 会话是**服务端存储**的，Cookie 为 `HttpOnly` + `SameSite=Lax`，JS 读不到 token。
- 路由**默认拒绝**：只有 login/status/health 公开，新增接口忘了加鉴权不会裸奔。
- 登录失败按 IP 限流（5 分钟内 10 次）。
- 配置回显全程脱敏：`token|secret|password|authorization|client_secret|access_token`。
- 账号存于 `runtime/webui/users.json`（bcrypt），权限 0600。

## 角色与权限

三角色：`owner`（主人）/ `admin`（管理员）/ `viewer`（只读）。能力表写在 `Webui\Auth::CAPABILITIES`，
「能力 -> 允许角色」只有一处定义，加能力不用翻控制器。

| 能力 | owner | admin | viewer |
| --- | :-: | :-: | :-: |
| view | ✓ | ✓ | ✓ |
| plugin.install / plugin.reload | ✓ | ✓ | |
| settings.write / protocol.write / group.write / system.cache | ✓ | ✓ | |
| runtime.write | ✓ | | |
| user.manage | ✓ | | |

- 控制器用 `Base::guard($request, 'plugin.install')` 兜底（返回 `null` 表示通过），真正的拦截在服务端。
- 角色**按 `users.json` 现查**而不是读会话：主人把人降级后，对方 12 小时的旧会话立刻失去写权限。
- 账号接口（仅 owner）：`GET/POST /webui/api/users`、`PATCH/DELETE /webui/api/users/{username}`、`POST /webui/api/users/{username}/password`。
- 升级兼容：老版本首个账号存的是 `admin`，`Auth::ensureOwner()` 启动时把最早创建的账号提升为 `owner`，避免面板永久失去账号管理能力。
- 前端 `GET /webui/api/auth/me` 返回 `{user, role, is_owner, permissions}`，`auth.can('plugin.install')` 只用来隐藏入口。

## 多账号

同一个协议可以挂多个机器人号，账号是独立文件 `runtime/accounts.json`（不再塞进 Config.ini）：

```json
{
  "accounts": [
    {
      "id": "onebot-3889", "name": "主号", "protocol": "onebot",
      "self_id": "3889012345", "enable": true,
      "api": "http://127.0.0.1:3000", "token": "…",
      "forward_url": "ws://127.0.0.1:6700", "remark": "线上主号"
    }
  ]
}
```

- **事件路由**：优先级 `?account=<id>` → `X-Account-Id` → `X-Self-Id` → 事件里的 `self_id`。
  命中后压栈 `Core\Bot\AccountContext`，协议层读 `onebot.api` / `milky.token` / `qqbot.forward.url`
  时自动拿到该账号的值，没命中才回落到 Config.ini 的全局配置。
- **常驻客户端**：每个协议只启动第一个启用账号的长连接，其余账号走事件推送（http webhook），
  避免同协议重复建连。日志里会写明「其余账号走事件推送接入」。
- **接口**（写操作需 `protocol.write`）：`GET/POST /webui/api/accounts`、`PUT/DELETE /webui/api/accounts/{id}`。
  响应永远不回显 token，只给 `token_set`；提交时 token 留空表示「不修改」。
- **升级兼容**：首次启动若没有 `accounts.json`，会按 Config.ini 里已配置的协议自动生成初始账号，
  老部署不需要手工迁移。
- **删除保护**：至少保留一个账号，避免面板把自己锁死。

## 第三方登录（OAuth）

面板支持用第三方平台登录，本质是「把第三方身份绑定到已有面板账号」：

| provider | 默认 scope | 说明 |
| --- | --- | --- |
| github | read:user | 用 `id` 做唯一标识，`login` 做展示名 |
| gitee | user_info | 同上 |
| linuxdo | openid profile | 同上 |

配置（Config.ini）：

```ini
[oauth]
; 不配就用「当前请求的 scheme://host + /webui/api/auth/oauth/{provider}/callback」
callback =

[oauth.github]
client_id = xxx
client_secret = xxx

[oauth.gitee]
client_id = xxx
client_secret = xxx
```

自建 OAuth2 服务也能接：在 `[oauth.自定义名]` 里补 `authorize_url` / `token_url` / `userinfo_url` /
`id_field` / `name_field` / `scope` 即可，provider 列表由代码里的内置表驱动。

流程：

1. 已登录用户在「账号」页点「绑定」→ `GET /webui/api/auth/oauth/{provider}/start?bind=1`（带签名 state 跳转）。
2. 第三方回调 `GET /webui/api/auth/oauth/{provider}/callback`（公开路由，浏览器此时还没有面板 token）。
3. 服务端换 token、取 userinfo，把 `sub` 写进 `users.json` 的 `bindings` 字段。
4. 以后未登录时点同一平台的「登录」，回调按 `provider + sub` 反查账号并直接建会话。

安全约束：

- state 用 HMAC-SHA256 签名并带 10 分钟有效期，防 CSRF / 重放；密钥存 `runtime/webui/oauth-secret.txt`（0600），
  也可用 `webui.oauth_secret` 指定。
- **不自动建号**：第三方身份没绑定过时只提示「请先用密码登录后绑定」，避免任意第三方账号点一下就拿到面板权限。
- 解绑：`DELETE /webui/api/auth/oauth/{provider}`；已绑定列表：`GET /webui/api/auth/oauth/providers`。

## 插件导入

WebUI 与聊天共用一个落盘器 `Webui\PluginInstaller`：

- WebUI：`POST /webui/api/plugins/import/zip`（raw body，`content-type: application/zip`，上限 32 MB）、
  `POST /webui/api/plugins/import/git`（`{url, branch?, name?}`），需要 `plugin.install`。
- 聊天（主人专用，`app/Plugins/PluginImport.php`）：`插件导入 git <url> [分支]`、`插件导入 zip <直链>`、
  `插件导入 列表`、`插件导入 重载`。

包落到 `runtime/github/<name>`，按 `composer.json` 的 `autoload.psr-4` 推导命名空间；入口类需与文件名同名、
位于 psr-4 根目录（Registry 只扫一层）。缺 composer.json 也能装，只是扫不到——接口用 `warnings` 告诉用户缺什么。

## 自检

```bash
php tests/webui_api_test.php      # 36 条路由的端到端行为
php tests/webui_bridge_test.php   # Swoole 桥接（桩对象）
php tests/kernel_test.php         # 内核各组件
```

## 部署

面板是「预构建静态产物 + PHP 接口」，生产机不需要 node：

1. **环境**：PHP ≥ 8.1，ext-json / mbstring / curl / openssl；先跑 `php bin/hyper doctor`。
2. **依赖**：`composer install --no-dev -o`（`vendor/` 不入库）。
3. **站点**：文档根指向 `public/`，非静态请求全部交给 `public/index.php`：
   ```bash
   php -S 0.0.0.0:8080 -t public public/index.php
   ```
   Nginx 等价写法：`try_files $uri /index.php?$query_string;`。
4. **静态**：`public/webui/` 就是构建产物（约 3.3 MB）。重新构建在 `ui/` 目录：`npm i && npm run build`。
5. **路径固定**：面板在 `/webui/`，接口在 `/webui/api`（前端写死 `BASE = '/webui/api'`）。
   反向代理若要加前缀，需要同时改前端与路由，不建议。
6. **可写**：`Config.ini` 与 `runtime/`（首次启动自动创建，含 users.json / 日志 / 缓存）。
7. **首次启动**：没有任何账号时，bootstrap 会把首个登录账号建为 owner；之后在「账号」页管理。
8. **可选扩展**：sodium（QQ 官方 Webhook 验签）、redis（可省，缺失走文件存储）、swoole（php 架构不需要）。
9. **自检**：`php bin/hyper doctor`、`php tests/webui_api_test.php`（67 项）。

## 启动脚本的适用环境

两个入口都不写死绝对路径（分别用 __DIR__ 与 BASH_SOURCE 推导项目根），整个目录可以搬到任意路径运行。
实测：把项目复制到 ~/relocate-test2 后执行 bin/hyper.sh start --port=8095，正常启动，/webui/ 302、/webui/api/health 200。

| 环境 | php bin/hyper | bin/hyper.sh |
|---|---|---|
| Linux（PHP ≥ 8.1 CLI） | 全部命令可用 | 需 bash + nohup |
| macOS | 可用 | 系统自带 bash 3.2 即可（未用 bash 4 语法） |
| Android / Termux | 可用 | 可用 |
| Docker / 容器 | 可用 | 可用 |
| Windows 原生 | help/status/doctor/config 可用，serve --arch=php 起不来 | 不可用 |

### 具体依赖

- php bin/hyper：PHP ≥ 8.1 CLI（json / mbstring / openssl / curl）。起服务需要能创建子进程：
  有 pcntl 走 pcntl_fork + pcntl_exec('/bin/sh', …)，否则退回 shell_exec + bash -c nohup。
  两者都没有（典型是 Windows 原生、缺 /bin/sh）时会提示「启动内置服务器失败」，并打印可手动执行的命令。
- bin/hyper.sh：bash 3.2+、nohup、seq、head、grep、awk、kill；端口检测按 ss → netstat → curl 逐级降级，都缺也能跑。
  PHP 查找顺序：$HYPER_PHP → 项目自带 .phpext/php → PATH 里的 php。
- 可写目录：runtime/（PID、日志、缓存）。vendor/ 缺失时启动会直接提示 composer install --no-dev -o，不再静默退出。
- 多实例：PID 与日志按端口命名（runtime/hyper-<port>.pid），不同端口可并存。
- git 模式：bin/hyper 与 bin/hyper.sh 已置 100755，clone 到普通文件系统后可直接 ./bin/hyper serve；
  在 /sdcard 这类忽略执行位的挂载上改用 php bin/hyper 或 bash bin/hyper.sh。

### Windows 上的建议

用 WSL2 或 Docker 跑 Linux 容器；Windows 原生缺 /bin/sh 与 bash，serve 的子进程创建会失败（help、status、doctor 仍可用）。

## 一键准备环境（php bin/hyper install）

刚 clone 出来的目录没有 `vendor/`，这条命令把环境一次准备好；而**发布包（zip）里已经带了 vendor/**，
这时它是幂等的「体检 + 补齐」：检查扩展、确认运行时目录与 Config 可写，发现依赖已就绪就跳过下载。
所以重复执行只会打印「环境就绪：依赖已就绪（未重复下载）」——这是正常的，不是没生效。

    php bin/hyper install                      # 扩展检查 + composer install --no-dev -o + 建 runtime 目录
    php bin/hyper install --dev                # 连 dev 依赖一起装
    php bin/hyper install --force              # vendor 已存在也重装（真正会去下载依赖）
    php bin/hyper install --download-composer  # 没装 composer 时自动下载 composer.phar
    bash bin/hyper.sh install                  # 同样的能力，走 bash 入口（可带 --dev/--force/--download-composer）

行为：

- 扩展分两档：json / mbstring / curl / openssl 缺失直接 FAIL 并 exit 1；swoole / sodium / redis 缺失只记 WARN（可选）。
- 自动创建并检查 runtime/、runtime/log、runtime/store、runtime/webui、runtime/github 可写，检查 Config.ini 可写。
- vendor/autoload.php 已存在则跳过 composer（除非 --force），并在结尾说明「依赖已就绪（未重复下载）」。
- composer 查找顺序：`$COMPOSER_BINARY` > 项目内 composer.phar > PATH 里的 composer；composer.phar 用当前 PHP 执行，PATH 里的 shim 直接执行。
- 命令本身不依赖 autoload，vendor 还没装也能跑（普通命令则仍会提示「缺少依赖」）。
- 必需项全过 exit 0，可以直接接着 php bin/hyper serve（默认 swoole）。

与 doctor 的区别：doctor 只体检、不改动任何东西，把「必需项失败（FAIL，exit 1）」和「可选扩展未装（WARN，不影响启动）」分开显示；
install 会真的装依赖、建目录，并在结尾如实报告这次「装了什么 / 跳过了什么」。