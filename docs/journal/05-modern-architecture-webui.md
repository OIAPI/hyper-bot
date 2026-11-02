# 日志 05：现代化分层架构 + 完整 WebUI

日期：2026-11-03

## 起点

上一阶段（日志 01–04）完成了协议层：OneBot11 正向/反向 WS、Milky、QQ 官方机器人，
但它们都还挂在旧的「注解路由 + 协程私有上下文」请求模型上，且没有任何可视化界面。
本阶段的目标是**架构现代化 + 完整 WebUI**，同时不破坏已经在跑的协议行为。

## 做了什么

### 1. 内核层（与 Swoole 解耦）

新增 `core/` 命名空间，全部不 import Swoole：

| 类 | 职责 |
|---|---|
| `Core\Config` | 唯一 INI 解析器，扁平化 `[protocol.qqbot]` 段名，支持读写与脱敏 |
| `Core\Container` | 极简 DI 容器（bind/singleton/get） |
| `Core\App` | 装配器：配置 → 日志 → 路由 → 存储，绑定到容器 |
| `Core\Http\Request` | 不可变请求值对象，`fromSwoole()` 是唯一适配点 |
| `Core\Http\Response` | 不可变响应（json/html/redirect/text/cookie） |
| `Core\Http\Router` | 模式路由，支持 `{param}` 与历史 `:param`、分组、中间件、命名 |
| `Core\Http\Kernel` | 中间件归约 + 404/405/500 兜底 |
| `Core\Http\MiddlewareAuthMiddleware` | 服务端会话、HttpOnly Cookie、滑动续期 |
| `Core\Log\Logger` + Handler | 结构化 JSONL 日志，环形缓冲，密钥脱敏 |
| `Core\Store\{Store,Memory,File,Redis}` | 统一存储接口（get/set/incr/zadd/zrange…） |
| `Core\Fs` | 跨平台文件写入（见下） |

旧类 `Utils\Env`、`Protocol\Core\Config` 改为**委托**到 `Core\Config`，
保留原 API，因此所有既有调用点一行都不用改。

### 2. 一个真实的坑：Android FUSE 不支持 flock

`file_put_contents($f, $data, LOCK_EX)` 在 /storage/emulated 上**静默返回 false**。
表现是：日志写不进去、首次启动的初始密码文件是 0 字节、用户永远登不进来。

排查过程：先怀疑权限，但 `echo test > file` 成功；单独测 `LOCK_EX` 得到 `bool(false)`，
而不带锁的写入正常。于是写了 `Core\Fs`：优先带锁写，失败自动降级无锁写，
再失败才报错；同时提供「临时文件 + rename」的原子写。所有写入点（日志、密码、
配置、文件存储、消息日志）统一改走它。

顺带修了另一个静默失败：密码文件写不进去时，原实现会把密码吞掉。
现在 `Auth::bootstrap()` 会把密码回传给启动器，直接打印在控制台。

### 3. WebUI 后端

`webui/` 下 9 个控制器，共 36 条路由，全部挂在 `/webui/api`：

- 公开：`POST /auth/login`、`GET /auth/status`、`GET /health`
- 其余**默认拒绝**，统一过 `webui.auth`

端点覆盖：认证、概览、群（含单群插件开关）、插件、协议（含能力矩阵）、
消息、日志（实时 + 历史）、配置（表单 + 原文 + 写回）、系统（体积/路由/缓存）。

### 4. WebUI 前端（无构建、离线可用）

`public/webui/`：原生 ES Modules，手写 CSS 设计令牌（亮/暗主题），
零依赖、零 CDN、零构建步骤。8 个视图 + 登录页。

一个设计决定：**布局只构建一次**。最初每页都重建整个 shell，
结果异步视图写回了已经被替换掉的容器，表现为「切页后内容空白」。
改成持久化 shell + 只替换 `.content` 后问题消失。

### 5. 接进生产入口

新增 `Webui\Bridge`，在 `RequestHandler::handle()` 最前面分流：
`/webui/*` 交给内核管线，其余原样走旧逻辑。用桩对象覆盖了这条链路
（本机无 swoole 扩展），验证了 401、Cookie、重定向与放行。

## 验证

```
LINT_FAIL=0
protocol_test / qqbot_sign / event_flow / milky_actions / adapters   PASS
kernel_test / webui_api_test / webui_bridge_test                     PASS
```

浏览器实测（`php bin/webui-serve.php --port=8092`）：登录成功，
8 个页面全部渲染出预期内容。

## 遗留

- 本机无 swoole/redis 扩展，WebSocket 与 Redis 路径只能静态推演。
- 日志页的 SSE 实时推送未做（当前是 5 秒轮询），Swoole 环境下可以再加。
