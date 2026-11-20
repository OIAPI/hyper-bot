# Hyper Bot 交接文档

> 当前发布版本：**3.3.15**
> 仓库：`OIAPI/hyper-bot`，日常分支 `dev`，发布分支 `main`
> 发布 tag：`v3.3.15` → `ac188ff`
> 本地 HEAD：`ac188ff`（dev / main 已同步）
> 工作目录：`/storage/emulated/0/model/hyper.lkaa.top`
> 最后更新：2026-11-20
>
> **当前有一个未解决的高优先级问题：插件源码修改后，reload 与完整重启都不生效。**
> 详见「八、已知限制 / 待办」第一条。

---

## 分支策略

- `main`：只用于发布，不直接开发；
- `dev`：日常开发默认分支，普通 push 只推 `dev`；
- 推 `dev` 会触发 `.github/workflows/dev.yml`，**只编译前端，不打包、不打 tag、不发 Release**；
- 需要发布时，用户明确确认后：
  1. 确认 `dev` 的 `VERSION` 已改好；
  2. `git checkout main && git pull && git merge dev`；
  3. `git push origin main`；
  4. 推 `v<版本>` tag，触发 release 工作流生成 zip/Release。
- **没有用户明确同意，不要推 `main`、不要打 tag。**

---

## 一、接手先做这几件事

1. **完整读一遍 git 日志**，不要只看最近几条：

   ```bash
   cd /storage/emulated/0/model/hyper.lkaa.top
   git log --oneline
   ```

2. 检查工作区，不要覆盖用户尚未提交的修改：

   ```bash
   git status --porcelain
   ```

3. 读本文件后，再按任务读对应源码；不要凭旧印象改代码。

4. **没有用户明确同意，不允许 `git push`、推 tag、触发 release。**
   提交可以本地做，push 前必须问。

---

## 二、项目地图

| 路径 | 职责 |
|---|---|
| `core/` | 内核：App、Config、Http、Store、Bot、Update、Schedule |
| `core/Bot/` | 事件分发、账号上下文、群状态、成员权限、订阅、事件流 |
| `core/Store/` | Store 抽象：File / Memory / Redis / Resilient / Pdo / Cached |
| `protocol/` | OneBot、Milky、QQ 官方机器人适配器与事件转换 |
| `app/Plugins/` | 内置插件；用户常在这里做本地优化，升级必须保护 |
| `webui/` | WebUI 后端 Controller / Routes / Metrics |
| `ui/src/` | Vue 3 + Vite + Pinia 前端源码 |
| `public/webui/` | 前端构建产物，发布时由 CI 生成 |
| `tests/` | 41 个自检脚本，大部分不需要 Swoole / Redis |
| `runtime/` | 运行时数据，升级永远不覆盖 |
| `docs/` | 架构、协议、部署、WebUI 细节 |
| `CHANGELOG.md` | 3.3.0 ~ 3.3.15 变更记录 |
| `.github/workflows/dev.yml` | dev 分支只编译前端，不发布 |
| `.github/workflows/release.yml` | 推 v* tag 后构建、打包、创建 Release |

---

## 三、账号作用域（最重要的架构约束）

3.3 起，机器人数据按账号隔离，不是前端过滤：

- 事件进来先按 `self_id` 找账号，压入 `Core\Bot\AccountContext`；
- `Core\Bot\AccountScope` 统一生成 `acct:{账号}:` 前缀；
- 群开关/群级插件开关：`acct:{账号}:{群}.info`；
- 群名/备注/标签：`acct:{账号}:webui:groups`；
- 成员权限：`acct:{账号}:memberPermission.*`；
- 按群订阅覆盖：`subscription-groups.{账号}.json`；
- 事件流：`acct:{账号}:webui:events`；
- 插件 `$this->Redis`：`StoreBridge` 自动加前缀，插件不用改代码；
- WebUI：顶栏选账号 → API 自动带 `X-Account-Id` → `core/Http/Kernel.php` 注入上下文。

单账号兼容：

- `AccountScope::legacyAllowed()` 在只有一个启用账号时为 true，回退读旧全局键；
- 单账号事件 `self_id` 对不上时，`EventDispatcher` 回退到唯一启用账号；
- 多账号严格按 `self_id`，不猜测；
- 启用第二个账号后，旧全局数据不再参与；需要用户重新配置或迁移。

**改账号相关逻辑时，必须同时检查**：

```text
core/Bot/AccountContext.php
core/Bot/AccountScope.php
core/Bot/GroupState.php
core/Bot/MemberPermissions.php
core/Bot/EventLog.php
core/Bot/SubscriptionOverride.php
core/Store/StoreBridge.php
core/Http/Kernel.php
webui/Controller/GroupsController.php
webui/Controller/PermissionsController.php
webui/Metrics.php
```

---

## 四、最近版本做了什么

