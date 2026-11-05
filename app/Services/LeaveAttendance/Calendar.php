<?php

namespace App\Services\LeaveAttendance;

use App\Models\LeaveAttendance\Holiday;
use Carbon\CarbonImmutable as Date;
use Illuminate\Validation\ValidationException;

class Calendar
{
    public function date(string $value): Date
    {
        return Date::parse($value, config('app.timezone'));
    }

    public function off(string $date): bool
    {
        $day = $this->date($date);

        return $day->isWeekend() || Holiday::whereMonth('date', $day->month)->whereDay('date', $day->day)->exists();
    }

    public function days(string $from, string $to, ?string $session = null): array
    {
        $start = $this->date($from)->startOfDay();
        $end = $this->date($to)->startOfDay();
        if ($end->lt($start) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['ends_on' => 'Select an inclusive range of at most 367 dates.']);
        }
        $holidays = array_fill_keys(Holiday::pluck('date')->map(fn ($date) => substr($date, 5, 5))->all(), true);
        $days = [];
        for ($d = $start; $d->lte($end); $d = $d->addDay()) {
            $days[$d->toDateString()] = $d->isWeekend() || isset($holidays[$d->format('m-d')]) ? 0.0 : ($session ? 0.5 : 1.0);
        }

        return $days;
    }

    public function count(string $from, string $to, ?string $session = null): float
    {
        return array_sum($this->days($from, $to, $session));
    }
}
