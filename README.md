# Hyper Bot

一个可以直接跑起来的 QQ / OneBot 机器人框架，带完整的 WebUI 管理面板：多协议、多账号、
插件、订阅、定时任务、权限、在线更新。

- 协议：OneBot 11（HTTP / 正向 WS / 反向 WS）、Milky（Webhook / WS / SSE）、QQ 官方机器人（Webhook）
- 架构：swoole 常驻（推荐）或 php 内置服务器（无需扩展）
- 面板：Vue 3 + 四套组件库可热切换（Element / Ant Design / Naive / Arco）
- 存储：文件存储开箱即用，有 Redis 时自动使用

---

## 一、环境要求

| 项 | 要求 |
|---|---|
| PHP | >= 8.1（推荐 8.3 / 8.5） |
| 必需扩展 | json、mbstring、curl、openssl |
| 可选扩展 | swoole（常驻 WS 服务端，推荐）、redis（缓存与会话共享）、sodium（QQ 官方 Webhook 验签） |
| 依赖 | 发布包已内置 vendor/；源码部署需要 Composer |

没有 swoole 也能跑（php 架构），但没有常驻 WebSocket，只能用 HTTP Webhook / 轮询。

---

## 二、快速开始

~~~bash
# 1. 解压发布包
unzip hyper-bot-vX.Y.Z.zip -d hyper-bot && cd hyper-bot

# 2. 准备环境（幂等：依赖已就绪时只做体检）
php bin/hyper install

# 3. 自检
php bin/hyper doctor

# 4. 启动（默认 swoole，没装扩展会自动降级 php）
php bin/hyper serve
~~~

启动后打开 http://<服务器>:9501/webui/ 。

首次登录：users.json 不存在时，第一个请求会创建 admin（主人），随机密码写在
runtime/webui/initial-password.txt，启动横幅与日志里也会打印这个路径。
不想用随机密码就先执行：

~~~bash
php bin/hyper passwd admin '你的密码'    # 账号不存在会自动创建成主人
php bin/hyper passwd --list              # 查看账号、角色、第三方绑定
~~~

---

## 三、命令行

| 命令 | 作用 |
|---|---|
| php bin/hyper serve [--port=9501] [--arch=swoole\|php] | 启动服务 |
| php bin/hyper worker | 只跑后台轮询（无 HTTP），适合容器里单独起一个进程 |
| php bin/hyper install [--force] [--dev] [--download-composer] | 准备依赖与运行时目录 |
| php bin/hyper doctor | 环境体检（必需项 / 可选项分开报告） |
| php bin/hyper redis | Redis 逐步诊断：连接 / AUTH / SELECT / PING / 读写往返 / 面板存储 |
| php bin/hyper passwd <用户> [密码] [--role=owner] [--list] [--path] [--verify] | 重置或创建面板账号 |
| php bin/hyper status | 查看当前运行状态 |
| bash bin/hyper.sh start\|stop\|restart\|install | 后台守护方式运行 |

---

## 四、WebUI 页面

| 分组 | 页面 | 说明 |
|---|---|---|
| 总览 | 概览 | 在线状态、事件量、协议连接、最近事件 |
| | 连接 | 各协议状态；可「测试发送」验证 API 地址与 token |
| | 事件 | 事件流，可按时间与类型筛选 |
| 运维 | 日志 | 按天读日志，兼容结构化 JSONL 与纯文本日志 |
| | 消息记录 | 收发消息留痕，默认显示最近有数据那天 |
| 业务 | 群管理 | 群列表、插件开关、同步群名（调 get_group_list） |
| | 插件 | 插件与钩子列表，可全局启停（不用删目录） |
| | 账号 | 机器人账号（多账号），一个协议可以挂多个号 |
| | QQ 权限 | 机器人在群里认人的角色与能力 |
| | 订阅 | 事件订阅目录，可增删自定义类型、按群覆盖 |
| 系统 | 定时任务 | cron 定时发消息 / 调插件静态方法 |
| | 配置 | 图形化编辑 Config.ini，危险项有提示 |
| | 运行时 | 切换 swoole / php 架构 |
| | 系统 | 健康检查（含版本号）、磁盘、路由表 |
| | 程序更新 | git 链接 / 压缩包 / 检查更新 |
| | 账号设置 | 面板登录账号、密码、第三方登录绑定 |

侧边栏底部可以切换组件库与明暗、调主题色（三滑块），全部即时生效不刷新页面。

