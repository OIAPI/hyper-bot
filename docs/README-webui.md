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

## 自检

```bash
php tests/webui_api_test.php      # 36 条路由的端到端行为
php tests/webui_bridge_test.php   # Swoole 桥接（桩对象）
php tests/kernel_test.php         # 内核各组件
```
