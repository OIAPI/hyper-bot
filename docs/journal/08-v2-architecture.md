# 08 — v2 架构落地：多运行架构、订阅内核、WebUI 可配

日期：2026-11-03
对应请求：「全部修复 1-5，优化旧代码适配新架构，可完全抛弃旧代码；写日志与文档；
检查运行时问题；写使用文档；写启动 bash 脚本；启动可选架构或 WebUI 配置架构；
消息订阅是否继承到新架构」

## 一、这次做了什么

把 v1 的「Swoole 单体 + 注解路由 + 全局 Cache 传参」重构成 v2 内核，**插件写法保持零改动**。

### 新增内核（core/）

| 文件 | 作用 | 替代 v1 的什么 |
|---|---|---|
| `core/Bot/Event.php` | 类型化事件对象 | `Cache::get('param')` 裸 stdClass |
| `core/Bot/Subscription.php` | 统一订阅过滤 + 可读拒绝原因 | 散在三处的 `[type]`/`[message]`/`[notice]` 判断 |
| `core/Bot/Context.php` | 事件上下文（协程隔离栈） | `Cache::set('param'/'adapter'/'server')` |
| `core/Bot/Registry.php` | 插件注册表（一次索引，热重载） | 每次分发反射扫描 |
| `core/Bot/Dispatcher.php` | 过滤→上下文→匹配→权限→执行 | `Controller\Trigger\*` 四件套 |
| `core/Runtime.php` | 运行架构选择 + 能力检测 + 降级说明 | 硬编码假设有 Swoole |
| `core/Scheduler.php` | 定时/延迟任务双实现 | `Utils\TaskManager` 硬编码 Timer |
| `core/Store/StoreBridge.php` | Redis 风格存储门面 | `new \Redis()` 硬依赖扩展 |
| `core/Bootstrap.php` | 启动引导 + 架构/配置一致性校验 | 各入口各写一份初始化 |
| `core/Version.php` | 版本单一来源 | 只存在于注释里 |
| `core/Http/AppRoutes.php` | 协议 webhook + 健康检查路由 | 长在旧注解路由里的协议端点 |

### 重写（保留 API 兼容）

- `utils/Cache.php` —— 保留键委托给 `Context`；去掉无界内存增长
- `utils/CoroutineCurl.php` —— 三驱动（swoole / curl / stream），**这是「没 Swoole 就跑不起来」的唯一硬阻塞点**
- `controller/Api.php` —— 去掉 per-CID 字典（内存泄漏 + 死代码 `getCacheKey()`）
- `app/BaseApp.php` —— 属性来源换成 Context/StoreBridge，插件零改动
- `protocol/Core/EventDispatcher.php` —— 改成委托 `Core\Bot\Dispatcher` 的兼容壳（5 个调用点不动）

### 新入口

- `bin/hyper` —— 统一 CLI：`serve` / `worker` / `doctor` / `status` / `config`
- `bin/hyper.sh` —— 进程管理：`start/stop/restart/status/logs/doctor`
- `public/index.php` —— 真正的应用 HTTP 入口（协议端点与 WebUI 同管线）

### WebUI 新增

- 后端：`RuntimeController`、`SubscriptionController`（路由从 37 → 47 条）
- 前端：`views/runtime.js`、`views/subscription.js`，侧栏新增两页

## 二、1-5 项修复的落点

| # | v1 问题 | v2 修法 |
|---|---|---|
| ① | `controller/Api.php` 内存泄漏 + 死代码 | 删 per-CID 字典与 `getCacheKey()`，改容器单例 + `Container::forget/forgetPrefix` |
| ② | 响应类型 stdClass/array 不一致 | `Bridge::shape()` 统一：关联数组→stdClass，列表保持数组 |
| ③ | 超时处理把「慢」当「没连上」 | `callApi()` 三态（array/false/null）+ 各协议显式 timeout |
| ④ | 插件热重载要重启 | `Registry::invalidate()` |
| ⑤ | 上下文散在全局 Cache | `Core\Bot\Context`（协程栈 + CLI 静态栈） |

## 三、运行时验证（本机实测）

环境：PHP 8.5.12 CLI，**无 ext-swoole / 无 ext-redis**。所以只能验证 php 与 webui 架构，
swoole 架构只做静态检查（这点如实记录，不夸大）。

