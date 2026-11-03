# WebUI 控制台

基于新内核（`Core\`）的可视化管理界面，位于 `/webui/`。

## 启动

### 独立运行（无需 Swoole，适合本地/救援）

```bash
bash bin/hyper.sh start --port=8092            # 推荐
# 或 php bin/hyper serve --arch=php --port=8092
# 或指定监听地址
bash bin/hyper.sh start --host=0.0.0.0 --port=8092
```

启动时会打印访问地址与账号信息：

```
  Hyper Bot WebUI
  ─────────────────────────────────────
  地址   http://127.0.0.1:8092/webui/
  存储   Core\Store\FileStore
  配置   /…/Config.ini
  账号   admin
  密码   xxxx（已写入 runtime/webui/initial-password.txt）
  ─────────────────────────────────────
```

**首次启动的密码是随机生成的**，不接受默认口令。文件写不进去时密码会直接打印在
控制台——务必先记下来再关窗口。登录后可在「配置 → 账号」里修改。

### 跟随主服务（Swoole）

```bash
php bin/start.php     # 或你常用的启动入口
```

`Webui\Bridge` 会在 `RequestHandler` 里接管 `/webui/*`，静态资源由 Swoole 的
`enable_static_handler`（`document_root = public`）直接返回。
访问 `http://<host>:<port>/webui/` 即可。

## 页面

| 页面 | 作用 |
|---|---|
| 概览 | 运行时、依赖、协议状态、最近事件 |
| 群管理 | 登记/编辑/移除群，单群插件开关 |
| 插件 | 插件清单、触发点、重新扫描 |
| 消息记录 | 按日期查看 `Utils\MessageLog` 落盘的消息 |
| 协议接入 | OneBot11 / Milky / QQ 官方机器人 的配置与能力矩阵 |
| 日志 | 实时尾部 + 历史按日期，按级别过滤 |
| 配置 | Config.ini 的表单视图、原文视图、键值写回、账号 |
| 系统 | 目录体积、已注册路由、缓存清理 |

## 架构

前端是**无构建的原生 ES Modules**，没有 npm、没有 CDN，离线可用：

```
public/webui/
├── index.html
└── assets/
    ├── app.css            设计令牌 + 组件
    ├── main.js            持久化 shell + 路由 + 登录态
    ├── lib/{dom,api,ui}.js
    └── views/*.js         8 个视图 + 登录
```

- `lib/dom.js`：`h('div.card', props, children)` 直接建真实节点，无虚拟 DOM。
- `lib/api.js`：统一 fetch 封装，401 自动触发登出回调。
- `lib/ui.js`：Toast / Modal / 确认框 / 格式化。

## 安全

- 会话是**服务端存储**的，Cookie 为 `HttpOnly` + `SameSite=Lax`，JS 读不到 token。
- 路由**默认拒绝**：只有 login/status/health 公开，新增接口忘了加鉴权不会裸奔。
- 登录失败按 IP 限流（5 分钟内 10 次）。
- 配置回显全程脱敏：`token|secret|password|authorization|client_secret|access_token`。
- 账号存于 `runtime/webui/users.json`（bcrypt），权限 0600。

## 角色与权限

三角色：`owner`（主人）/ `admin`（管理员）/ `viewer`（只读）。能力表写在 `Webui\Auth::CAPABILITIES`，
「能力 -> 允许角色」只有一处定义，加能力不用翻控制器。

| 能力 | owner | admin | viewer |
| --- | :-: | :-: | :-: |
| view | ✓ | ✓ | ✓ |
| plugin.install / plugin.reload | ✓ | ✓ | |
| settings.write / protocol.write / group.write / system.cache | ✓ | ✓ | |
| runtime.write | ✓ | | |
| user.manage | ✓ | | |

- 控制器用 `Base::guard($request, 'plugin.install')` 兜底（返回 `null` 表示通过），真正的拦截在服务端。
- 角色**按 `users.json` 现查**而不是读会话：主人把人降级后，对方 12 小时的旧会话立刻失去写权限。
- 账号接口（仅 owner）：`GET/POST /webui/api/users`、`PATCH/DELETE /webui/api/users/{username}`、`POST /webui/api/users/{username}/password`。
- 升级兼容：老版本首个账号存的是 `admin`，`Auth::ensureOwner()` 启动时把最早创建的账号提升为 `owner`，避免面板永久失去账号管理能力。
- 前端 `GET /webui/api/auth/me` 返回 `{user, role, is_owner, permissions}`，`auth.can('plugin.install')` 只用来隐藏入口。

## 插件导入

WebUI 与聊天共用一个落盘器 `Webui\PluginInstaller`：

- WebUI：`POST /webui/api/plugins/import/zip`（raw body，`content-type: application/zip`，上限 32 MB）、
  `POST /webui/api/plugins/import/git`（`{url, branch?, name?}`），需要 `plugin.install`。
- 聊天（主人专用，`app/Plugins/PluginImport.php`）：`插件导入 git <url> [分支]`、`插件导入 zip <直链>`、
  `插件导入 列表`、`插件导入 重载`。

包落到 `runtime/github/<name>`，按 `composer.json` 的 `autoload.psr-4` 推导命名空间；入口类需与文件名同名、
位于 psr-4 根目录（Registry 只扫一层）。缺 composer.json 也能装，只是扫不到——接口用 `warnings` 告诉用户缺什么。

## 自检

```bash
php tests/webui_api_test.php      # 36 条路由的端到端行为
php tests/webui_bridge_test.php   # Swoole 桥接（桩对象）
php tests/kernel_test.php         # 内核各组件
```

## 部署

面板是「预构建静态产物 + PHP 接口」，生产机不需要 node：

1. **环境**：PHP ≥ 8.1，ext-json / mbstring / curl / openssl；先跑 `php bin/hyper doctor`。
2. **依赖**：`composer install --no-dev -o`（`vendor/` 不入库）。
3. **站点**：文档根指向 `public/`，非静态请求全部交给 `public/index.php`：
   ```bash
   php -S 0.0.0.0:8080 -t public public/index.php
   ```
   Nginx 等价写法：`try_files $uri /index.php?$query_string;`。
4. **静态**：`public/webui/` 就是构建产物（约 3.3 MB）。重新构建在 `ui/` 目录：`npm i && npm run build`。
5. **路径固定**：面板在 `/webui/`，接口在 `/webui/api`（前端写死 `BASE = '/webui/api'`）。
   反向代理若要加前缀，需要同时改前端与路由，不建议。
6. **可写**：`Config.ini` 与 `runtime/`（首次启动自动创建，含 users.json / 日志 / 缓存）。
7. **首次启动**：没有任何账号时，bootstrap 会把首个登录账号建为 owner；之后在「账号」页管理。
8. **可选扩展**：sodium（QQ 官方 Webhook 验签）、redis（可省，缺失走文件存储）、swoole（php 架构不需要）。
9. **自检**：`php bin/hyper doctor`、`php tests/webui_api_test.php`（67 项）。
