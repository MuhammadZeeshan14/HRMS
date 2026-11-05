<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$schema = $argv[1] ?? '';
if (! preg_match('/^hrla_punch_verify_20261104_[a-f0-9]{6}$/D', $schema)) {
    throw new RuntimeException('Refuse non-verification schema');
}
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $schema, 'database.connections.mysql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('mysql');
DB::statement('SET SESSION innodb_lock_wait_timeout=10');
