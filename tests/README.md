# 协议自检脚本

> 这些脚本**不需要 Swoole / Redis 扩展**，可在任意装有 PHP 8.2+ 的机器上直接运行，
> 用于验证协议层的转换与状态机逻辑，避免上线后才发现数据映射错误。

```bash
php tests/protocol_test.php   # Milky 事件/消息段转换、QQ 官方事件转换、配置读取
php tests/qqbot_sign.php      # QQ 官方 Webhook Ed25519 签名（需要 ext-sodium）
php tests/event_flow.php      # Milky 幂等去重、QQ 官方被动回复 msg_seq 状态机
php tests/local.php           # Cache 协程私有存储（raw_body / headers）
```

全部通过时退出码为 0；任一失败退出码为 1，可直接接入 CI。

## 说明

- `event_flow.php` 用同步版 `Swoole\Coroutine` 桩替代真实扩展，
  因此 `EventDispatcher` 的分发逻辑会被真实执行（包括触发控制器实例化）。
- 涉及 `Trigger` 控制器时会因为缺少 Redis 打印告警，属于预期行为，不影响断言。
- 若要在真实 Swoole 环境跑，删掉文件顶部的桩代码即可。
