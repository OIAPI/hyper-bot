<?php
namespace Core\Http;

/** 不可变请求对象的可变扩展：路由参数、会话、已解析输入都通过它传递。 */
final class RequestContext {
    public function __construct(
        public readonly Request $request,
        public array $params = [],
        public ?array $session = null,
    ) {}
}