---

## 五、配置要点（Config.ini）

> 面板里能改的都在「配置」页；手改文件后需要重启进程。

~~~ini
[app]
name = Hyper Bot
timezone = Asia/Shanghai

[server]
port = 9501

[runtime]
arch = swoole          ; swoole | php，默认 swoole，缺扩展自动降级

[webui]
session_store = file   ; file（推荐）| store

[store]
driver = auto          ; auto 有 Redis 就用 Redis，否则文件

[Redis]
host = 127.0.0.1
port = 6379
auth =
select = 7             ; 填错只会回退文件存储，用 php bin/hyper redis 排查

[protocol.onebot]
enable = false
api = http://127.0.0.1:3001   ; 发消息 / 拉群列表走这里
token =
forward.enable = false         ; 本框架主动连实现端
forward.url = ws://127.0.0.1:3001
reverse.enable = false         ; 实现端反向连本框架（只有 true 才启动 WS 服务端）
reverse.path = /onebot/v11/ws
reverse.token =

[protocol.milky]
enable = false
api = http://127.0.0.1:3010
event.mode = webhook
webhook.path = /api/protocol/milky/webhook

[protocol.qqbot]
enable = false
app_id =
client_secret =
event.mode = webhook
~~~

### 关于 WS 服务端

只有下面任一条件成立时，进程才会启动 WebSocket 服务端：

- [protocol.onebot] reverse.enable = true
- [protocol.milky] enable = true 且 ws.server_path 非空

否则端口上只跑 HTTP，不监听 WS（实现端重连会在握手阶段被拒，也不会刷日志）。
php bin/hyper doctor 里有一行「WS 服务端」显示当前状态。

---

## 六、多账号

同一协议可以挂多个机器人号，各自一套 api / token / self_id：

- 面板「业务 -> 账号」增删改，凭据只写不读（列表只显示「已配置」）
- 事件进来时按 self_id 自动路由到对应账号；手动调用可用 ?account=<id> 指定
- 主动操作（测试发送、同步群名）优先用账号里的 api/token，取不到才回落 Config.ini
- 同一协议有多个账号又没指定时，接口会报错并列出候选，不会瞎猜

---

## 七、两套「权限」不要混

| | 面板账号 | QQ 成员权限 |
|---|---|---|
| 管什么 | 谁能登录 WebUI | 谁能用机器人的管理命令 |
| 身份 | 用户名 + 密码 / 第三方登录 | QQ 号 |
| 在哪配 | 系统 -> 账号设置 | 业务 -> QQ 权限 |
| 角色 | owner / admin / viewer | super / owner / admin / editor / member |

QQ 权限是「角色 -> 能力」模型，能力包括 command.basic、command.manage、
group.config、member.manage、plugin.manage、system.manage；
旧的 8 档等级（含数字 2/3/6/15）读取时自动映射，老插件不用改。

面板读权限时会同时看三个地方：面板自己的 store、带 dbN: 前缀的键、以及直连
[Redis] 配置指向的那台 Redis —— v1 的数据通常在最后那个里。

---

## 八、订阅

- 目录 = 内置（约 46 项）+ 插件通过类注释 @subscribe 声明的 + 面板里自定义的
- 全新部署默认全开，装完就能收事件
- 支持按群覆盖（静音 / 只收指定通知类型）、请求与元事件的细分开关
- 判定顺序：通道开关 -> 自身/机器人前缀 -> 按群覆盖 -> 消息范围 -> 通知白名单

---

## 九、定时任务

存在 runtime/schedule/jobs.json，面板「系统 -> 定时任务」管理。

- 表达式：标准 5 段，支持 *、*/n、a-b、a-b/n、a,b 与 @hourly @daily 等别名
- 动作：给群/好友发消息，或调用插件静态方法（Class@method）
- 执行入口：swoole 有 30 秒定时器；php 架构靠 HTTP 请求顺带触发（没人访问就不会准点）；
  也可以单独跑 php bin/hyper worker

---

## 十、插件

把插件目录放进 app/Plugins/，或从面板「插件 -> 导入」上传 zip / 填 git 地址。

- 钩子来自注解：#[\Controller\Attr\Rule]（命令）、#[Notice]（事件）、#[Request]（请求）
- 面板能看到每个插件的钩子数量、触发条件、处理方法与所需权限
- 全局启停写在 runtime/plugins-state.json，停用的插件不注册钩子（不是删文件）
- 插件可以声明订阅类型（类注释 @subscribe / @subscribeGroup）

