# 日志 02：PHP 运行时搭建 + 核心层修正 + 自检

> 承接 `01-architecture-and-plan.md`。本篇记录「跑起来、能自检」这一阶段。

## 一、本机 PHP 运行时的搭建（关键突破）

之前只能用 Node 的 `php-parser` 做语法检查，无法真正执行代码。本次把 PHP 装进了 Termux 私有目录：

```
P=/data/user/0/com.dsharnessmobile.shell/files/usr
1) apt-get download php php-sodium（apt-get install 因 dpkg 的 PATH 检查失败）
2) 把 .deb 解包到私有前缀：dpkg-deb -x php_*.deb $P/var/phpx/
3) 依赖 .so 全部解包到同一前缀
4) 运行时通过 LD_LIBRARY_PATH 指向解包出来的 lib
```

封装成 `/storage/emulated/0/model/.phpext/php`（bash 脚本，用 `bash .phpext/php` 调用，
因为 /storage 是 FUSE 挂载，不可执行）。内容要点：

```bash
export LD_LIBRARY_PATH="$D/data/data/com.termux/files/usr/lib:$P/lib"
exec php -d extension_dir=".../lib/php" -d extension=sodium -d memory_limit=512M "$@"
```

> 坑：`apt-get install php` 会失败在 `dpkg: error: 6 expected programs not found in PATH`，
> 原因是 dpkg 用硬编码的 `/usr/local/sbin:/usr/sbin:/sbin` 检查 PATH，
> 与 Termux 前缀不匹配。用 `dpkg-deb -x` 手工解包绕过。

**现在可用的能力**：`php -l` 真实语法检查、`sodium`（QQ 官方验签）、`curl`、`mbstring`、`intl`。
**仍缺**：`swoole`、`redis` 扩展（Termux 源没有，无法真正起服务器，只能静态检查 + 单测逻辑层）。

## 二、测试脚本

新增两个可重复运行的自检脚本（不依赖 Swoole）：

| 脚本 | 覆盖内容 |
|---|---|
| `.phpext/tests/protocol_test.php` | Milky 事件转换、Milky CQ 解析、QQ 官方事件转换、Config 读取 |
| `.phpext/tests/qqbot_sign.php` | QQ 官方 Ed25519 握手签名向量（与官方文档示例逐字节比对） |

运行：

```bash
bash .phpext/php .phpext/tests/protocol_test.php
bash .phpext/php .phpext/tests/qqbot_sign.php
```

结果：**21 + 5 项全部通过**。

## 三、本次修掉的问题（都有实际证据）

### 1. composer autoload 缺少 `Protocol\` 命名空间
`protocol/` 目录是新加的，但 `composer.json` 的 psr-4 没登记，
导致 `Protocol\Milky\Converter` 找不到类（单测直接 `Class not found`）。
→ 在 `composer.json` 加 `"Protocol\\": "protocol/"` 并 `composer dump-autoload`。

### 2. `Protocol\Core\Config` 读不到 `[protocol.qqbot]` 这类小节
根因：`Utils\Env` 只对「小节内部的键」按点号分层，**不会拆分小节名本身**；
而 `parse_ini_file($f, true)` 把 `[protocol.qqbot]` 解析成顶层键 `"protocol.qqbot"`。
所以 `Env::get('protocol.qqbot.api')` 永远走不到。
→ 重写 `Config`：独立解析 Config.ini 并缓存，采用「最长小节名优先」的逐级回退查找
（`protocol.qqbot.api` → 试 `[protocol.qqbot]` 再在段内找 `api`；退化到 `[protocol]`）。
已用 6 个键实测通过。

### 3. Config.ini 里的 `#` 注释只要含 `=` 就会破坏 INI 解析
PHP 的 INI 解析器里 `#` **不是**注释符，只有 `;` 是。
所以 `# 默认 1<<25 | 1<<24（…）`、`# forward.url = ws://…` 这类行会被当成键值对，
直接 `syntax error` 或产生垃圾键。
→ 把所有含 `=` / `|` 的 `#` 注释改成 `;`。

### 4. `Cache::$adapter` 直接赋值会绕过协程隔离
`Context::bind()` / `EventDispatcher` 之前写的是 `Cache::$adapter = ...`（静态属性），
而 `Cache::get('adapter')` 读的是协程上下文 → WS 并发时 A 事件的适配器会串到 B 事件。
→ 统一改成 `Cache::set('adapter', ...)` / `Cache::get('adapter')`。
（`Bridge::hasAdapter()` 同步修正。）

### 5. 正向 WS 的读写跨协程
Swoole 协程客户端要求**同一连接只在一个协程里读写**；原实现 `callApi()` 在业务协程里
直接 `$client->push()`，与 `forwardLoop()` 里的 `recv()` 并发 → 未定义行为。
→ 引入出站队列 `Channel(128)`：业务协程只入队，`forwardLoop` 单协程出队并 push；
`recv` 超时改 50ms，兼顾收发。

### 6. 触发器强依赖 HTTP 响应对象
`Controller\Trigger\{Message,Notice,Request}` 的 `initDependencies()` 里
`!$this->server instanceof RequestHandler` 就抛异常 → WS 事件（无 HTTP 响应）全部失败。
→ 属性改为 `?RequestHandler`，校验降级为 `Logger::debug` 告警；
`EventDispatcher` 在无响应器时注入 `Protocol\Core\NullResponder`（空实现，
webhook 场景仍复用真实 RequestHandler）。

### 7. `Controller\Router::list()` 对 `group_id` 硬依赖
`$param->group_id` 在私聊 / QQ 官方（openid）/ Milky 私聊事件里不存在 → 直接 fatal。
→ 加默认值兜底（`is_object($param) ? ($param->group_id ?? 0) : 0`）。

### 8. PHP 8.4+ 不允许 `?string|float|int` 这种「可空 + 联合」写法
`api/message.php`、`app/BaseApp.php` 的类型拓宽改出了语法错误。
→ 必须写成 `string|float|int|null`。

### 9. 路由注册只生效一次
`Route::registerByAnnotation()` 内部有 `if (self::$init === true) return;`，
连着调两次时第二个控制器（`App\Controller\Protocol`）根本不会被注册。
→ 改成一次性传入数组 `[Api::class, Protocol::class]`。

### 10. `Meta_event` 触发器假设必有 `status`
Milky/QQ 官方的心跳/生命周期事件没有 `status` 字段 → 访问 null 属性报错。
→ 全部改为 `?? null` 判断，并区分 lifecycle / heartbeat 两种 meta_event_type。

## 四、当前状态

- 全项目 `php -l` **0 失败**
- 协议层单测 **全部通过**
- 新增文件：`protocol/Core/NullResponder.php`
- 尚未完成：`docs/README-protocol.md`、历史字段 `message_id` 兼容说明、最终提交
