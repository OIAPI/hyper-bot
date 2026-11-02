<?php
namespace Core\Http;

/** 中间件：给定下一个处理器，返回一个新的处理器。可组合、可单测。 */
interface Middleware {
    public function process(Request $request, callable $next): Response;
}