---

## 十一、更新与发布

面板「系统 -> 程序更新」：

| 方式 | 说明 |
|---|---|
| 检查更新 | 目录是 git 仓库时 git fetch 比对远端，只报告 |
| 从 Git 链接 | git clone --depth 1 到临时目录再覆盖；成功后自动把当前目录关联成仓库 |
| 从压缩包 | 上传发布包 zip，自动剥外层目录 |

永不覆盖：runtime/、vendor/、.git/、node_modules/、Config.ini、.env。
旧文件先备份到 runtime/update/backup-<时间>/。

国内拉 GitHub 慢可以用加速前缀（面板下拉，或手动拼）：
https://gh-proxy.com/https://github.com/user/repo.git

### 自动发布（GitHub Actions）

推送到 main 或手动触发 .github/workflows/release.yml 时：

1. 读 VERSION，若该版本 Release 已存在则跳过（幂等）
2. 构建前端 -> 产物有变化则提交回去（带 [skip ci]，冲突时 rebase 重试）
3. 打 tag -> 装 composer 依赖 -> 打包 zip + sha256 -> 创建 Release

发版就是把 VERSION 改一下再 push。

---

## 十二、目录结构

~~~
api/          v1 兼容的 API 门面
app/          插件目录与 BaseApp
bin/          hyper（CLI）、hyper.sh（守护脚本）
controller/   HTTP/WS 服务、注解枚举
core/         内核：Bot、Http、Store、Schedule、Update
docs/         README-webui.md（面板细节）
protocol/     OneBot / Milky / QQ 官方机器人适配
public/       入口 index.php 与 webui 静态产物
runtime/      运行时数据（日志、缓存、账号、任务、会话、更新备份）
tests/        18 个自检脚本，php tests/xxx_test.php
ui/           面板源码（Vue 3 + Vite）
utils/        日志、HTTP、Markdown 等工具
webui/        面板后端：路由、控制器、账号与权限
~~~

---

## 十三、常见问题

登录页说「还没有任何账号」
执行 php bin/hyper passwd admin '密码'。若提示账号文件不可用，看 passwd --path
输出的属主与权限（CLI 用 root 建的文件是 0600，php-fpm 以 www 运行就读不到，
chown -R www:www runtime）。

面板 500 / 登录页说没初始化管理员
多半是 Redis 配置指向了非 Redis 端口。php bin/hyper redis 会逐步告诉你卡在哪一步；
2.1.5 起存储层会自动降级到文件存储，不会整站挂掉。

Redis 明明能连，面板却不用
php bin/hyper redis 最后一行会打印「面板存储」；显示「文件存储」说明
[store] driver = file 或 Redis 某一步失败（会打 WARNING 说明原因）。

用着用着被踢回登录页
2.1.6 起会话默认存本地文件（[webui] session_store = file），Redis 抖动不再影响登录态；
只有 12 小时有效期到了才会要求重新登录。

能收消息但不能发
发消息走 [protocol.onebot] api（或账号里的 api）。面板「连接 -> 测试发送」会告诉你
实际用的是哪个地址、协议端返回了什么。

群名一片空白
点「群管理 -> 同步群名」，会调 get_group_list 把群名写回来。

反向 WS 日志刷屏
只有 reverse.enable = true 才会启动 WS 服务端；关掉后端口上不监听，也就没有这类日志。

QQ 权限列表是空的
面板会同时查面板存储和 [Redis] 指向的那台 Redis；为空时页面会直接写出
「面板存储 N 条 / 机器人 Redis 是否可用」的原因。常见是没装 php-redis 扩展、
Redis.auth 不一致，或 select 的库号不对。

更新后页面白屏
前端产物换了文件名，强刷一次浏览器（Ctrl+Shift+R），或给 index.html 关掉缓存。

---

## 十四、开发

~~~bash
# 面板
cd ui
npm install
npm run build        # 产物输出到 ../public/webui
npm run dev          # 本地开发（需要后端在跑）

# 测试（不需要 swoole / Redis）
php tests/accounts_test.php
php tests/subscription_test.php
# ……共 18 个，见 tests/
~~~

提交前建议跑一遍：

~~~bash
for f in tests/*_test.php; do php "$f" || break; done
cd ui && npx vue-tsc --noEmit && npm run build
~~~

---

## 十五、许可

跟随原项目授权方式使用；二次分发请保留出处与本文档。
