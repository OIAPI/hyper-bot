<?php
// 公共文件，用于引用;

// error_reporting(0);
ini_set('display_errors', 'Off');

define('__root__', __DIR__);
define('__app__', __DIR__ . '/app');
define('__utils__', __DIR__ . '/utils');
define('__config__', __DIR__ . '/config');
define('__temp__', __DIR__ . '/runtime');
define('__runtime__', __DIR__ . '/runtime');
define('__logger__', __runtime__ . '/log');
define('__message__', __runtime__ . '/message');
define('debug', true);
define('APP_PATH', __app__);

// bin/hyper install 需要在没有 vendor 时也能跑，用常量跳过 autoload
if (!defined('HYPER_SKIP_AUTOLOAD')) {
    // 依赖缺失是最常见的部署故障，且 display_errors 已关，直接给出可操作的提示
    if (!is_file(__root__ . '/vendor/autoload.php')) {
        fwrite(STDERR, '缺少依赖：vendor/ 未安装。请先运行 php bin/hyper install 或 composer install --no-dev -o' . PHP_EOL);
        exit(1);
    }

    require __root__ . '/vendor/autoload.php';
}


