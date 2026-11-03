# 09 — 清除 v1 残留：删除 31 个文件，只保留 v2

日期：2026-11-03
对应请求：「去除旧的无用的程序残留，只保留 v2 代码」

## 一、为什么这次删除需要谨慎

上一轮（日志 08）把 v2 内核建好了，但 v1 的代码**文件还在**。直接 `rm` 是危险的：
这个项目的插件/API/路由大量使用**动态调用**，静态 grep 会漏判：

| 动态机制 | 例子 | 静态分析的误判 |
|---|---|---|
| `Controller\Api::resolve()` | `'Api\\' . $apiName` 再 `new` | `Api\message` 等全部被判"无引用" |
| `#[Rule] / #[Notice]` 注解 | 注解里只有正则字符串 | 插件方法被判"无引用" |
| 字符串类名 | `'App\\Plugins\\' . $name` | 同上 |

所以流程是：**先做依赖分析 → 证明无引用 → 迁移真实依赖 → 再删**。

## 二、依赖分析怎么做

写了两个脚本（`runtime/`，已忽略提交）：

1. `deadcode.php` —— 正向：哪些类定义没有任何引用
2. `deps2.php` —— 反向：删掉 X 之后，**存活代码里还有谁引用它**

第一版用「短名匹配」，噪声极大（`Router` 既可能是 `Controller\Router` 也可能是
`core/Http/Router`）。第二版改成**按 PHP 命名空间规则精确解析**：
解析每个文件的 `namespace` + `use` 别名表，然后判断
「use 别名指向它 / 全名出现 / 同命名空间短名」三者之一才算真引用。

结果：31 个待删文件里，**只有 5 个有真实反向依赖**。其余全是纯死代码。

## 三、真实反向依赖与迁移

| 依赖点 | 原代码 | 改为 |
|---|---|---|
| `app/Plugins/Help.php` | `Router::scanAppClasses()` + `Router::$appDocs` | `Registry::plugins()` |
| `webui/Controller/PluginsController.php` | `Router::clean()` | `Registry::invalidate()` |
| `webui/Controller/SystemController.php` | `Router::clean()` | `Registry::invalidate()` |
| `webui/Metrics.php` | 自己 `new ReflectionClass` 扫一遍 | 直接读 `Registry` 的结果 |
| `controller/Server/HttpServer.php` | `new RequestHandler(...)` | `Webui\Routes::kernel()->handle()` |
| `controller/Server/HttpServer.php` | `Protocol\WebhookRouter::boot()` | `Core\Bootstrap::bootProtocolClients()` |
| `protocol/Core/Bridge.php` | `Protocol\Core\Context::current()` | `Core\Bot\Context::adapter()` |

另外 `api/message.php` 的 `uin` 是**误报**——它只是参数名 `$uin`，不是 `Api\uin` 类。

## 四、这次顺带修掉的真 bug

### 1. swoole 架构跑的是 v1 老 HTTP 栈

这是本轮最重要的发现。

`HttpServer::onRequest` 原本先试 `Webui\Bridge::handle()`，**不匹配**就走
`Route::match()` → 命中 `App\Controller\Protocol` 的注解路由 → 回到 v1 的
`Trigger\*` 分发。也就是说：

- php 架构：走 v2 内核（订阅 + Dispatcher）
- swoole 架构：走 v1 注解路由 + Trigger

**两种架构的事件处理逻辑根本不一样**。而 `App\Controller\Protocol` 的协议端点
和 v2 的 `AppRoutes` 又完全重复——同一件事有两份实现，必然漂移。

修法：`onRequest` 直接走 `Webui\Routes::kernel()`，与 php 架构同一管线。
顺手删掉了一个**永远不可达**的分支：

```php
if ($this->isWebSocketEndpoint($path)) {
    \Protocol\OneBot\WsHub::handleUpgrade($request, $response, $path);   // 这个方法不存在
}
```

