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


require __root__ . '/vendor/autoload.php';


