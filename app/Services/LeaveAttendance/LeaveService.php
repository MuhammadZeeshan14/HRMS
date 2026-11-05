<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\LeaveAttendance\LeaveDecision;
use App\Models\LeaveAttendance\LeavePolicy;
use App\Models\User;
use App\Policies\LeaveAttendancePolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeaveService
{
    public function __construct(private Calendar $calendar) {}

    public function policy(Employee $employee, int $type, bool $lock = false): ?LeavePolicy
    {
        if (! $employee->employment_status_id) {
            return null;
        }
        $query = LeavePolicy::where('employment_status_id', $employee->employment_status_id)->where('leave_type_id', $type);

        return ($lock ? $query->lockForUpdate() : $query)->first();
    }

    public function used(Employee $employee, LeavePolicy $policy, ?int $year = null): float
    {
        $year ??= (int) now()->year;
        $from = sprintf('%04d-01-01', $year);
        $to = sprintf('%04d-12-31', $year);

        return (float) LeaveApplication::where('employee_id', $employee->id)->where('leave_type_id', $policy->leave_type_id)->where('status', 'approved')
            ->where('starts_on', '<=', $to)->where('ends_on', '>=', $from)->get()
            ->sum(fn ($l) => $this->calendar->count(max($l->starts_on, $from), min($l->ends_on, $to), $l->session));
    }

    public function accountingError(Employee $employee, ?LeavePolicy $p, string $from, string $to, float $days): ?string
    {
        if (! $p) {
            return 'A leave policy has not been configured for this HRIS employee status and leave type.';
        }
        if ($p->allowance_kind === 'unspecified') {
            return 'Allowance is unspecified. HR must complete policy setup.';
        }
        if ($p->allowance_kind === 'as_needed') {
            return 'As-needed approval limits are pending a defined business rule.';
        }
        if ($p->allowance === null) {
            return 'Numeric allowance is missing.';
        }
        if ($days <= 0) return null;
        $byYear = [];
        foreach ($this->calendar->days($from, $to) as $date => $units) {
            $year = (int) substr($date, 0, 4);
            $byYear[$year] = ($byYear[$year] ?? 0) + $units;
        }
        foreach ($byYear as $year => $requested) {
            if ($requested > 0 && $requested > (float) $p->allowance - $this->used($employee, $p, $year)) {
                return 'Insufficient leave allowance for calendar year '.$year.'.';
            }
        }

        return null;
    }

    public function validateRange(Employee $employee, array $data, ?int $except = null): float
    {
        if (! empty($data['session'])) {
            throw ValidationException::withMessages(['session' => 'Half-day leave is inactive until session, counting and attendance rules are defined.']);
        }
        $days = $this->calendar->count($data['starts_on'], $data['ends_on']);
        if ($days <= 0) {
            throw ValidationException::withMessages(['starts_on' => 'The selected range contains only days off and cannot be submitted.']);
        }
        if ($employee->date_of_joining && $data['starts_on'] < $employee->date_of_joining->toDateString()) {
            throw ValidationException::withMessages(['starts_on' => 'Leave cannot precede the joining date.']);
        }
        $overlaps = LeaveApplication::where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
            ->where('starts_on', '<=', $data['ends_on'])->where('ends_on', '>=', $data['starts_on'])->when($except, fn ($q) => $q->whereKeyNot($except))->get();
        $requested = array_filter($this->calendar->days($data['starts_on'], $data['ends_on']));
        foreach ($overlaps as $other) {
            if (array_intersect_key($requested, array_filter($this->calendar->days($other->starts_on, $other->ends_on, $other->session)))) {
                throw ValidationException::withMessages(['starts_on' => 'This request overlaps a pending or approved leave application on a working day.']);
            }
        }

        return $days;
    }

    public function submit(Employee $employee, User $actor, array $data): LeaveApplication
    {
        abort_unless(app(LeaveAttendancePolicy::class)->view($actor, $employee), 403);

        return DB::transaction(function () use ($employee, $actor, $data) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $days = $this->validateRange($employee, $data);
            $p = $this->policy($employee, (int) $data['leave_type_id']);
            // Incomplete policies permit a pending request, never approval or deduction.
            if ($p && $p->allowance_kind === 'numeric' && $p->allowance !== null) {
                if ($error = $this->accountingError($employee, $p, $data['starts_on'], $data['ends_on'], $days)) {
                    throw ValidationException::withMessages(['leave_type_id' => $error]);
                }
            }

            return LeaveApplication::create(collect($data)->only(['starts_on', 'ends_on', 'leave_type_id', 'reason', 'form_date'])->all()
                + ['employee_id' => $employee->id, 'requester_id' => $actor->id, 'chargeable_days' => $days, 'status' => 'pending']);
        }, 3);
    }

    public function decide(LeaveApplication $leave, User $actor, string $outcome, string $reason): void
    {
        if (! in_array($outcome, ['approved', 'rejected'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose an outcome and provide a decision reason.']);
        }
        DB::transaction(function () use ($leave, $actor, $outcome, $reason) {
            Employee::whereKey($leave->employee_id)->lockForUpdate()->firstOrFail();
            $leave = LeaveApplication::whereKey($leave->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(LeaveAttendancePolicy::class)->decide($actor, $leave->employee, $leave->requester_id), 403);
            if ($leave->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This application has already been decided.']);
            }
            if ($outcome === 'approved') {
                $days = $this->validateRange($leave->employee, $leave->toArray(), $leave->id);
                $p = $this->policy($leave->employee, $leave->leave_type_id, true);
                if ($error = $this->accountingError($leave->employee, $p, $leave->starts_on, $leave->ends_on, $days)) {
                    throw ValidationException::withMessages(['decision' => $error]);
                }
                $leave->fill(['chargeable_days' => $days, 'policy_id' => $p->id, 'policy_snapshot' => $p->toArray() + ['employee_status_name' => $p->employeeStatus->name, 'balance_basis' => 'calendar_year']]);
            }
            LeaveDecision::create(['application_id' => $leave->id, 'actor_id' => $actor->id, 'outcome' => $outcome, 'reason' => $reason, 'decided_at' => now()]);
            $leave->status = $outcome;
            $leave->save();
        }, 3);
    }
}
