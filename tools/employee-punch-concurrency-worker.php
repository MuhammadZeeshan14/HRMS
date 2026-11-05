<?php

use App\Models\User;
use App\Services\LeaveAttendance\PunchService;

require __DIR__.'/employee-punch-concurrency-bootstrap.php';
$actor = User::findOrFail((int) $argv[2]);
$action = $argv[3];
$uuid = $argv[4];
$time = $argv[5];
$ready = $argv[6];
$gate = $argv[7];
Carbon\Carbon::setTestNow(Carbon\Carbon::parse($time, config('app.timezone')));
file_put_contents($ready, 'ready');
$limit = microtime(true) + 15;
while (! file_exists($gate)) {
    if (microtime(true) > $limit) {
        throw new RuntimeException('Barrier timeout');
    }
    usleep(10000);
}
$event = app(PunchService::class)->record($actor, ['action' => $action, 'submission_id' => $uuid, 'latitude' => 24.86, 'longitude' => 67, 'accuracy' => 8]);
echo json_encode(['id' => $event->id, 'action' => $event->action]);
