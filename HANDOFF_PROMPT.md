# Hyper Bot 交接提示词

> 用法：把下面「提示词正文」整段复制给接手的 AI；把最后的「当前任务」替换成实际需求。

## 提示词正文

你是接手 Hyper Bot 项目的 AI 编码代理。请先建立上下文，再动手改代码。

【项目基本信息】
- 工作目录：/storage/emulated/0/model/hyper.lkaa.top
- 仓库：OIAPI/hyper-bot（GitHub）
- 当前发布版本：3.3.15
- 当前发布 tag：v3.3.15 -> ac188ff
- 分支策略：
  - dev  = 日常开发默认分支，普通 push 只推 dev；
  - main = 只用于发布，不直接开发。
- dev 的 CI（.github/workflows/dev.yml）只编译前端，不打包、不打 tag、不发 Release。
- 发布仍然走：main 合并 + 推 v<版本> tag，触发 .github/workflows/release.yml。

【开始任务前必须做】
1. 完整执行 git log --oneline，读完整个提交历史（当前 164 条），不要只看最近几条；
2. 读根目录 HANDOFF.md、CHANGELOG.md、README.md；
3. 执行 git status --porcelain，确认工作区没有需要保留的未提交改动；
4. 阅读与任务相关的源码后再改，不要凭记忆猜结构；
5. 如果是接手前一个 AI 的未完成任务，先读 HANDOFF.md 第八节「已知限制 / 待办」。

【硬性规则】
- 没有用户明确同意：绝对不要 git push、不要推 tag、不要触发 release；
- 默认只推 dev；只有用户明确说「发布 / 发包」时，才合并 dev 到 main 并推 tag；
- 提交信息必须完整详细：改了什么、为什么改、影响范围、测试结果；
- 不要为了通过测试而放宽校验或写死结果；
- 不要覆盖用户本地修改的插件；更新逻辑已有 preserve 机制，改它时必须保留；
- 账号作用域是核心架构：群配置、群插件开关、成员权限、订阅覆盖、事件流、
  插件 $Redis 数据都按 acct:{账号}: 前缀隔离；单账号有 legacy 回退；
- 普通 push 不构建；只有推 v<版本> tag 才自动构建发布；
- 不要自行修改 VERSION 除非任务明确要求发版。

【当前最高优先级未解决问题】
插件源码修改后：
- WebUI「重新加载」无效；
- Sys 插件 reload 无效；
- 控制台 reload 无效；
- 直接 Ctrl-C 退出再启动，仍然运行旧代码。

3.3.11 ~ 3.3.15 已尝试但未解决：
- Swoole master 不提前 Registry::load()；
- 插件改为 WorkerStart 加载；
- opcache_invalidate 插件文件；
- 启动横幅不在 master 统计插件；
- reload 延迟给 master 发 SIGUSR1；
- PdoStore / RedisStore 的 PID 重连。

结论：问题很可能不在 reload 逻辑本身。接手后先验证，不要继续盲目加重载代码：
1. 是否真的换了进程：
   ps -ef | grep -E 'hyper|php -S|swoole'
   cat runtime/hyper-*.pid runtime/hyper-*.child 2>/dev/null
   ss -ltnp | grep <端口>
   注意 php -S 是父进程 + 子进程；没有 pcntl 时 Ctrl-C 可能只杀父进程，
   子进程继续占端口，看起来重启了其实还是旧进程。
2. 是否 OPcache / preload / file_cache：
   php -i | grep -E 'opcache.enable_cli|opcache.validate_timestamps|opcache.preload|opcache.file_cache'
3. 改的是否是实际加载的文件：
   php -r 'require "Include.php"; \Core\App::boot()->loadPlugins(); $r=new ReflectionClass("App\\Plugins\\group"); echo $r->getFileName(), PHP_EOL;'
   对比实际路径的 md5sum / mtime。
4. 运行目录/版本是否就是修改目录：
   pwd、readlink -f .、cat VERSION、WebUI 系统页版本。

【项目结构速查】
- core/            内核：App、Config、Http、Store、Bot、Update、Schedule
- core/Bot/        事件分发、账号上下文、群状态、成员权限、订阅、事件流
- core/Store/      File / Memory / Redis / Resilient / Pdo / Cached
- protocol/        OneBot、Milky、QQ 官方机器人适配器
- app/Plugins/     内置插件（用户常本地修改，升级必须 preserve）
- webui/           WebUI 后端 Controller / Routes / Metrics
- ui/src/          Vue 3 + Vite + Pinia 前端源码
- public/webui/    前端构建产物
- tests/           41 个自检脚本，多数不需要 Swoole / Redis
- runtime/         运行时数据，升级永远不覆盖

【存储与连接（生产已切数据库）】
- 固定数据推荐 [store] driver=mysql/pgsql，Redis 只做缓存；
- 切换存储不会自动迁移旧 Redis/文件数据；
- PdoStore / RedisStore 都有 PID 感知：Swoole worker fork 后自动重连；
- 不要重新引入「master 创建连接、worker 直接继承」的写法。

【验证要求】
- 后端改动：至少跑相关 tests/*.php，并跑 static_class_test；
- 前端改动：cd ui && npx vue-tsc --noEmit && npx vite build；
- 账号/群/权限/更新相关：account_scope_test、permissions_test、
  webui_api_test、update_preserve_test；
- 本机 PHP 路径：
  export PATH=/data/data/com.dsharnessmobile.shell/files/home/.local/bin:$PATH
  TMPDIR=/storage/emulated/0/model/.tmp_phptest php tests/xxx_test.php

【当前任务】
[在这里写具体任务]

【完成后】
- 汇总：改了哪些文件、为什么、影响范围、测试结果、未验证项；
- 不要自行 push；
- 询问用户：是否 push dev、是否需要合并 main 并发版。

## 备注

- 完整项目交接见 HANDOFF.md；
- 更新记录见 CHANGELOG.md；
- 发布/分支规则见 README.md 和 .github/workflows/。
