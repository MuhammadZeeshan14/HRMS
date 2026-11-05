<?php

namespace App\Services\LeaveAttendance;

use Carbon\CarbonImmutable as Date;

class AttendanceCalculator
{
    public const STATUSES = ['Day off', 'Leave', 'Missing Time In', 'Missing Time Out', 'Absent', 'Late', 'On Time', 'Early off', 'Missing shift configuration', 'In progress / future', 'Late / Early off', 'On Time / Early off'];

    public function roundPunch(Date $punch): Date
    {
        $seconds = $punch->minute * 60 + $punch->second;

        return $punch->startOfHour()->addSeconds((int) (floor(($seconds + 900) / 1800) * 1800));
    }

    public function evaluate(string $date, bool $off, bool $leave, ?string $start, ?string $end, ?string $in, ?string $out, ?Date $now = null): array
    {
        $tz = config('app.timezone');
        $a = $in ? Date::parse($in, $tz) : null;
        $b = $out ? Date::parse($out, $tz) : null;
        $s = $start ? Date::parse($start, $tz) : null;
        $e = $end ? Date::parse($end, $tz) : null;
        $work = $a && $b ? max(0, (int) $a->diffInSeconds($b, false)) : null;
        $actual = $s && $e ? (int) $s->diffInSeconds($e, false) : null;
        $late = $a && $s ? $this->roundPunch($a)->gt($s->addMinutes(30)) : false;
        $early = $b && $e ? $this->roundPunch($b)->lt($e->subMinutes(30)) : false;
        $status = match (true) {
            $off => 'Day off', $leave => 'Leave',
            $a && ! $b => 'Missing Time Out', ! $a && $b => 'Missing Time In',
            ! $s || ! $e => 'Missing shift configuration',
            ! $a && ! $b && $e->gt($now ?? Date::now($tz)) => 'In progress / future',
            ! $a && ! $b => 'Absent',
            default => implode(' / ', array_filter([$late ? 'Late' : 'On Time', $early ? 'Early off' : null])),
        };

        return ['status' => $status, 'late' => $late, 'early' => $early, 'work_seconds' => $work,
            'actual_seconds' => $actual, 'diff_seconds' => $work !== null && $actual !== null ? $work - $actual : null];
    }

    public static function duration(?int $seconds): string
    {
        if ($seconds === null) {
            return '—';
        }

        return ($seconds < 0 ? '-' : '').sprintf('%d:%02d:%02d', intdiv(abs($seconds), 3600), intdiv(abs($seconds) % 3600, 60), abs($seconds) % 60);
    }
}
