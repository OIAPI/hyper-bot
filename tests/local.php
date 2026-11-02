<?php
require dirname(__DIR__) . "/Include.php";
\Utils\Cache::setLocal("raw_body", "{\"a\":1}");
\Utils\Cache::setLocal("headers", ["authorization" => "Bearer x"]);
var_dump(\Utils\Cache::getLocal("raw_body"));
var_dump(\Utils\Cache::getLocal("headers"));
var_dump(\Utils\Cache::getLocal("missing", "dflt"));
