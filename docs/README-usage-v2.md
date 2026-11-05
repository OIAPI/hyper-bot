# Hyper Bot v2 使用文档

> 面向使用者：怎么装、怎么启动、怎么接协议、怎么在 WebUI 里改配置。
> 想了解内部结构请看 [架构说明](README-architecture-v2.md)，从 v1 升级请看 [迁移指南](README-migration-v1-to-v2.md)。

## 1. 这是什么

Hyper Bot 是一个 QQ 机器人消息网关，把三种协议端统一成一套业务插件：

| 协议 | 方向 | 说明 |
|---|---|---|
| **OneBot11** | 正向 WS / 反向 WS / HTTP webhook / HTTP API | 最常用，NapCat、Lagrange、LLOneBot 等协议端都支持 |
| **Milky** | webhook / WS / SSE | [milky.ntqqrev.org](https://milky.ntqqrev.org/)，新版 NTQQ 协议 |
| **QQ 官方机器人** | webhook / Gateway WS | 官方 API v2，走 app_id + client_secret 鉴权 |

插件只需写一次（基于 OneBot11 事件模型），三种协议端都能用——
协议适配层负责双向翻译。

## 2. 环境要求

- **PHP >= 8.1**（推荐 8.2+）
- 必需扩展：`json`、`mbstring`、`curl`、`openssl`
- 可选扩展：
  - `swoole` —— 想要协程并发 / WebSocket 服务端 / 常驻连接时必需（**生产推荐**）
  - `sodium` —— QQ 官方机器人 webhook 验签必需
  - `redis` —— 用 Redis 存储时必需；没有则自动降级为文件存储

一条命令体检：

```bash
bash bin/hyper.sh doctor
```

## 3. 三种运行架构

v2 最大的变化：**跑哪种架构是配置项，不是硬编码**。

| 架构 | 适用场景 | 需要 Swoole | 能力 |
|---|---|---|---|
| `swoole` | 生产环境 | 是 | 全部：HTTP + WS 服务端 + 常驻客户端 + 精确定时器 |
| `php` | 开发机 / 无 Swoole 环境 / 小规模 | 否 | HTTP 接入 + WebUI；WS 相关功能不可用 |
| `webui` | 只想用管理面板 | 否 | 只有 WebUI，不处理机器人事件 |

**自动选择**：不指定时（`auto`）会检测环境——装了 Swoole 就用 `swoole`，没装自动降级为 `php`，
并在启动日志里明确写出降级原因，不会静默半残。

选择优先级（从高到低）：

1. 命令行 `--arch=swoole`
2. 环境变量 `HYPER_ARCH=swoole`
3. `Config.ini` 的 `[runtime] arch`
4. 自动检测

WebUI 的「运行架构」页面也能切换（写入 Config.ini，**重启后生效**）。

## 4. 启动

### 用启动脚本（推荐）

```bash
bash bin/hyper.sh start                      # 按配置启动
bash bin/hyper.sh start --arch=php --port=8080
bash bin/hyper.sh stop
bash bin/hyper.sh restart --arch=swoole
bash bin/hyper.sh status
bash bin/hyper.sh logs                       # 跟踪日志
bash bin/hyper.sh doctor                     # 环境体检
```

> 安卓 / Termux 的 `/storage` 挂载不支持可执行位，所以请用 `bash bin/hyper.sh` 调用，
> 不要写 `./bin/hyper.sh`。

### 用 PHP 入口

```bash
php bin/hyper serve                          # 按配置
php bin/hyper serve --arch=php --port=8080   # 指定架构与端口
php bin/hyper worker                         # 只跑定时任务队列
php bin/hyper status                         # 打印架构/订阅/协议状态
php bin/hyper doctor                         # 环境体检
php bin/hyper config runtime.arch php        # 读/写配置
```

### 绑定 HTTPS 证书

```bash
# 绑定（校验证书/私钥是否匹配，然后复制到 runtime/ssl）
php bin/hyper ssl:bind --cert=/path/fullchain.pem --key=/path/privkey.pem --domain=example.com

php bin/hyper ssl:show      # 查看已绑定证书（域名 / 到期 / 指纹）
php bin/hyper ssl:remove    # 删除绑定
```

- 每次启动都会自动读取 `runtime/ssl` 里的证书；
- Swoole 架构会**额外**监听 HTTPS 端口（默认 443，可用 `--ssl-port=8443`
  或 `Config.ini [http] ssl_port` 修改），原来的 HTTP 端口保持不变；
- 临时覆盖已绑定证书（优先级更高，只影响本次启动）：

```bash
php bin/hyper serve --ssl-cert=/tmp/fullchain.pem --ssl-key=/tmp/privkey.pem
php bin/hyper serve --no-ssl      # 忽略已绑定证书，只跑 HTTP
```

- PHP / webui 架构用的是 PHP 内置服务器，不支持 TLS；请用 `--arch=swoole`
  或在前置 Nginx/Caddy 上终止 TLS；
- 非 root 用户无法监听 443，请用 `--ssl-port` 选高位端口再交给反代。

### 启动后的控制台

`serve` 启动后终端会进入交互模式，可以直接敲命令，不需要另开终端：

```text
hyper> status          # 等价于另开终端执行 php bin/hyper status
hyper> doctor
hyper> ssl:show
hyper> config runtime.arch php
hyper> passwd admin 新密码
hyper> stop            # 停止服务（exit / quit 同效）
```

- 除 `status`/`doctor`/`config`/`ssl:*`/`passwd`/`ext`/`redis` 外，`bin/hyper` 的其他
  子命令也会透传执行（在独立 PHP 进程里跑）；
- `serve` / `worker` / `install` / `update:apply` 不允许在运行中的控制台执行；
- 编辑：`↑`/`↓` 翻历史，`←`/`→`、Home/End 移动光标，Backspace/Delete 编辑，
  Ctrl+C 取消当前行，Ctrl+U 清空，Ctrl+L 清屏；
- 历史最多保留 100 条，连续重复的命令只记一条。

启动后会打印一段横幅，确认架构、存储、订阅、插件数量：

```
  Hyper Bot 2.0.0
  ────────────────────────────────────────────────────
  运行架构  PHP 内置服务器（php）
  存储      FileStore
  订阅      message(group) + notice(group_decrease/group_increase) + request + meta_event
  插件      4 个
  配置      /path/to/Config.ini
  ────────────────────────────────────────────────────

  入口      http://127.0.0.1:8080/webui/
```

## 5. WebUI

启动后打开 `http://<host>:<port>/webui/`。

首次启动会在 `runtime/webui/initial-password.txt` 生成初始密码，
登录后在「配置」页可以改。

| 页面 | 用途 |
|---|---|
| 概览 | 事件量、插件数、运行状态 |
| 群管理 | 登记群、开关群、按群关闭插件 |
| 插件 | 插件列表、元信息、热重载 |
| 消息记录 | 最近事件（被订阅丢弃的也在里面） |
| 协议接入 | 三个协议端的连接状态与参数 |
| **消息订阅** | 决定哪些事件值得处理（v2 新增） |
| **运行架构** | 切换 swoole / php / webui（v2 新增） |
| 日志 | 按日期查看、过滤等级 |
| 配置 | 直接编辑 Config.ini |
| 系统 | 存储、目录体积、已注册路由 |

## 6. 接协议端

### 6.1 OneBot11

`Config.ini`：

```ini
[protocol.onebot]
enable = true
self_id = 10001

; HTTP webhook：协议端把事件 POST 到本服务
[protocol.onebot.forward]
enable = true
url = http://127.0.0.1:8080/api/protocol/webhook

; 反向 WS：协议端连过来（需要 swoole 架构）
[protocol.onebot.reverse]
enable = true
path = /onebot/ws
token = 你的token

; HTTP API：本服务主动调协议端
[protocol.onebot.api]
url = http://127.0.0.1:3000
token = 你的token
```

- **正向 WS**：本服务主动连协议端的 `ws://.../ws` 接收事件（需要 swoole）
- **反向 WS**：协议端连本服务（需要 swoole，因为要 WebSocket 服务端）

### 6.2 Milky

```ini
[protocol.milky]
enable = true
api = http://127.0.0.1:3010
token = 你的token
timeout = 30

[protocol.milky.event]
mode = webhook                    ; webhook / ws / sse
[protocol.milky.webhook]
path = /api/protocol/milky/webhook
```

> 无 Swoole 时只能用 `mode = webhook`，启动时会告警。

### 6.3 QQ 官方机器人

```ini
[protocol.qqbot]
enable = true
app_id = 你的AppID
client_secret = 你的Secret

[protocol.qqbot.event]
mode = webhook                    ; webhook / ws（ws 需要 swoole）
[protocol.qqbot.webhook]
path = /api/protocol/qqbot/webhook
verify = true                     ; 是否校验签名（需要 ext-sodium）
```

在 QQ 开放平台把回调地址填成 `https://你的域名/api/protocol/qqbot/webhook`。
平台验证回调时发 `op=13`，本服务会自动返回 `plain_token` + `signature`。

## 7. 消息订阅

**为什么保留订阅？**（这是 v2 明确保留的设计，不是历史包袱）

订阅是**业务路由的第一层过滤**，不是协议层的事：

1. 多个协议端可能同时喂同一个群，但业务只关心其中一部分事件；
2. 让不关心的事件在**最便宜的位置**被丢弃——不落库、不跑插件、不调 API；
3. 每条被丢弃的事件都能给出**可读原因**（`reason`），排查「插件为什么没触发」直接看日志；
4. 新协议会产生大量业务无关事件（心跳、成员变更、机器人上下线），订阅是唯一的止损点。

配置位置：

```ini
[subscription]
message = true                    ; 消息事件
notice = true                     ; 通知事件
request = true                    ; 请求事件
meta_event = false                ; 元事件（心跳等，一般关掉）

message_scope = group             ; group / private / all

; 只处理这些通知类型；留空 = 全部丢弃
notice_types = group_increase, group_decrease, group_ban

ignore_self = true                ; 忽略自己发的消息（防自触发死循环）
ignore_robots = true              ; 忽略其他机器人
robot_prefix = 3889
```

WebUI「消息订阅」页面可视化编辑，保存后重启生效。

**兼容性**：没写 `[subscription]` 时，自动读 v1 的 `[type]` / `[message] type` / `[notice] {type}=1`，
老配置不用改。

## 8. 写插件

插件写法与 v1 **完全一致**，老插件不用改：

```php
<?php
namespace App\Plugins;

use App\BaseApp;
use Controller\Attr\Rule;

/**
 * @name demo
 * @description 示例插件
 * @version 1.0
 */
class Demo extends BaseApp
{
    #[Rule('/^hello$/i', name: '打招呼', permission: 1)]
    public function hello(): void
    {
        $this->api->message->sendMsg(
            group: $this->group,
            message: '你好，' . $this->nickname
        );
    }
}
```

可用的属性：

| 属性 | 说明 |
|---|---|
| `$this->api` | API 容器：`$this->api->message->sendMsg(...)` |
| `$this->Redis` | Redis 风格存储（无 ext-redis 也能用，底层是文件/Redis） |
| `$this->input` | 当前事件（`Core\Bot\Event`，带 `get/postType/userId/groupId` 等） |
| `$this->qq` / `$this->group` | 当前用户 / 群（官方机器人的 OpenID 是字符串，v1 会崩） |
| `$this->nickname` / `$this->raw` | 昵称 / 原始消息 |
| `$this->message_id` | 消息 ID |
| `$this->del($id, 2)` | 延迟删除消息（Swoole/PHP 两种架构都可用） |

规则注解：

```php
#[Rule('/正则/', name: '显示名', permission: 3)]      // 消息
#[Notice('group_increase')]                          // 通知
#[Request('friend')]                                 // 请求
```

`permission` 等级（沿用 v1 编号）：`default=1 < editor=2 < admin=3 < manager=4 < director=5 < owner=6 < supervisor=7 < super=8`。

## 9. 从 v1 升级

见 [迁移指南](README-migration-v1-to-v2.md)。一句话：

- **配置**：老 `Config.ini` 直接用，新键不写就走兼容逻辑；
- **插件**：代码不用改；
- **启动**：`php index.php` → `bash bin/hyper.sh start`（或 `php bin/hyper serve`）。

## 10. 常见问题

**Q：启动报「未安装 ext-swoole，已降级为 php」**
不是错误，是正常降级。想用 WS 相关功能才需要装 Swoole。

**Q：端口被占用**
`bash bin/hyper.sh start --port=8081`，或先 `bash bin/hyper.sh stop`。

**Q：插件没触发**
去 WebUI「消息记录」看这条事件有没有进来；再查「日志」里的 `accepted` 与 `reason` 字段——
订阅拒绝会写明原因。

**Q：改配置后不生效**
大部分配置需要重启。WebUI 保存时也会提示「重启后生效」。

**Q：`/storage` 上 chmod +x 无效**
安卓 FUSE 挂载的限制。用 `bash bin/hyper.sh` 调用即可。