### 3.3.0

- 账号作用域完整落地；
- WebUI 全局账号选择器；
- 配置页支持删除单项/整节；
- QQ 官方机器人账号配置、沙箱切换、发送链路修复。

### 3.3.1

- 移除群后旧事件不再把群拉回来（`webui:groups:removed` 墓碑）；
- 单账号事件回退。

### 3.3.2

- 群管理弹窗支持改群名/备注/标签；
- 事件带 `group_name` 时展示；
- 官方群名可手动填写。

### 3.3.3

- 修复 `#[Notice('group_increase')]` 原始类型名被当正则，导致
  `invalid hook regex / Internal error`；
- 只有以分隔符开头的 `match` 才走 `preg_match`。

### 3.3.4 ~ 3.3.6

- QQ 官方接口适配：`get_group_info`、入群审批策略、面板、频道、流式单聊、
  分片上传、Gateway；
- 本地 >5MB 富媒体自动走 prepare → PUT → part_finish → files。

### 3.3.7

- 新增 PdoStore（MySQL/PostgreSQL/SQLite）与 CachedStore；
- Redis 只做读缓存，Redis 假死降级直读数据库；
- 群同步按协议分流：
  - OneBot/Milky 调 `get_group_list`；
  - QQ 官方只按消息/事件记录刷新，不调受限接口；
  - 群列表按协议过滤（官方 32 位 openid / OneBot、Milky 纯数字）。

### 3.3.8

- 新增配置项支持一次添加多个。

### 3.3.9

- 发布流程改为 **推 tag 自动发布**；
- 普通 push 只同步源码，不构建、不打包、不发 Release。

### 3.3.10

- WebUI 升级保留本地修改：
  - Git 更新先跑 `git status`，工作区修改跳过；
  - `runtime/update/upstream.json` 记录上游哈希，本地不一致跳过；
  - 支持 `Config.ini [update] preserve` 和 `runtime/update/preserve.txt`；
  - 面板显示「保留 N 个本地修改」。

### 3.3.11

- 修复插件改代码后 reload/restart 仍加载旧代码：
  - Swoole master 不再提前加载插件类，插件改为 WorkerStart 内加载；
  - WorkerStart / reload 前清理插件文件 OPcache；
  - WebUI「重新加载」在 Swoole 下延迟给 master 发 SIGUSR1 重启 worker；
  - Sys 插件 reload、控制台 reload/restart 共用 PluginReloader；
  - PHP/webui 架构每次请求重新加载插件。
- 注意：从 3.3.10 升级到 3.3.11 后必须**完整重启一次 master**，
  新逻辑才会生效；之后 reload 即可加载新插件代码。

### 3.3.12

- 修复 3.3.11 的遗漏：启动横幅在 Swoole master 里调用 `registry->plugins()`，
  仍然 class_exists 把插件类加载进 master；Swoole 下横幅不再读取插件数量；
- 升级到 3.3.12 后仍需完整重启一次，确保新 master 不加载插件类。

### 3.3.13

- WebUI 消息记录：使用 MessageLog 实际目录、兼容大写文件名；审计落库提前到插件过滤前；
- RedisStore：PID 感知自动重建连接（Swoole worker 不再继承 master socket），
  异常 / set false 自动重连重试；每进程长连接，不是每请求短连接。

### 3.3.14

- Event 增加 `__get` / `__isset`：兼容 v1 插件的 `$this->input->message[0]->type`
  和 `->data->id`，数组递归转 stdClass，缺失字段返回 null，不再报
  `Undefined property: Core\Bot\Event::$message`；
- 实现 JsonSerializable / __debugInfo，json_encode(input) 返回事件字段；
- 新增 event_input_test 8 项。

### 3.3.15

- PdoStore 强制 FETCH_ASSOC，修复 Undefined array key "k" / "v" / "expires_at"；
- MySQL 强制 MYSQL_ATTR_USE_BUFFERED_QUERY；
- get/zcard 单行查询 closeCursor，zrange/keys/ztrim 显式 fetchAll，
  修复 2014 unbuffered queries active；
- PdoStore 增加 PID 感知，worker fork 后自动重连并补建表；
- pdo_store_test 增加到 20 项。

---

## 五、更新与本地修改保护

```ini
[update]
; 支持 glob，多个用逗号分隔
preserve = app/Plugins/Group.php, app/Plugins/*.php
```

或：

```text
runtime/update/preserve.txt
一行一个路径/glob
```

规则：

- 覆盖前备份到 `runtime/update/backup-<时间>/`；
- 被保留的文件不覆盖，但记录上游哈希；
- 更新结果包含 `preserved` / `preserved_files` / `warnings`；
- 首次 zip 更新没有 git 基线时，必须显式写 preserve 规则；
- 不要把用户本地插件改动用「强制覆盖」绕过。

