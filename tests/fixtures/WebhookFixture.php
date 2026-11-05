<?php
namespace Test\Fixtures;

use Controller\Attr\Webhook;
use Core\Http\Request;

final class WebhookFixture
{
    #[Webhook(method: 'get', router: '/demo')]
    public function ping(): int
    {
        return 122;
    }

    #[Webhook(method: 'all', router: '/demo')]
    public function any(): array
    {
        return ['ok' => true];
    }

    #[Webhook(method: 'post', router: '/demo')]
    public function echo(Request $request): array
    {
        return ['body' => $request->body];
    }

    #[Webhook(method: 'get', router: '/demo')]
    public function badReturn(): object
    {
        return new \stdClass();
    }

    #[Webhook(method: 'get', router: '/webui/x')]
    public function reserved(): int
    {
        return 1;
    }

    #[Webhook(method: 'trace', router: '/demo')]
    public function badMethod(): int
    {
        return 1;
    }

    #[Webhook(method: 'get', router: '路径')]
    public function chinese(): string
    {
        return '你好';
    }

    #[Webhook(method: 'get')]
    public function root(): string
    {
        return 'root';
    }

    #[Webhook(method: 'get', router: '/demo')]
    public function multi(Request $request, string $name, int $n = 7, ?string $extra = null): array
    {
        return ['name' => $name, 'n' => $n, 'extra' => $extra, 'method' => $request->method];
    }

    #[Webhook(method: 'get', router: '/demo/{id}')]
    public function withPath(int $id, array $params): array
    {
        return ['id' => $id, 'params' => $params];
    }

    #[Webhook(method: 'get', router: '/demo')]
    public function missing(string $nope): int
    {
        return 1;
    }
}
