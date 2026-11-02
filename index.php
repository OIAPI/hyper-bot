<?php

require __DIR__ . '/Include.php';

$Server = new Controller\Server\HttpServer;
$Server->start();