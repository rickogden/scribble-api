<?php


$app = require_once __DIR__.'/../src/app.php';
if(php_sapi_name() === 'cli-server') {
    $app['debug'] = true;
    $filename = __DIR__.preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI']);
    if(is_file($filename)) {
        return false;
    }
}

$app->run();