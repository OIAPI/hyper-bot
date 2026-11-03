# v2 架构说明

> 面向维护者：v2 的分层、每个内核组件负责什么、为什么这样切。
> 使用方式看 [使用文档](README-usage-v2.md)，升级看 [迁移指南](README-migration-v1-to-v2.md)。

## 1. 一句话

v2 把 v1 的「Swoole 单体 + 注解路由 + 全局 Cache 传参」拆成：

```
协议适配层（OneBot11 / Milky / QQ官方）   ← 只负责「翻译」
        ↓ 统一事件
Core\Bot（Event / Subscription / Context / Registry / Dispatcher）  ← 业务内核
        ↓ 插件方法
app/Plugins/*                            ← 业务插件（写法与 v1 一致）
```

运行架构（swoole / php / webui）只是「怎么跑起来」，不影响上面三层。

## 2. 目录职责

| 路径 | 职责 |
|---|---|
| `core/Bot/Event.php` | 类型化事件对象（统一 OneBot11 事件模型） |
| `core/Bot/Subscription.php` | 事件订阅过滤（业务路由第一层） |
| `core/Bot/Context.php` | 事件上下文（替代 `Cache::set('param')`），协程隔离 |
| `core/Bot/Registry.php` | 插件注册表（扫描 + 注解索引，替代反射遍历） |
| `core/Bot/Dispatcher.php` | 分发器：过滤 → 上下文 → 匹配 → 权限 → 执行 |
| `core/Runtime.php` | 运行架构选择与能力检测 |
| `core/Scheduler.php` | 定时/延迟任务（Swoole Timer 或文件队列） |
| `core/Store/StoreBridge.php` | Redis 风格存储门面（不依赖 ext-redis） |
| `core/Bootstrap.php` | 启动引导 + 架构/配置一致性校验 |
| `core/Http/` | HTTP 内核（Router / Request / Response / Kernel） |
| `core/Http/AppRoutes.php` | 协议 webhook + 健康检查路由 |
| `core/App.php` | 容器与内核服务装配 |
| `protocol/` | 协议适配层（各协议目录自包含） |
| `app/Plugins/` | 业务插件 |
| `app/BaseApp.php` | 插件基类（属性注入） |
| `webui/` | WebUI 后端（路由 + 控制器） |
| `public/webui/` | WebUI 前端（无构建，原生 ESM） |
| `bin/hyper` | 统一 CLI 入口 |
| `bin/hyper.sh` | 进程管理脚本 |
| `controller/Server/HttpServer.php` | Swoole 架构的进程/事件宿主（HTTP 与 WS 都由它承载） |
| `controller/Api.php` | 插件 API 容器（`$this->api->message` 的解析入口） |
| `controller/Attr/` | 插件注解（Rule / Notice / Request） |
| `controller/Enum/` | 权限与业务枚举 |
| `api/` | 各业务域 API 实现（message / groupSetup / friend ...） |

## 3. 一次事件的完整路径

```
协议端（NapCat / Milky / QQ开放平台）
   │  ① 事件到达
   ├─ OneBot11 HTTP webhook → POST /api/protocol/webhook
   ├─ OneBot11 反向 WS     → protocol/OneBot/WsHub.php
   ├─ Milky webhook        → POST /api/protocol/milky/webhook
   ├─ Milky WS/SSE         → protocol/Milky/EventClient.php
   └─ QQ官方 webhook       → POST /api/protocol/qqbot/webhook

   │  ② 各协议适配器「翻译」成统一 OneBot11 事件
   ▼
Protocol\Core\EventDispatcher::dispatch($event, $adapter)
   │  （v2 里它只是一个兼容壳，真正干活的是下面）
   ├─ MessageLog 落库（审计，在订阅之前，为了能查「为什么没触发」）
   ▼
Core\Bot\Dispatcher::dispatch(Event, Adapter)
   │
   ├─ ① Subscription::rejectReason()   → 拒绝就到此为止（返回可读 reason）
   ├─ ② Context::run(...)              → 压栈，事件上下文对插件可见
   ├─ ③ Registry 匹配注解              → 用 raw_message / notice_type 匹配
   ├─ ④ 权限 & 群开关校验              → 不够权限跳过
   └─ ⑤ 执行插件方法                    → new $class($rule) 后调用，异常兜住
```

