<?php

use Illuminate\Contracts\Console\Kernel;

if ($argc !== 2 || ! str_starts_with($argv[1], '/run/nurselink-backup/')) {
    fwrite(STDERR, "A protected runtime output path is required.\n");
    exit(2);
}

require '/var/www/nurselink-api/vendor/autoload.php';
$app = require '/var/www/nurselink-api/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = config('database.connections.mysql');
if (! is_array($connection) || empty($connection['database']) || empty($connection['username'])) {
    fwrite(STDERR, "The production MySQL configuration is incomplete.\n");
    exit(3);
}

$escape = static fn (mixed $value): string => str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);
$contents = "[client]\n"
    . 'host="' . $escape($connection['host'] ?? '127.0.0.1') . "\"\n"
    . 'port="' . $escape($connection['port'] ?? '3306') . "\"\n"
    . 'user="' . $escape($connection['username']) . "\"\n"
    . 'password="' . $escape($connection['password'] ?? '') . "\"\n";

if (file_put_contents($argv[1], $contents, LOCK_EX) === false || ! chmod($argv[1], 0600)) {
    fwrite(STDERR, "Unable to write protected MySQL client configuration.\n");
    exit(4);
}

fwrite(STDOUT, (string) $connection['database']);

