#!/usr/bin/env php
<?php

require dirname(__DIR__) . '/vendor/autoload.php';

use he\queue\demo\PHPJob;
use he\queue\Resque;

$REDIS_BACKEND_DB = getenv('REDIS_BACKEND_DB');
$REDIS_BACKEND = getenv('REDIS_BACKEND');
if (!empty($REDIS_BACKEND)) {
    if (empty($REDIS_BACKEND_DB))
        Resque::setBackend($REDIS_BACKEND);
    else
        Resque::setBackend($REDIS_BACKEND, $REDIS_BACKEND_DB);
}

Resque::enqueue('PHPJob', PHPJob::class, ['h1']);
Resque::enqueue('PHPJob', PHPJob::class, ['h2']);
Resque::enqueue('PHPJob', PHPJob::class, ['h3']);