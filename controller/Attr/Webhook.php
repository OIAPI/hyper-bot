<?php
namespace Controller\Attr;

use Attribute;

/**
 * 把一个插件方法声明成公开 HTTP Webhook 路由。
 *
 *   #[Webhook(method: 'get', router: '/路径')]
 *   public function test() { return 122; }
 *
 * 最终路径 = router + '/' + 方法名（router 留空就是根路径）：
 *   上面这条 → GET /路径/test
 *
 * method 支持 get / post / put / patch / delete / all（all = 任意方法）。
 * 返回值只接受 Response / 标量 / 数组 / null，其它类型调用时返回 500。
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Webhook
{
    public function __construct(
        /** get / post / put / patch / delete / all */
        public string $method = 'all',
        /** 路径前缀，可留空；会自动补前导斜杠 */
        public string $router = '',
    ) {}
}
