<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\CorrectionDecision;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\PunchEvent;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Notifications\AttendanceApproval;
use App\Policies\LeaveAttendancePolicy;
use App\Services\EmployeeAccess;
use Carbon\CarbonImmutable as Date;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function effective(): Builder
    {
        return AttendanceVersion::query()->whereNotExists(function ($q) {
            $q->selectRaw('1')->from('hrla_attendance_versions as newer')
                ->whereColumn('newer.employee_id', 'hrla_attendance_versions.employee_id')
                ->whereColumn('newer.shift_date', 'hrla_attendance_versions.shift_date')
                ->where(fn ($q) => $q->whereColumn('newer.committed_at', '>', 'hrla_attendance_versions.committed_at')
                    ->orWhere(fn ($q) => $q->whereColumn('newer.committed_at', 'hrla_attendance_versions.committed_at')->whereColumn('newer.id', '>', 'hrla_attendance_versions.id')));
        });
    }

    public function latest(int $employee, string $date): ?AttendanceVersion
    {
        return AttendanceVersion::where('employee_id', $employee)->where('shift_date', $date)->orderByDesc('committed_at')->orderByDesc('id')->first();
    }

    public function schedule(int $employee, string $date): ?array
    {
        $assignment = ShiftAssignment::with('shift')->where('employee_id', $employee)->effectiveOn($date)->first();
        if (! $assignment) {
            return null;
        }

        return $this->snapshot($assignment->shift, $date);
    }

    public function snapshot(Shift $shift, string $date): array
    {
        $s = Date::parse($date.' '.$shift->start_time, config('app.timezone'));
        $e = Date::parse($date.' '.$shift->end_time, config('app.timezone'))->addDays($shift->overnight ? 1 : 0);

        return ['shift_id' => $shift->id, 'shift_name' => $shift->name, 'shift_start' => $s->toDateTimeString(), 'shift_end' => $e->toDateTimeString()];
    }

    public function validatePunches(array $data, ?array $schedule): void
    {
        if (! $schedule) {
            throw ValidationException::withMessages(['shift_date' => 'Assign an effective shift before recording attendance.']);
        }
        $tz = config('app.timezone');
        $s = Date::parse($schedule['shift_start'], $tz);
        $e = Date::parse($schedule['shift_end'], $tz);
        foreach (['time_in', 'time_out'] as $field) {
            if (empty($data[$field])) {
                continue;
            }
            $p = Date::parse($data[$field], $tz);
            if ($p->lt($s->subDay()) || $p->gt($e->addDay())) {
                throw ValidationException::withMessages([$field => 'Punch must be within one day of the assigned shift. Use full dates for overnight shifts.']);
            }
        }
        if (! empty($data['time_in']) && ! empty($data['time_out']) && Date::parse($data['time_out'], $tz)->lte(Date::parse($data['time_in'], $tz))) {
            throw ValidationException::withMessages(['time_out' => 'Time Out must be later than Time In.']);
        }
    }

    public function append(Employee $employee, User $actor, array $data, string $source, ?int $expected, array $provenance = []): AttendanceVersion
    {
        abort_unless(app(LeaveAttendancePolicy::class)->manage($actor, $employee), 403);

        return DB::transaction(function () use ($employee, $actor, $data, $source, $expected, $provenance) {
            Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(LeaveAttendancePolicy::class)->manage($actor->fresh(), $employee->fresh()), 403);
            $latest = $this->latest($employee->id, $data['shift_date']);
            if ($latest?->id !== $expected) {
                throw ValidationException::withMessages(['base_version_id' => 'Attendance changed since this form or preview was loaded. Reload and review the current record.']);
            }
            $schedule = $latest ? $latest->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->schedule($employee->id, $data['shift_date']);
            $this->validatePunches($data, $schedule);

            return AttendanceVersion::create($schedule + [
                'employee_id' => $employee->id, 'shift_date' => $data['shift_date'],
                'time_in' => $data['time_in'] ?? null, 'time_out' => $data['time_out'] ?? null,
                'reason_type' => $data['reason_type'], 'reason' => $data['reason'] ?? '', 'client_name' => $data['client_name'] ?? null,
                'actor_id' => $actor->id, 'source' => $source, 'committed_at' => now(),
            ] + $provenance);
        }, 3);
    }

    public function request(Employee $employee, User $actor, array $data): CorrectionRequest
    {
        $access = app(Access::class);
        abort_unless(app(EmployeeAccess::class)->enabled($actor) && ! $access->hr($actor) && $actor->employee?->id === $employee->id, 403);
        $this->validateHistoricalDate($data['shift_date'] ?? '');
        if (trim($data['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => 'Explain the missed attendance or correction before submitting.']);
        }
        if ($access->approvers($employee)->isEmpty()) {
            throw ValidationException::withMessages(['employee_id' => 'No eligible approver exists. Ask a Level 1 employee to assign a reporting Admin in your ancestor department, or activate an HR account in your company.']);
        }
        $data['reason'] = $data['reason'] ?? '';

        return DB::transaction(function () use ($employee, $actor, $data) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless(app(EmployeeAccess::class)->enabled($actor) && ! app(Access::class)->hr($actor) && (int) $employee->user_id === (int) $actor->id, 403);
            $this->validateHistoricalDate($data['shift_date']);
            if (app(Access::class)->approvers($employee)->isEmpty()) {
                throw ValidationException::withMessages(['employee_id' => 'No eligible approver exists. Ask a Level 1 employee to assign an eligible reporting Admin, or activate a same-company HR account.']);
            }
            $latest = $this->latest($employee->id, $data['shift_date']);
            if ($latest?->id !== ($data['base_version_id'] ?? null)) {
                throw ValidationException::withMessages(['base_version_id' => 'The current attendance changed. Reload before submitting.']);
            }
            $schedule = $latest ? $latest->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->schedule($employee->id, $data['shift_date']);
            $this->validatePunches($data, $schedule);

            $duplicate = CorrectionRequest::where('employee_id', $employee->id)->where('shift_date', $data['shift_date'])->where('status', 'pending')
                ->where('time_in', $data['time_in'] ?? null)->where('time_out', $data['time_out'] ?? null)->exists();
            if ($duplicate) {
                throw ValidationException::withMessages(['shift_date' => 'An identical attendance request is already pending. Review its status in Attendance History.']);
            }

            $request = CorrectionRequest::create(collect($data)->only(['shift_date', 'base_version_id', 'time_in', 'time_out', 'reason_type', 'reason', 'client_name'])->all()
                + ['employee_id' => $employee->id, 'requester_id' => $actor->id, 'status' => 'pending']);
            foreach (app(Access::class)->approvers($employee) as $recipient) {
                $recipient->notify(new AttendanceApproval($request->id, $employee->display_name));
            }

            return $request;
        }, 3);
    }

    public function validateHistoricalDate(string $date): void
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || $date >= now(config('app.timezone'))->toDateString()) {
            throw ValidationException::withMessages(['shift_date' => 'Edit / Mark Attendance is for previous days only. Use Attendance Check In / Check Out for today.']);
        }
    }

    /** Called within the punch transaction with the employee row locked. */
    public function commitLivePunch(Employee $employee, User $actor, PunchEvent $event): AttendanceVersion
    {
        $access = app(EmployeeAccess::class);
        abort_unless(DB::transactionLevel() > 0 && $access->enabled($actor) && ! $access->admin($actor)
            && (int) $employee->user_id === $actor->id && (int) $event->employee_id === $employee->id, 403);
        $date = $event->attendance_date;
        $latest = AttendanceVersion::where('employee_id', $employee->id)->where('shift_date', $date)->orderByDesc('committed_at')->orderByDesc('id')->lockForUpdate()->first();
        $schedule = $latest ? $latest->only(['shift_id', 'shift_name', 'shift_start', 'shift_end']) : $this->schedule($employee->id, $date);
        $this->validatePunches([$event->action === 'check_in' ? 'time_in' : 'time_out' => $event->recorded_at], $schedule);
        $events = PunchEvent::where('employee_id', $employee->id)->where('attendance_date', $date)->lockForUpdate()->get();
        $ins = array_filter([$events->where('action', 'check_in')->min('recorded_at'), $latest?->time_in]);
        $outs = array_filter([$events->where('action', 'check_out')->max('recorded_at'), $latest?->time_out]);

        return AttendanceVersion::create($schedule + [
            'employee_id' => $employee->id, 'shift_date' => $date,
            'time_in' => $ins ? min($ins) : null, 'time_out' => $outs ? max($outs) : null,
            'reason_type' => 'other', 'reason' => 'Server-recorded '.str_replace('_', ' ', $event->action).' · event '.$event->id,
            'actor_id' => $actor->id, 'source' => 'employee live punch', 'committed_at' => now(),
        ]);
    }

    public function decide(CorrectionRequest $request, User $actor, string $outcome, string $reason): void
    {
        if (! in_array($outcome, ['approved', 'rejected'], true) || trim($reason) === '') {
            throw ValidationException::withMessages(['decision' => 'Choose an outcome and provide a decision reason.']);
        }
        DB::transaction(function () use ($request, $actor, $outcome, $reason) {
            Employee::whereKey($request->employee_id)->lockForUpdate()->firstOrFail();
            $request = CorrectionRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            abort_unless(app(LeaveAttendancePolicy::class)->decide($actor, $request->employee, $request->requester_id), 403);
            if ($request->status !== 'pending') {
                throw ValidationException::withMessages(['decision' => 'This request has already been decided.']);
            }
            if ($outcome === 'approved') {
                $this->append($request->employee, $actor, $request->toArray(), 'approved correction', $request->base_version_id, ['correction_id' => $request->id]);
            }
            CorrectionDecision::create(['correction_id' => $request->id, 'actor_id' => $actor->id, 'outcome' => $outcome, 'reason' => $reason, 'decided_at' => now()]);
            $request->update(['status' => $outcome]);
        }, 3);
    }
}