`open_websocket_protocol = true` 时 Swoole 完成握手后直接派发 `open` 事件，
`onRequest` 根本收不到 WS 升级请求。这个分支调用的 `handleUpgrade()` 也从来没实现过——
真被走到就是 `Call to undefined method` 致命错误。

### 2. `Context::current()` 与 `Context::adapter()` 可能读到不同对象

`Core\Bot\Context` 里有**两份状态**：静态属性 `self::$current` 和栈。`current()`
优先读静态属性，`adapter()` 读 `current()`——但 `bind()` 只写栈不写属性时两者就分叉。
测试里表现为「绑定成功但读不到」。

修法：栈作为**唯一真相源**，`current()` 只读栈顶，`$current` 仅在 `run()` 退出时
作为缓存同步一次。

### 3. `composer.json` 强制要求 `ext-swoole`

```json
"require": { "ext-swoole": ">=4.8" }
```

这意味着**没有 Swoole 的机器连 `composer install` 都装不上**，
而 v2 的核心卖点就是「php 架构不需要 Swoole」。自相矛盾。
改为 `suggest`，并补上真正必需的 `ext-json` / `ext-mbstring` / `ext-curl`。

## 五、删除清单（31 个文件，约 9000 行）

| 类别 | 文件 | 行数 |
|---|---|---|
| 旧入口 | `index.php`、`bin/webui-serve.php`、`public/dev-server.php` | 123 |
| 旧页面 | `public/manager.html` | 1179 |
| 旧控制器 | `app/Controller/{Api,Common,Protocol}.php`、`app/BaseController.php` | 217 |
| 插件注册 | `controller/Router.php`、`controller/Traits/{Worker,staticWorker}.php` | 491 |
| 事件分发 | `controller/Trigger/{Message,Notice,Request,Meta_event}.php` | 902 |
| 注解路由 | `controller/Attr/{Route,RoutePrefix}.php`、`utils/Route.php` | 335 |
| 请求处理 | `controller/Server/RequestHandler.php` | 385 |
| 定时任务 | `utils/TaskManager.php` | 495 |
| 插件扫描 | `utils/{Worker,PluginManager}.php` | 376 |
| 从未调用 | `utils/{Yargs,Number,DeviceRegister}.php` | 1558 |
| 死 API | `api/uin.php` | 27 |
| 空壳 | `public/Api/App.php`、`core/Http/RequestContext.php` | 11 |
| 旧上下文 | `protocol/Core/Context.php` | 26 |
| 协议启动 | `protocol/WebhookRouter.php` | 64 |
| 部署脚本 | `redis.sh`、`init-project.sh` | 161 |

删除前已全部备份到 `backup/`（git 忽略），最终确认无误后清理。

## 六、验证结果

| 项目 | 结果 |
|---|---|
| 全部 PHP 文件语法 | ✅ 通过 |
| 10 个测试套件 | ✅ 全部通过 |
| php 架构启动 / health / WebUI / 5 个 API / 事件 | ✅ 200 + accepted |
| 前端 jsdom E2E（10 个视图） | ✅ 全部通过 |
| `Registry` 插件识别 | ✅ 4 个（group / help / permissio / sys） |
| 剩余 PHP 文件 | 110 个，17945 行 |

**未验证**：swoole 架构的真实运行（本机没有 ext-swoole）。`onRequest` 的改动
是按 Swoole API 静态编写的，与 php 架构共用同一个 `Routes::kernel()`，
但**没有实机跑过**。这是本轮唯一的遗留风险，已在此明确记录。

## 七、测试的连带修改

- `tests/outbound_test.php` / `outbound_gaps.php`：`Protocol\Core\Context::bind()`
  → `Core\Bot\Context::bind()`；断言从「Context 实例身份」改为「adapter 身份」
  （Context 是不可变对象，每次 bind 都会替换栈顶，不应比较实例）。
- `outbound_gaps.php` 的 section 3 原来检查「Trigger 文件里有 snapshot/restore 字符串」，
  文件删了自然失败。改成**行为验证**：嵌套 `Context::run()` 与异常路径下，
  上下文是否都正确恢复——这才是原来那个测试真正想保证的事。
