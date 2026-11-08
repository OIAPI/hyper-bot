# 更新日志

当前发布版本：**3.3.11**（仓库 VERSION 已到 3.3.12，3.3.12 补插件 reload 遗漏，未出包）。

## 3.3.12（未发布）

- 修复 3.3.11 仍无法 reload 插件代码的遗漏：
  - 启动横幅在 Swoole master 里调用 `registry->plugins()`，仍会 class_exists 把所有插件类加载进 master；
  - Swoole 下横幅不再读取插件数量，改为「Worker 启动时加载」；
  - master 保持不加载插件类，reload worker 才能 fork 出最新代码。

## 3.3.11

- 修复插件改代码后 reload/restart 仍加载旧代码：
  - Swoole master 不再提前加载插件类，插件改为 WorkerStart 内加载；
  - WorkerStart / reload 前清理插件文件 OPcache；
  - WebUI「重新加载」在 Swoole 下会延迟给 master 发 SIGUSR1 重启 worker；
  - Sys 插件 reload、控制台 reload/restart 共用 PluginReloader，走同一套清缓存和重启逻辑；
  - PHP/webui 架构每次请求重新加载插件。

## 3.3.10

- WebUI 升级保留本地修改：Git 工作区改动、upstream.json 哈希、preserve 规则；
- 面板显示「保留 N 个本地修改」，被覆盖旧文件仍备份。

## 3.3.9

- 发布流程改为 tag 自动触发：普通 push 只同步源码，不构建、不打包、不发 Release；
- 需要 build 包时推送 v<版本> tag，工作流自动校验 VERSION、构建、打包并创建 Release；
- 手动 Actions → release → Run workflow 保留为兜底。

## 3.3.8

- 新增配置项支持一次添加多个：同一小节下多行键名/类型/值，统一校验后逐项写入；
- 保持 Config.ini 自动备份、plugin.xxx 小节和 Config::string 读取方式不变。

## 3.3.7

- 新增 MySQL / PostgreSQL / SQLite 持久层（PdoStore），键值 + 有序集合自动建表；
- 新增 CachedStore：数据库做持久层，Redis 只做读缓存，缓存异常自动直读数据库；
- Redis 读超时 1.5 秒，避免长时间空闲连接假死拖住 worker；
- 群同步按协议分流：
  - OneBot / Milky 调用 get_group_list；
  - QQ 官方只按消息/事件记录刷新，不调用受限接口；
  - 群列表按协议过滤（官方 32 位 openid / OneBot、Milky 纯数字），不再互相混入；
- 多账号同步必须指定 account，避免写错账号；
- 新增 [store] 配置说明和数据库部署文档。

## 3.3.6

- QQ 官方机器人补流式单聊 stream_messages；
- 补分片上传 prepare / part_finish；本地 >5MB 富媒体自动走官方分片流程；
- 补 get_gateway / get_self_info。

## 3.3.5

- QQ 官方机器人补入群自动审批策略（查询/创建/修改/删除/执行/白名单）；
- 补指令面板详情/修改/删除/关联目标；
- 补群成员批量禁言、频道（Guild）相关接口。

## 3.3.4

- QQ 官方机器人使用 get_group_info 同步群名；
- get_group_info 补 group_openid / group_tags，群简介为空时补备注。

## 3.3.3

- 修复 #[Notice('group_increase')] 这类原始类型名被当正则导致的
  invalid hook regex / Internal error，入群/退群通知恢复触发；
- 带分隔符的 /regex/ 写法保持可用。

## 3.3.2

- 群管理弹窗支持修改群名 / 备注 / 标签；
- 官方机器人没有群列表接口时群名可手动填写，群页增加提示；
- 事件里携带 group_name 时群列表直接展示。

## 3.3.1

- 修复移除群后旧事件又把群拉回来、时间被清空的问题（按账号记移除墓碑）；
- 单账号事件 self_id 对不上时回退到唯一启用账号，避免插件数据掉回全局键；
- 多账号仍严格按 self_id，不猜测。

## 3.3.0

- 机器人账号作用域：群开关、群级插件开关、群名/备注/标签、成员权限、
  按群订阅覆盖、事件流、插件通过 $Redis 写的配置缓存，全部按账号隔离；
- WebUI 顶栏全局账号选择器，请求自动带 X-Account-Id，Kernel 注入 AccountContext；
- 单账号升级兼容：群/权限/订阅/事件回退旧全局键；
- 配置页支持删除单项或整个小节（DELETE /settings），自动备份；
- QQ 官方机器人：只用账号配置，API 固定生产/沙箱，账号页新增沙箱开关；
- 修复官方机器人自建回复不走协议桥、openid self_id、Gateway 读超时。