---

## 六、发布流程

日常开发（默认）：

```bash
git checkout dev
git push origin dev
```

只触发 `.github/workflows/dev.yml` 编译前端；不打包、不打 tag、不发 Release。

需要发布时（必须用户明确确认）：

```bash
# 1. 确认 dev 的 VERSION 已改好
git checkout dev && git pull

# 2. 合并 dev 到 main
git checkout main && git pull
git merge --ff-only dev
git push origin main

# 3. 推版本 tag，触发 release 工作流
git tag v3.3.16
git push origin v3.3.16
```

工作流 `.github/workflows/release.yml` 自动：

1. 校验 tag 与 VERSION 一致；
2. 已存在同版本 Release 则跳过；
3. 构建前端；
4. 产物有变化提交回 main（`[skip ci]`）；
5. 强制把 tag 移到构建提交；
6. composer 依赖、打包 zip + sha256；
7. 创建 Release。

**任何 push / tag / 发布都要先问用户。**

---

## 七、测试与构建

后端测试（不需要 Redis/Swoole 的占多数）：

```bash
cd /storage/emulated/0/model/hyper.lkaa.top
php tests/account_scope_test.php
php tests/permissions_test.php
php tests/subscription_test.php
php tests/webui_api_test.php
php tests/webui_bridge_test.php
php tests/config_test.php
php tests/update_test.php
php tests/update_preserve_test.php
php tests/static_class_test.php
php tests/adapters.php
php tests/event_account_fallback_test.php
php tests/dispatcher_match_test.php
php tests/pdo_store_test.php
```

本机测试用 PHP 路径：

```bash
export PATH=/data/data/com.dsharnessmobile.shell/files/home/.local/bin:$PATH
TMPDIR=/storage/emulated/0/model/.tmp_phptest php tests/xxx_test.php
```

前端：

```bash
cd ui
npx vue-tsc --noEmit
npx vite build
```

本机构建脚本会把源码同步到 `/data/data/com.dsharnessmobile.shell/files/home/webui-build`，
再输出到 `public/webui`。

---

## 八、已知限制 / 待办

### 已解决/已证伪：插件源码修改后 reload / 完整重启都不生效

**结论（2026-11-20 复核）：这是测试方式造成的误判，不是代码缺陷。**

用户后续确认：「插件 reload 没问题，是我测试的时候分开测试的，没发现」。
独立验证也全部通过：

| 交接时怀疑的方向 | 实测结果 |
|---|---|
| 重启没真正换进程 | **排除**。`--arch=php` 下给父进程发 SIGINT，父子进程都退出、端口释放、pid 文件清理 |
| php -S 子进程残留旧代码 | **排除**。改完插件文件后，同一进程的下一次请求就读到新代码 |
| OPcache 命中旧字节码 | **排除**。`opcache.enable_cli = Off`、`validate_timestamps = On`、无 preload、无 file_cache |
| 加载的不是修改的文件 | **排除**。反射确认加载的就是 `app/Plugins/Group.php` 本体；`runtime/github` 为空 |
| 运行目录 ≠ 修改目录 | **排除**。pwd / readlink -f / VERSION 3.3.15 一致 |

复现方式（改一个插件元数据，看新进程是否读到）：

```bash
php -r 'require "Include.php"; \Core\Bootstrap::run(["quiet"=>true]);
  $p=\Core\App::boot()->registry->plugin("App\Plugins\Demo");
  echo $p["description"], PHP_EOL;'
```

**重要**：本机没有 `ext-swoole`，`Config.ini` 写的 `arch = swoole` 会**自动降级**
为 php 内置服务器。也就是说 3.3.11 / 3.3.12 那批 Swoole master 相关改动在没有
Swoole 的环境里根本不参与运行，不可能是本问题的原因。排查此类问题时先
`php -r '...Runtime::fromConfig()...'` 确认真实架构，别对着没生效的代码路径找。

### 测试必须用可加锁的 TMPDIR（本机特有）

`/storage/emulated`（FUSE）**不支持 flock**，`file_put_contents(..., LOCK_EX)`
直接返回 false 且不写入。用默认 TMPDIR 跑测试会出现假失败
（log_test、schedule_test 各挂几项）：

```bash
# 会假失败
TMPDIR=/storage/emulated/0/model/.tmp_phptest php tests/log_test.php

# 正确做法：指向可 flock 的目录
mkdir -p /data/data/com.dsharnessmobile.shell/files/home/.tmp_phptest
TMPDIR=/data/data/com.dsharnessmobile.shell/files/home/.tmp_phptest php tests/log_test.php
```

换到可加锁目录后全量 35 个测试脚本全部通过。

