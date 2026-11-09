<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use Core\Bot\Event;

$pass = 0; $fail = 0;
$check = function (string $name, bool $ok) use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  OK   {$name}\n"; }
    else { $fail++; echo "  FAIL {$name}\n"; }
};

$event = Event::fromArray([
    'post_type' => 'message',
    'message' => [
        ['type' => 'reply', 'data' => ['id' => '123']],
        ['type' => 'text', 'data' => ['text' => 'hello']],
    ],
    'sender' => ['user_id' => 100, 'nickname' => 'n'],
]);

$check('v1 message[0]->type', ($event->message[0]->type ?? null) === 'reply');
$check('v1 message[0]->data->id', ($event->message[0]->data->id ?? null) === '123');
$check('v1 message[1]->data->text', ($event->message[1]->data->text ?? null) === 'hello');
$check('v1 sender->nickname', ($event->sender->nickname ?? null) === 'n');
$check('缺失字段返回 null', $event->not_exists === null);
$check('isset 判断', isset($event->message) && !isset($event->not_exists));
$check('数组访问仍可用', $event->toArray()['message'][0]['type'] === 'reply');
$check('json_encode(input) 返回事件字段', (json_decode((string)json_encode($event), true)['message'][0]['type'] ?? null) === 'reply');


echo PHP_EOL . ($fail === 0 ? '全部通过' : "失败 {$fail} 项") . "，通过 {$pass} 项" . PHP_EOL;
exit($fail === 0 ? 0 : 1);
