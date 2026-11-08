# Hyper Bot 交接文档

> 当前开发版本：**3.3.11**（未发布）
> 最近发布版本：**3.3.10**
> 仓库：`OIAPI/hyper-bot`，分支 `main`
> 发布 tag：`v3.3.10` → `17f246f`
> 本地 HEAD：待推送（3.3.11 插件 reload 修复）
> 工作目录：`/storage/emulated/0/model/hyper.lkaa.top`
> 最后更新：2026-11-08

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
| `tests/` | 39 个自检脚本，大部分不需要 Swoole / Redis |
| `runtime/` | 运行时数据，升级永远不覆盖 |
| `docs/` | 架构、协议、部署、WebUI 细节 |
| `CHANGELOG.md` | 3.3.0 ~ 3.3.10 变更记录 |

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

普通 push：

```bash
git push origin main
```

不会构建、不会打 tag、不会发 Release。

需要发布：

```bash
git tag v3.3.11
git push origin v3.3.11
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

## 九、给下一个 AI 的提示词（直接复制）

```text
你是接手 Hyper Bot 项目的 AI 编码代理。

工作目录：
/storage/emulated/0/model/hyper.lkaa.top

仓库：OIAPI/hyper-bot
当前版本：3.3.10
当前发布 tag：v3.3.10（17f246f）

开始任务前必须：
1. 先完整执行 git log --oneline，读完整个提交历史，不要只看最近几条；
2. 读项目根目录 HANDOFF.md、CHANGELOG.md、README.md；
3. 执行 git status --porcelain，确认工作区没有需要保留的未提交改动；
4. 阅读与任务相关的源码后再改，不要凭记忆猜结构。

硬性规则：
- 没有用户明确同意，绝对不要 git push、不要推 tag、不要触发 release；
- 提交信息必须完整详细，写清改了什么、为什么改、影响范围；
- 不要为了通过测试而放宽校验或写死结果；
- 不要覆盖用户本地修改的插件；更新逻辑已有 preserve 机制，改它时要保留；
- 账号作用域是核心架构：群配置、群插件开关、成员权限、订阅覆盖、事件流、
  插件 $Redis 数据都按 acct:{账号}: 前缀隔离；单账号有 legacy 回退；
- 发布：普通 push 不构建；只有推 v<版本> tag 才自动构建发布。

验证要求：
- 后端改动至少跑相关 tests/*.php，并跑 static_class_test；
- 前端改动跑 npx vue-tsc --noEmit 和构建；
- 涉及账号/群/权限/更新逻辑时，必须跑 account_scope_test、permissions_test、
  webui_api_test、update_preserve_test。

当前任务：
[在这里写具体任务]

完成后：
- 汇总改动、测试结果、未验证项；
- 不要自行 push；询问用户是否 push / 是否发布 tag。
```

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
git tag v3.3.11
git push origin v3.3.11
```
