# 自检脚本

> 这些脚本**不需要 Swoole / Redis 扩展**，可在任意装有 PHP 8.2+ 的机器上直接运行，
> 用于验证协议层、内核层与 WebUI 的行为，避免上线后才发现数据映射或鉴权错误。

## 协议层

```bash
php tests/protocol_test.php   # Milky 事件/消息段转换、QQ 官方事件转换、配置读取
php tests/qqbot_sign.php      # QQ 官方 Webhook Ed25519 签名（需要 ext-sodium）
php tests/event_flow.php      # Milky 幂等去重、QQ 官方被动回复 msg_seq 状态机
php tests/local.php           # Cache 协程私有存储（raw_body / headers）
php tests/milky_actions.php   # OneBot action → Milky API 映射覆盖率与参数细节
php tests/adapters.php        # 三个适配器的注册、name()/handles()/meta() 与 HTTP 兜底
```

## 内核与 WebUI

```bash
php tests/kernel_test.php        # Config / Router / Kernel / Auth / Store / Logger / Container
php tests/webui_api_test.php     # WebUI 全部路由的端到端行为（内存存储，不落地）
php tests/webui_bridge_test.php  # Swoole 桥接：分流、401、Cookie、重定向（用桩对象）
```

`webui_api_test.php` 使用内存存储并注入测试账号，不会污染 `runtime/`。

全部通过时退出码为 0；任一失败退出码为 1，可直接接入 CI。

## 说明

- `event_flow.php` / `webui_bridge_test.php` 用桩对象替代真实 Swoole 扩展，
  因此对应的分发 / 桥接逻辑会被真实执行，而不是只做静态检查。
- 涉及 `Trigger` 控制器时会因为缺少 Redis 打印告警，属于预期行为，不影响断言。
- 若要在真实 Swoole 环境跑，删掉文件顶部的桩代码即可。