> 注意：`utils/Logger.php` 与 `utils/MessageLog.php` 直接用
> `file_put_contents(..., FILE_APPEND | LOCK_EX)`，在 `/storage` 上部署时
> **日志会静默丢失**（只把错误打到 stderr）。`core/Fs.php` 已有正确的降级逻辑，
> 但这两个文件没走它。若要在 FUSE 文件系统上部署，这是待修项。

### 原「高优先级：插件源码修改后 reload / 完整重启都不生效」记录（保留备查）

**现象（用户 2026-11-16 反馈，尚未解决）：**

- 修改插件输出/逻辑；
- WebUI 点「重新加载」、Sys 插件 reload、控制台 reload 都无效；
- 直接 Ctrl-C 退出再启动，仍然运行旧代码；
- 只有换掉整个部署/进程才可能生效。

3.3.11 ~ 3.3.15 已做但**没有解决**：

- Swoole master 不提前 Registry::load()；
- WorkerStart 内加载插件；
- 插件文件 opcache_invalidate()；
- 启动横幅在 Swoole master 不再统计插件；
- reload 延迟给 master 发 SIGUSR1；
- PdoStore / RedisStore 的 PID 感知重连。

**已排查方向（都没有最终确认）：**
- 完整重启是否真的换进程（php -S 子进程残留 / Swoole worker 残留）；
- OPcache / opcache.preload / opcache.file_cache 是否命中旧字节码；
- 实际加载文件是否就是用户修改的文件（zip 部署、runtime/github 副本、软链）；
- 运行目录/版本是否就是用户修改目录。

说明问题很可能不在“reload 逻辑”，而在以下方向之一，**接手后先验证，不要继续盲目加重载代码**：

1. **完整重启并没有真正换进程**
   - php -S 是父进程 + 子进程：没有 pcntl 时 Ctrl-C 只杀父进程，
     php -S 子进程会继续占端口，看起来“重启了”其实还是旧进程；
   - 检查：
     ~~~bash
     ps -ef | grep -E 'hyper|php -S|swoole'
     cat runtime/hyper-*.pid runtime/hyper-*.child 2>/dev/null
     ss -ltnp | grep <端口>
     ~~~
2. **OPcache / preload / file_cache 命中旧字节码**
   - 检查：
     ~~~bash
     php -i | grep -E 'opcache.enable_cli|opcache.validate_timestamps|opcache.preload|opcache.file_cache'
     ~~~
   - 若 validate_timestamps=0 或有 opcache.preload，需要显式清 OPcache 或调整配置。
3. **改的不是实际加载的文件**
   - 部署可能是 zip/release 产物，或插件从 runtime/github 加载；
   - 用反射确认实际文件：
     ~~~bash
     php -r 'require "Include.php"; \Core\App::boot()->loadPlugins(); $r=new ReflectionClass("App\\Plugins\\group"); echo $r->getFileName(), PHP_EOL;'
     ~~~
   - 对比 md5sum 与实际加载路径。
4. **运行的版本/目录不是正在改的目录**
   - 对比 pwd、readlink -f .、cat VERSION、WebUI 系统页版本。

### 其他已知限制

- **Webhook 订阅、RSS、定时任务、消息日志、Config.ini 协议级配置、插件全局启停**
  仍是全局一份，没有按账号隔离；
- QQ 官方：
  - 没有整表群列表接口，群名只能手动填/事件带 group_name；
  - `get_group_info` 等接口需要平台白名单，无权限会报 11253；
  - 频道对象目前原始 JSON 透传，没有转成 OneBot 结构；
- **Redis 假死**：默认仍是 Redis 优先；要用数据库持久层需配置 `[store] driver=mysql/pgsql`；
  切换存储不会自动迁移旧 Redis/文件数据；
- 插件直接读原始 Store/文件而不是 `$this->Redis` 时，不受账号作用域保护；
- 首次 zip 更新前，本地已改过的插件必须写进 preserve 规则，否则可能被覆盖；
- 大文件分片上传只做了接口和流程，未做真实公网大文件回归。

---

## 九、给下一个 AI 的交接提示词

完整可复制版本在根目录 `HANDOFF_PROMPT.md`，直接整段粘贴给接手的 AI 即可。

提示词已经包含：

- 项目路径、仓库、当前版本/tag；
- dev / main 分支规则与发布流程；
- 「没有明确同意不 push / 不发版」的硬性约束；
- 当前未解决的插件热更新问题，以及接手后的验证顺序；
- 账号作用域、存储、PID 重连等架构约束；
- 测试与构建命令。

---

## 十、常用命令

```bash
# 状态
git status --short
git log --oneline -20

# 后端测试
php tests/webui_api_test.php
php tests/account_scope_test.php

# 前端构建
cd ui && npx vue-tsc --noEmit && npx vite build

# 发布（需用户明确同意）
git checkout dev && git pull
git checkout main && git pull
git merge --ff-only dev && git push origin main
git tag v3.3.16
git push origin v3.3.16
```