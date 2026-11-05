<?php

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\PunchEvent;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\Location;
use App\Services\LeaveAttendance\AttendanceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! is_dir(storage_path('framework/testing'))) {
    mkdir(storage_path('framework/testing'), 0775, true);
}
$schema = 'hrla_punch_verify_20261104_'.bin2hex(random_bytes(3));
DB::statement('CREATE DATABASE `'.$schema.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
config(['database.default' => 'mysql', 'database.connections.mysql.database' => $schema, 'database.connections.mysql.url' => null, 'cache.default' => 'array', 'session.driver' => 'array']);
DB::purge('mysql');
if (DB::connection()->getDatabaseName() !== $schema) {
    throw new RuntimeException('Wrong database');
}
Artisan::call('migrate', ['--force' => true]);
$c = Company::create(['code' => 'RACE', 'name' => 'Isolated punch verification', 'status' => 'active']);
$r = Department::create(['code' => 'ROOT', 'name' => 'Root', 'company_id' => $c->id, 'status' => 'active']);
$m = Employee::create(['employee_no' => 'BOSS', 'first_name' => 'Race', 'last_name' => 'Manager', 'company_id' => $c->id, 'department_id' => $r->id, 'employment_status' => 'active', 'date_of_joining' => '2026-01-01']);
$d = Department::create(['code' => 'LEAF', 'name' => 'Leaf', 'company_id' => $c->id, 'parent_department_id' => $r->id, 'status' => 'active']);
$l = Location::create(['code' => 'OFFICE', 'name' => 'Office', 'company_id' => $c->id, 'status' => 'active', 'latitude' => 24.8607, 'longitude' => 67.0011]);
$e = Employee::create(['employee_no' => 'WORKER', 'first_name' => 'Race', 'last_name' => 'Worker', 'company_id' => $c->id, 'department_id' => $d->id, 'primary_report_to_id' => $m->id, 'reporting_location_id' => $l->id, 'employment_status' => 'active', 'date_of_joining' => '2026-01-01']);
$s = Shift::create(['name' => 'Day', 'company_id' => $c->id, 'start_time' => '09:00:00', 'end_time' => '17:00:00', 'overnight' => false]);
ShiftAssignment::create(['employee_id' => $e->id, 'shift_id' => $s->id, 'starts_on' => '2026-01-01', 'actor_id' => $m->user_id]);
$race = function (array $inputs) use ($schema, $e) {
    $prefix = storage_path('framework/testing/punch-race-').bin2hex(random_bytes(4));
    $gate = $prefix.'-go';
    $ps = [];
    foreach ($inputs as $i => $input) {
        $ready = $prefix.'-'.$i.'-ready';
        $p = new Process([PHP_BINARY, __DIR__.'/employee-punch-concurrency-worker.php', $schema, (string) $e->user_id, ...$input, $ready, $gate], null, null, null, 30);
        $p->start();
        $ps[] = [$p, $ready];
    }
    $limit = microtime(true) + 20;
    while (count(array_filter($ps, fn ($pair) => file_exists($pair[1]))) !== count($ps)) {
        if (microtime(true) > $limit) {
            throw new RuntimeException('Worker barrier failed');
        }
        foreach ($ps as [$p]) {
            if (! $p->isRunning()) {
                throw new RuntimeException($p->getOutput().$p->getErrorOutput());
            }
        }
        usleep(20000);
    }
    file_put_contents($gate, 'go');
    $results = [];
    foreach ($ps as [$p]) {
        $p->wait();
        if (! $p->isSuccessful()) {
            throw new RuntimeException($p->getOutput().$p->getErrorOutput());
        }
        $raw = trim($p->getOutput());
        $parsed = json_decode($raw, true);
        if (! is_array($parsed)) {
            throw new RuntimeException('Worker failed: '.$raw.$p->getErrorOutput());
        }
        $results[] = $parsed;
    }

    return $results;
};
$uuid = (string) Str::uuid();
$same = $race([['check_in', $uuid, '2026-11-04 09:00:00'], ['check_in', $uuid, '2026-11-04 09:00:00']]);
if ($same[0]['id'] !== $same[1]['id'] || PunchEvent::count() !== 1 || AttendanceVersion::count() !== 1) {
    throw new RuntimeException('Duplicate retry committed twice');
}
$distinct = $race([['check_in', (string) Str::uuid(), '2026-11-04 13:00:00'], ['check_out', (string) Str::uuid(), '2026-11-04 17:00:00']]);
$v = app(AttendanceService::class)->latest($e->id, '2026-11-04');
if (PunchEvent::count() !== 3 || AttendanceVersion::count() !== 3 || $v->time_in !== '2026-11-04 09:00:00' || $v->time_out !== '2026-11-04 17:00:00') {
    throw new RuntimeException('Concurrent distinct punch lost');
}
echo json_encode(['database' => $schema, 'same_submission' => '2 simultaneous requests -> 1 event/version', 'distinct_submissions' => '2 simultaneous requests -> both events preserved', 'effective_day' => ['time_in' => $v->time_in, 'time_out' => $v->time_out], 'production_data_modified' => false], JSON_PRETTY_PRINT).PHP_EOL;