| 验证项 | 结果 |
|---|---|
| 10 个 PHP 测试套件 | 全部通过 |
| `bin/hyper doctor` | 正常，正确识别缺 swoole/redis 并推荐 php |
| `bin/hyper help/status/config` | 正常 |
| php 架构启动 → health → webui → 停止 | 200 / 200 / 停止后 000 |
| webui 架构启动 → 协议 webhook | **503 明确拒绝**（见下方 bug） |
| 群消息事件 → 订阅接受 → 规则匹配 | `{"accepted":true,"matched":1}` |
| 私聊事件（scope=group） | `{"accepted":false,"reason":"消息范围仅群聊，当前 private"}` |
| WebUI 登录 + 新接口 runtime/subscription | 正常返回 |
| jsdom 前端 E2E（10 个视图含两个新页） | 全部通过 |

### 本轮实际发现并修掉的运行时 bug

1. **webui-only 架构静默吞事件**：协议路由先注册并返回 200，协议端会以为投递成功，
   实际没有任何业务执行——最坏的静默失败。修法：`AppRoutes::register($router, $acceptEvents)`，
   webui 模式下不注册协议路由，让请求落到 503 兜底。
2. **`stop` 停不掉服务**：子进程 `pcntl_exec` 后主进程的 `register_shutdown_function` 不生效，
   子进程变孤儿继续占端口。修法：显式接管 SIGTERM/SIGINT/SIGHUP，清理时先 TERM 后 KILL。
3. **PID 文件错位**：受限环境里 shell 的 `$!` 拿到外层 bash 的 PID。修法：由 PHP 进程自己写 `getmypid()`。
4. **`port_in_use` 恒返回 false**：安卓 bash 没有 `/dev/tcp`。修法：改用 `curl` 探活。
5. **`App\Plugins\Group` 的 `@name help`**（v1 复制粘贴 bug）：导致插件身份错乱、
   列表里出现两个 help。修法：改成 `@name group`。
6. **`Help.php` / `Sys.php` 残留 `use Swoole\...`**（死导入）：无 Swoole 时类加载即失败。修法：删掉。

## 四、关于「消息订阅是否继承」

**继承了，而且是刻意保留、并且做了升级。**

v1 的订阅判断散落三处（`EventDispatcher::allowedTypes()`、`Trigger\Message::validateBaseParam()`、
`Trigger\Notice::checkNoticeConfigSwitch()`），新增协议时容易漏。v2 合成 `Core\Bot\Subscription`。

**为什么保留它（不是历史包袱）：**

1. 订阅是**业务路由的第一层过滤**，不属于协议层——多个协议端可能同时喂同一个群，业务只关心子集；
2. 让不关心的事件在**最便宜的位置**丢弃：不落库、不跑插件、不调 API；
3. 每条丢弃都有**可读原因**（`rejectReason` 返回人话字符串），排查「插件为什么没触发」直接看日志；
4. 新协议产生大量业务无关事件（心跳、成员变更、机器人上下线），订阅是唯一止损点。

**兼容**：没写 `[subscription]` 时自动读 v1 的 `[type]`/`[message]`/`[notice]`，老部署零改动。

## 五、遗留 / 未验证

- **swoole 架构未做真实运行验证**（本机没有扩展）。WS 正向/反向、Gateway 常驻、
  `Swoole\Timer` 路径均为静态正确性 + 逻辑层测试，不能声称「已实测」。
- `controller/Trigger/*`、`controller/Router.php`、`utils/TaskManager.php`、`utils/Route.php`
  在 v2 已不被调用，但文件仍保留（兼容旧引用）。彻底删除建议放到下一轮，避免一次改动过大。
- `protocol/` 仍在用 `Utils\Env`/`Utils\MessageLog`，这两者已足够稳定，未强行迁移。
- PHP 架构下 `Scheduler` 只接受可序列化回调（PHP 闭包无法跨进程持久化），传闭包会被
  拒绝并记日志——这是 PHP 的固有限制，已在文档写明。

## 六、文件清单

新增 11 个内核文件 + 2 个入口 + 2 个 WebUI 控制器 + 2 个前端视图 + 3 篇文档。

文档：
- `docs/README-usage-v2.md` —— 使用文档（安装/启动/架构/协议/订阅/插件/FAQ）
- `docs/README-architecture-v2.md` —— 架构说明（分层/事件路径/为什么这样切）
- `docs/README-migration-v1-to-v2.md` —— 迁移指南（配置/插件/启动/回滚）
