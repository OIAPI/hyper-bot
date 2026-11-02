# 协议扩展：架构与实施日志

> 用途：中途中断后可据此快速恢复上下文。每次完成一个阶段都追加一篇日志。

## 目标

在既有 **PHP-Swoole + OneBot11(webhook only)** 的 bot 框架上补齐：

1. OneBot11 **正向 WS**（框架作为客户端连接实现端）与 **反向 WS**（实现端连接框架）
2. **Milky** 协议支持（HTTP API + WebHook/WS/SSE 事件）
3. **QQ 官方机器人（QQ Bot API v2）** 支持（AccessToken、Webhook+Ed25519 验签、Gateway WS、发消息/富媒体）

## 关键设计决策

### 1. 内部统一事件模型 = OneBot11
所有协议的事件都被转换成 OneBot11 事件结构后交给既有 `Controller\Trigger\*`，
因此 7 个既有插件（Group/Help/Permission/Sys/Api/Common + Router）**零改动**即可在三种协议下工作。

### 2. API 调用统一走「协议桥」
`Api\app::get()/post()` 是最底层的 HTTP 出口，所有 `api/*.php` 都经由它。
改造后：

- 当前事件上下文（`Cache::$adapter`）存在时 → `Protocol\Core\Bridge::call(action, params)`
- 否则 → 原有 `{cq.api}/{action}` HTTP 调用（保持向后兼容）

这样 `$api->message->sendMsg(...)` 这类业务代码一行都不用改。

### 3. 协程上下文隔离（重要）
持久 WS 连接会并发处理多个事件，原 `Cache::$param/$adapter/$server` 是进程级静态变量，
直接在协程里共享会**串号**。因此在 `Cache` 中加入协程上下文层：

- `Swoole\Coroutine::getContext()` 存在时，reserved 键（param/server/http/api/replys/adapter）读写优先落协程上下文
- Webhook 单请求场景行为不变

### 4. 目录结构

```
protocol/
├── Core/            # 框架无关的抽象层
│   ├── Adapter.php          # 适配器基类
│   ├── HttpAdapter.php      # 传统 HTTP OneBot 适配器（基线）
│   ├── Bridge.php           # action → 适配器 的转发桥
│   ├── Config.php           # [protocol] 配置读取
│   ├── Context.php          # 当前适配器上下文
│   └── EventDispatcher.php  # 标准事件 → Trigger 控制器
├── OneBot/          # 正向/反向 WS
│   ├── WsHub.php            # WS 连接中枢（服务端 + 客户端 + echo 关联）
│   ├── Adapter.php          # WS 优先、HTTP 兜底
│   └── ...
├── Milky/           # Milky 协议
│   ├── Api.php              # HTTP API 客户端
│   ├── Converter.php        # Milky → OneBot11 事件/消息段
│   ├── EventClient.php      # WebHook/WS/SSE 事件接入 + 幂等去重
│   ├── Adapter.php          # OneBot11 action → Milky API
│   └── ...
└── QqBot/           # QQ 官方机器人
    ├── Token.php            # AccessToken 缓存与刷新
    ├── Webhook.php          # Ed25519 验签 + op=13 回调地址验证
    ├── Converter.php        # 官方事件 → OneBot11
    ├── EventClient.php      # Gateway WS（Hello/Identify/Heartbeat/Resume）
    ├── Adapter.php          # 官方 OpenAPI 调用
    └── ...
```

### 5. WebSocket 端点
Swoole 的 `HttpServer` 设置了 `open_websocket_protocol => true`，
`RequestHandler` 识别端点后调用 `Swoole\Http\Server::upgrade()` 完成握手：

| 协议 | 端点 | 方向 |
|---|---|---|
| OneBot 反向 WS | `/onebot/v11/ws` | 实现端 → 框架 |
| OneBot 正向 WS | 外部地址（配置项） | 框架 → 实现端 |
| Milky WS | `/milky/event`（可选，框架模拟协议端） | 外部 → 框架 |
| Milky WS/SSE 客户端 | `ws://{milky.api}/event` | 框架 → 协议端 |
| QQ 官方 Gateway | `wss://api.bot.qq.com/websocket/` | 框架 → 腾讯 |

## 进度

- [x] 阅读项目代码，摸清 HttpServer / RequestHandler / Trigger / Api / Cache / Utils
- [x] 协议核心抽象层（Core）
- [x] OneBot 正向/反向 WS
- [x] Milky（API/事件/转换/适配器）
- [x] QQ 官方机器人（Token/Webhook/Gateway/适配器）
- [x] Config.ini 配置段 + 协议状态路由
- [ ] 消息段历史字段（message_id 兼容）与文档
- [ ] 语法/静态检查与自查

## 踩坑记录

1. 本机没有 PHP 运行时（Termux 源无 php 包），只能用 `php-parser`（Node）做语法校验：
   `node .phpcheck/check.js <files...>`，注意 version 必须写 `'8.3'` 字符串。
2. Milky **没有 message_id**，只有会话内唯一的 `message_seq`；撤回必须带 `message_scene + peer_id`。
3. Milky WS **只能推事件不能调 API**，API 一律走 HTTP `POST /api/{name}`。
4. QQ 官方 Webhook 验签必须用**原始 body**，不能 json_decode 再 encode；公钥由 AppSecret 现算。
5. QQ 官方 `msg_seq` 与 `msg_id` 组合去重，重复发送会 40054005，必须递增。
