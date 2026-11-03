# v1 → v2 迁移指南

## 一句话结论

**老配置直接用，老插件不用改。** 只有启动方式变了。

```bash
# v1
php index.php

# v2
bash bin/hyper.sh start          # 或 php bin/hyper serve
```

## 1. 配置迁移

### 不用改的部分

`Config.ini` 里所有 v1 键都会被读取，包括：

- `[app]` debug / timezone
- `[http]` port / host
- `[cq]` api / auth
- `[permission]` super
- `[Redis]` host / port / auth / select
- `[type]` message / notice / request / meta_event
- `[message]` type
- `[notice]` group_increase 等
- `[protocol.*]`（v2 新增的协议小节）

### 建议新增的部分

```ini
[runtime]
arch = auto        ; auto / swoole / php / webui

[store]
driver = auto      ; auto / redis / file / memory

[subscription]     ; 不写也行，会自动读 v1 的 [type]/[message]/[notice]
message = true
notice = true
request = true
meta_event = false
message_scope = group
notice_types = group_increase, group_decrease
ignore_self = true
ignore_robots = true
```

> **注意**：`Config.ini` 注释只能用 `;`。PHP 的 INI 解析器不认 `#`，写了会导致整行被当值。

### 订阅的兼容逻辑

v2 的 `Subscription::fromConfig()` 优先读 `[subscription]`，没有时回退到 v1：

| v2 键 | v1 回退来源 |
|---|---|
| `subscription.message` | `[type] message` |
| `subscription.notice` | `[type] notice` |
| `subscription.request` | `[type] request` |
| `subscription.meta_event` | `[type] meta_event` |
| `subscription.message_scope` | `[message] type` |
| `subscription.notice_types` | `[notice]` 里值为 1 的键 |

所以**老部署不改配置也能跑**，只是行为等价、不享受新键的可读性。

## 2. 插件迁移

**结论：零改动。**

v1 插件长这样：

```php
class demo extends BaseApp {
    #[Rule('/^hello$/', name: '打招呼', permission: 1)]
    public function hello() {
        $this->api->message->sendMsg(group: $this->group, message: 'hi');
        $this->Redis->set('k', 'v');
        return $this->del($this->message_id, 2);
    }
}
```

v2 完全一样。差异只在**属性来源**：

| 属性 | v1 来源 | v2 来源 |
|---|---|---|
| `$this->input` | `Cache::get('param')`（裸 stdClass） | `Context::current()->event`（`Core\Bot\Event`） |
| `$this->Redis` | `new \Redis()`（无扩展则 fatal） | `StoreBridge`（文件存储也能用） |
| `$this->qq` / `$this->group` | 数字为主 | 支持字符串 OpenID（官方机器人） |
| `$this->message_id` | `message_id` | `message_id ?? message_seq`（Milky 兼容） |
| `$this->del()` | `Swoole\Timer::after` | `Core\Scheduler::after`（两种架构都行） |

### 需要留意的行为变化

1. **`$this->input` 现在是对象，不是 stdClass**
   用 `$this->input->get('x')` 或 `->userId()` / `->groupId()` / `->postType()`。
   老的 `$this->input->group_id` 仍然可用（`__get` 走 `get()`）。

2. **`$this->Redis->get()` 未命中返回 `false`**（与真实 Redis 一致）。
   v1 有些地方按 `null` 判断，迁移时注意。

3. **权限等级沿用 v1 编号 1..8**
   但 `permission: PermRule::SUPER` 这种写法在 v1 里其实**永远匹配不上**
   （v1 用 `===` 比较 enum 与字符串）。v2 修正了这个隐藏 bug。

4. **删除消息不再依赖 Swoole**
   PHP 架构下走延迟队列，由主进程轮询执行（精度约 1 秒）。

## 3. 启动方式迁移

| v1 | v2 |
|---|---|
| `php index.php` | `bash bin/hyper.sh start`（已删除，勿再用） |
| `php bin/webui-serve.php` | `bash bin/hyper.sh start --arch=webui`（已删除） |
| `php -S ... public/dev-server.php` | `bash bin/hyper.sh start --arch=php`（已删除） |
| 手动 `kill` | `bash bin/hyper.sh stop` |
| 查看状态 | `bash bin/hyper.sh status` |

`public/dev-server.php`、`bin/webui-serve.php`、`index.php`、`public/manager.html` 在 v2
已**彻底删除**，请统一改用 `bin/hyper.sh` / `bin/hyper`。

## 4. 被删除/替换的 v1 代码

以下文件在 v2 里**不再被调用**（保留文件是为了兼容旧引用，可择机清理）：

| v1 | v2 替代 |
|---|---|
| `Controller\Trigger\{Message,Notice,Request,Meta_event}` | `Core\Bot\Dispatcher` |
| `Controller\Router::scanAppClasses()` | `Core\Bot\Registry` |
| `Cache::set('param')` 全局传参 | `Core\Bot\Context` |
| `Controller\Api` 的 per-CID 字典 | 容器单例 + `Container::forget` |
| `Utils\TaskManager` 的硬编码 Timer | `Core\Scheduler` |
| `Protocol\Core\EventDispatcher` 的真实逻辑 | 改为委托 `Core\Bot\Dispatcher`（壳保留） |

被删除的 v1 备份留在 `runtime/*.v1.bak`（已被 `.gitignore` 忽略）。

## 5. 迁移检查清单

```bash
# 1. 环境体检
bash bin/hyper.sh doctor

# 2. 确认架构选择正确
php bin/hyper status

# 3. 起服务
bash bin/hyper.sh start

# 4. 打开 WebUI：http://<host>:<port>/webui/
#    看「运行架构」是否是预期的，「消息订阅」是否是预期的

# 5. 发一条测试事件
curl -X POST http://127.0.0.1:<port>/api/protocol/webhook \
  -H 'Content-Type: application/json' \
  -d '{"post_type":"message","message_type":"group","group_id":10001,
       "user_id":20001,"self_id":30001,"raw_message":"/help","sender":{"role":"member"}}'

# 6. 看日志里的 {"accepted":true,...} 或 {"accepted":false,"reason":"..."}
bash bin/hyper.sh logs
```

## 6. 回滚

v2 的改动都在新文件里；旧的 `Cache` / `Api` / `BaseApp` 备份在 `runtime/*.v1.bak`。
要回滚：

```bash
git log --oneline          # 找到 v2 之前提交
git revert <commit>
```
