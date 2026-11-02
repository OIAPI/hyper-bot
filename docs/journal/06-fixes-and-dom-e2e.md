# 日志 06：缺陷修复与真实 DOM 端到端验证

日期：2026-11-03

## 背景

日志 05 交付了内核与 WebUI，自测全绿。但「自测通过」不等于「功能可用」——
这一轮把 WebUI 真正跑起来逐个点过，抓到三个只有实际使用才会暴露的缺陷。

## 三个真实缺陷

### 1. FileStore 的通配符把键全丢了（严重）

`Metrics::groups()` 用 `keys('*.info')` 找群配置，但 `FileStore::keys()` 的实现是：

```php
str_starts_with($key, rtrim($prefix, '*'))   // '*.info' -> '.info'
```

`123456.info` 并不以 `.info` 开头，所以**一个都匹配不到**。
表现：群列表永远是空的；给群关掉插件后，群详情里 `plugins_on` 依然是 4。

改成支持任意位置的通配（`*` → 正则 `.*`），并且不加 `*` 时仍按前缀匹配，
保持向后兼容。

### 2. Config 写回把文件写坏了（严重）

`Config::set('app.debug', false)` 之后 `Config.ini` 里多出一行 `1false`，
原本的 `debug = true` 没被替换。原因：

```php
preg_replace($pattern, '1' . ..., $current, 1, $count)
```

替换串前缀是字面量 `1` 而不是反向引用 `$1`，本该写 `"$1"`。

### 3. 同名键会串小节

`fileKey()` 只返回「小节内的键名」（`app.debug` → `debug`），
随后拿 `debug` 做全文正则替换——只要别的小节里也有 `debug`，就会改错地方。

重写了 `set()`：先按行扫描定位目标小节的起止范围，只在该范围内匹配键名，
命中就整行替换；未命中则插入到该小节末尾（小节不存在才新建）。
同时用真实配置验证了「两个小节各有一个 `debug`」的场景不会互相污染。

三者都补了回归测试（`kernel_test.php`），防止再犯。

## 验证方式升级：真实 DOM

浏览器工具的点击通道在本机失效（viewport 恒为 0×0），只能读文本。
于是改用 jsdom 搭了一套前端端到端：

- 加载**真实的** `views/*.js` 与 `lib/*.js`（不是 mock）
- 直连**运行中的** dev-server（真实 HTTP、真实会话 Cookie）
- 断言：登录、8 个视图渲染、登记群、关插件后 `plugins_on`/`worker` 变化

```
OK   登录页渲染出表单
OK   登录 API 成功 / 下发了会话 Cookie
OK   视图 overview/groups/plugins/protocols/logs/messages/settings/system 渲染
OK   登记群后可读到
OK   关闭插件后 plugins_on 减少
OK   关闭插件后 worker 有记录
全部通过
```

脚本已收进 `tests/webui_dom_test.mjs`，可重复执行。

## 其它

- `Config.ini` 增加 `app.timezone`；不设置时时区保持 PHP 默认（CLI 是 UTC），
  设置后日志与群管理时间才与本地一致。
- 路由清单端点原先按「方法分组」遍历，而 `Router::all()` 是二维表，
  导致 `/system/routes` 返回 0 条；已修正为按 `method => routes` 展开。

## 状态

内核、WebUI 后端、WebUI 前端、Swoole 接入、文档均已完成并有测试覆盖；
本轮补上了实际使用中暴露的三个缺陷。剩余风险仍是本机无 swoole/redis 扩展，
WebSocket 与 Redis 路径只能静态推演。