插件发消息时反过来：`$this->api->message->sendMsg()` → `Controller\Api` →
`Protocol\Core\Bridge::call($action, $params)` → 当前 `Context` 里的 `$adapter` →
对应协议的 `call()`。**插件完全不需要知道自己在哪个协议端上跑。**

## 4. 为什么这样切

### 4.1 订阅独立成一层（而不是散在 Trigger 里）

v1 的判断分散在三处：

- `EventDispatcher::allowedTypes()` 读 `[type]`
- `Trigger\Message::validateBaseParam()` 读 `[message] type`
- `Trigger\Notice::checkNoticeConfigSwitch()` 读 `[notice] {type}`

新增一个协议时，你得同时改三处，且漏一处就静默失效。
v2 合成 `Core\Bot\Subscription`，两个方法：`fromConfig()` 读、`rejectReason(Event)` 判。
好处是**拒绝原因是可读字符串**（"消息范围仅群聊，当前 private"），
直接打进日志、返回给 WebUI，排查时不用猜。

### 4.2 Context 取代全局 Cache

v1 用 `Cache::set('param', $event)` / `Cache::set('adapter', $adapter)` 传递上下文。
三个问题：

- 事件并发时**互相覆盖**（Swoole 下就是数据错乱）；
- 定时任务里 `Cache::get('param')` 是 null，`$this->input->sender` 直接崩；
- 没有「当前在哪个事件里」的概念，无法回溯。

v2 用 `Core\Bot\Context`：Swoole 协程上下文存栈，CLI 下退化为静态栈。
`Context::run()` 自动压栈/出栈，插件在 `new` 时从 `Context::current()` 拿到事件。

### 4.3 Registry 取代反射遍历

v1 每次分发都反射扫描 `app/Plugins` 找注解，插件热重载只能重启。
v2 建一次索引（`Registry::load()`），匹配时查表；`invalidate()` 支持热重载。

### 4.4 Runtime 抽象运行架构

v1 假设一定有 Swoole：`new \Swoole\WebSocket\Server`、`Timer::clearAll`、
`Coroutine::create` 散落各处，没装扩展直接 fatal，连提示都给不出。

v2 的 `Core\Runtime` 把「能力」与「架构」解耦：

```php
$runtime->handlesEvents()             // 是否处理机器人事件
$runtime->supportsWebSocketServer()   // 能否做 WS 服务端（反向 WS）
$runtime->supportsPersistentClients() // 能否常驻连接（正向 WS / Gateway）
$runtime->limitations()               // 人话描述的限制列表
```

启动时 `Bootstrap::validateRuntimeLimits()` 会拿配置去对：
「配了反向 WS 但没有 Swoole」→ 明确告警，而不是静默不生效。

## 5. 存储

`Core\Store` 三个驱动，按 `[store] driver` 选（`auto` 时优先 Redis，缺失降级文件）：

| 驱动 | 场景 |
|---|---|
| `RedisStore` | 有 ext-redis 时（多进程共享） |
| `FileStore` | 无 ext-redis（单机，原子写） |
| `MemoryStore` | 测试用 |

插件看到的是 `StoreBridge`——长得像 Redis（`get/set/setex/exists/del/keys/incr/zadd/...`），
所以 v1 插件里的 `$this->Redis->get(...)` 一行都不用改。

## 6. 定时任务

`Core\Scheduler`：

- Swoole 架构 → `Swoole\Timer`（毫秒精度）；
- PHP 架构 → 文件队列 `runtime/schedule.jsonl`，由主进程每秒轮询执行。

**已知限制**：PHP 架构下闭包无法跨进程持久化，所以延迟任务只接受可序列化回调
（`[Class, method]`）；传闭包会被**拒绝并记日志**，不是静默丢弃。

## 7. 测试

```bash
# PHP 逻辑层（10 个套件）
for t in protocol_test qqbot_sign event_flow milky_actions adapters \
         kernel_test webui_api_test webui_bridge_test outbound_test outbound_gaps; do
  php tests/$t.php
done

# 前端 E2E（需要运行中的服务 + jsdom）
WB_PASS=$(cat runtime/webui/initial-password.txt) node tests/webui_dom_test.mjs
```

