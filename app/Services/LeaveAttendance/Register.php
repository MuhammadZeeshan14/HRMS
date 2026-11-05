<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\LeaveAttendance\ShiftAssignment;
use Illuminate\Support\Collection;

class Register
{
    public function __construct(private AttendanceService $attendance, private AttendanceCalculator $calculator, private Calendar $calendar) {}

    public function rows(Collection $employees, string $from, string $to): array
    {
        $ids = $employees->pluck('id');
        $versions = $this->attendance->effective()->with('actor')->whereIn('employee_id', $ids)->whereBetween('shift_date', [$from, $to])->get()->keyBy(fn ($v) => $v->employee_id.'|'.$v->shift_date);
        $assignments = ShiftAssignment::with('shift')->whereIn('employee_id', $ids)->where('starts_on', '<=', $to)->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $from))->orderByDesc('id')->get()->groupBy('employee_id');
        $leaves = LeaveApplication::whereIn('employee_id', $ids)->where('status', 'approved')->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->get()->groupBy('employee_id');
        $dates = $this->calendar->days($from, $to);
        $rows = [];
        foreach ($employees as $employee) {
            foreach ($dates as $date => $working) {
                if ($employee->date_of_joining && $date < $employee->date_of_joining->toDateString()) {
                    continue;
                }
                $version = $versions->get($employee->id.'|'.$date);
                $assignment = $assignments->get($employee->id, collect())->first(fn ($a) => $a->starts_on <= $date && (! $a->ends_on || $a->ends_on >= $date));
                $schedule = $version ? $version->only(['shift_name', 'shift_start', 'shift_end']) : ($assignment ? $this->attendance->snapshot($assignment->shift, $date) : null);
                $leave = $leaves->get($employee->id, collect())->first(fn ($l) => $l->starts_on <= $date && $l->ends_on >= $date);
                $result = $this->calculator->evaluate($date, ! $working, (bool) $leave, $schedule['shift_start'] ?? null, $schedule['shift_end'] ?? null, $version?->time_in, $version?->time_out);
                $rows[] = ['employee' => $employee, 'date' => $date, 'version' => $version, 'schedule' => $schedule, 'leave' => $leave] + $result;
            }
        }

        return $rows;
    }

    public function row(Employee $employee, string $date): array
    {
        $version = $this->attendance->latest($employee->id, $date);
        $schedule = $version ? $version->only(['shift_name', 'shift_start', 'shift_end']) : $this->attendance->schedule($employee->id, $date);
        $leave = LeaveApplication::where('employee_id', $employee->id)->where('status', 'approved')->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();
        $result = $this->calculator->evaluate($date, $this->calendar->off($date), (bool) $leave, $schedule['shift_start'] ?? null, $schedule['shift_end'] ?? null, $version?->time_in, $version?->time_out);

        return ['employee' => $employee, 'date' => $date, 'version' => $version, 'schedule' => $schedule, 'leave' => $leave] + $result;
    }
}
