<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = '/var/www/nurselink-api/storage/framework/maintenance.php')) {
    require $maintenance;
}

require '/var/www/nurselink-api/vendor/autoload.php';

/** @var Application $app */
$app = require_once '/var/www/nurselink-api/bootstrap/app.php';

$app->handleRequest(Request::capture());