测试不依赖 Swoole——所有内核逻辑都能在纯 PHP 下验证。
WebSocket 相关的**真实连接**行为无法在本机验证（没有扩展），
但协议层的翻译逻辑（签名、事件转换、action 映射）都有覆盖。

## 8. 与 v1 的取舍

保留：插件写法、注解语义、`$this->api` / `$this->Redis` 调用方式、权限等级编号、配置键。

### 已彻底删除的 v1 代码（无任何运行时引用）

| 已删除 | 替代者 | 删除依据 |
|---|---|---|
| `index.php` | `bin/hyper` | 旧入口，等价于 `bin/hyper serve --arch=swoole` |
| `bin/webui-serve.php` | `bin/hyper` | 旧 WebUI 入口 |
| `public/dev-server.php` | `public/index.php` | 旧开发入口 |
| `public/manager.html` | `public/webui/` | 旧管理页（1179 行，无引用） |
| `public/Api/App.php` | — | 空文件（0 行） |
| `app/Controller/{Api,Common,Protocol}.php` | `core/Http/AppRoutes.php` + WebUI | 协议端点与 WebUI 完全重复 |
| `app/BaseController.php` | `app/BaseApp.php` | 旧控制器基类 |
| `controller/Router.php` | `core/Bot/Registry` | 反射扫描 + 菜单 |
| `controller/Traits/{Worker,staticWorker}.php` | `core/Bot/Registry` | worker 列表 |
| `controller/Trigger/{Message,Notice,Request,Meta_event}.php` | `core/Bot/Dispatcher` | 分发逻辑（共 902 行） |
| `controller/Attr/{Route,RoutePrefix}.php` | `core/Http/Router` | 注解 HTTP 路由 |
| `controller/Server/RequestHandler.php` | `core/Http/Kernel` | v1 请求处理（swoole 已改走内核） |
| `utils/Route.php` | `core/Http/Router` | 注解路由实现 |
| `utils/TaskManager.php` | `core/Scheduler` | 硬编码 `Swoole\Timer` |
| `utils/{Worker,PluginManager}.php` | `core/Bot/Registry` | worker / 插件扫描 |
| `utils/{Yargs,Number,DeviceRegister}.php` | `bin/hyper` 内置解析 | 从未被调用（共 1558 行） |
| `api/uin.php` | — | 依赖 `global $api` + Redis，从未被调用 |
| `protocol/Core/Context.php` | `core/Bot/Context` | `Cache::set('adapter')` 的包装 |
| `protocol/WebhookRouter.php` | `Core\Bootstrap::bootProtocolClients` | 协议客户端启动 |
| `core/Http/RequestContext.php` | `core/Bot/Context` | 空壳（11 行，无引用） |
| `redis.sh` / `init-project.sh` | — | 部署辅助脚本（无引用） |

合计删除 **31 个文件、约 9000 行**。所有改动都在 git 历史里（提交 `38362a5` 之后的
删除提交），需要时可用 `git revert` 或从历史中取回。

### 因删除而做的迁移

| 位置 | 改动 |
|---|---|
| `controller/Server/HttpServer::onRequest` | 改为走 `Webui\Routes::kernel()`，与 php 架构同一管线 |
| `controller/Server/HttpServer::startProtocolClients` | 改为 `Core\Bootstrap::bootProtocolClients()` |
| `app/Plugins/Help.php` | `Router::scanAppClasses()` → `Registry::plugins()` |
| `webui/Controller/PluginsController.php` | `Router::clean()` → `Registry::invalidate()` |
| `webui/Controller/SystemController.php` | `Router::clean()` → `Registry::invalidate()` |
| `webui/Metrics.php` | 自己反射扫描 → 读 `Registry`（消除两份扫描漂移） |
| `protocol/Core/Bridge.php` | `Protocol\Core\Context::current()` → `Core\Bot\Context::adapter()` |
| `core/Bot/Context.php` | 新增 `bind()`（替代 v1 `Protocol\Core\Context::bind()`） |
| `composer.json` | `ext-swoole` 必装 → `suggest`（php 架构不装 Swoole 也能装依赖） |
