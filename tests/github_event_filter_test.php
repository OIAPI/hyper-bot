<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Http\Router;
use Core\Webhook\GithubWebhook;

$pass = 0;
$fail = 0;
function check(string $name, bool $ok): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
}

$releasePublished = ['action' => 'published'];
$releaseCreated = ['action' => 'created'];
$issueOpened = ['action' => 'opened'];
$pushMain = ['ref' => 'refs/heads/main', 'created' => false, 'deleted' => false, 'forced' => false];
$pushCreated = ['ref' => 'refs/heads/feat', 'created' => true];

check('空列表 = 全部', GithubWebhook::eventSubscribed('release', $releasePublished, []));
check('* = 全部', GithubWebhook::eventSubscribed('push', $pushMain, ['*']));
check('release 匹配任意动作', GithubWebhook::eventSubscribed('release', $releaseCreated, ['release']));
check('release.* 匹配任意动作', GithubWebhook::eventSubscribed('release', $releaseCreated, ['release.*']));
check('release.published 命中', GithubWebhook::eventSubscribed('release', $releasePublished, ['release.published']));
check('release.created 不命中 published', !GithubWebhook::eventSubscribed('release', $releasePublished, ['release.created']));
check('issues.opened 命中', GithubWebhook::eventSubscribed('issues', $issueOpened, ['issues.opened']));
check('issues.closed 不命中 opened', !GithubWebhook::eventSubscribed('issues', $issueOpened, ['issues.closed']));
check('push 匹配所有 push', GithubWebhook::eventSubscribed('push', $pushMain, ['push']));
check('push:main 命中 main', GithubWebhook::eventSubscribed('push', $pushMain, ['push:main']));
check('push#main 命中 main', GithubWebhook::eventSubscribed('push', $pushMain, ['push#main']));
check('push:main 不命中 dev', !GithubWebhook::eventSubscribed('push', ['ref' => 'refs/heads/dev'], ['push:main']));
check('push:refs/heads/main 命中', GithubWebhook::eventSubscribed('push', $pushMain, ['push:refs/heads/main']));
check('push.created 命中新建分支', GithubWebhook::eventSubscribed('push', $pushCreated, ['push.created']));
check('push.deleted 不命中普通 push', !GithubWebhook::eventSubscribed('push', $pushMain, ['push.deleted']));
check('大小写不敏感', GithubWebhook::eventSubscribed('Release', $releasePublished, ['RELEASE.PUBLISHED']));
check('多规则任一命中', GithubWebhook::eventSubscribed('release', $releasePublished, ['push', 'release.published']));

// 群插件 URL：App\\Plugins\\Fq 必须编码成 App%5CPlugins%5CFq，路由再解码
$router = new Router();
$router->group('/webui/api', function (Router $r): void {
    $r->post('/groups/{group_id}/plugins/{plugin}', static fn() => null, 'groups.plugin');
});
$hit = $router->match('POST', '/webui/api/groups/130558695/plugins/App%5CPlugins%5CFq');
check('编码后的插件类能命中路由', $hit !== null);
check('路由参数被正确解码', ($hit['params']['plugin'] ?? '') === 'App\\Plugins\\Fq');
$bad = $router->match('POST', '/webui/api/groups/130558695/plugins/App/Plugins/Fq');
check('未编码的多段路径（旧 bug）不再命中', $bad === null);

echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
