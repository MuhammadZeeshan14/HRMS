<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\LeaveAttendance\Holiday;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\LeaveAttendance\LeavePolicy;
use App\Models\LeaveAttendance\LeaveType;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Models\User;
use App\Services\EmployeeAccess;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SetupService
{
    public const MODELS = ['shifts' => Shift::class, 'assignments' => ShiftAssignment::class, 'holidays' => Holiday::class, 'types' => LeaveType::class, 'policies' => LeavePolicy::class];

    public function model(string $kind): string
    {
        return self::MODELS[$kind] ?? abort(404);
    }

    public function assignMany(array $data, int $actor): int
    {
        $access = app(Access::class);
        $user = User::findOrFail($actor);
        abort_unless($access->hr($user) && $access->employees($user)->whereIn('id', $data['employee_ids'])->count() === count($data['employee_ids']), 403);

        return DB::transaction(function () use ($data, $actor) {
            $employees = Employee::whereIn('id', $data['employee_ids'])->orderBy('id')->lockForUpdate()->get();
            if ($employees->count() !== count($data['employee_ids'])) {
                throw ValidationException::withMessages(['employee_ids' => 'An employee no longer exists. Refresh the list and select employees again.']);
            }
            Shift::findOrFail($data['shift_id']);
            // Append history. For every covered date, the newest assignment ID wins.
            foreach ($employees as $employee) {
                ShiftAssignment::create([
                    'employee_id' => $employee->id, 'shift_id' => $data['shift_id'],
                    'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null, 'actor_id' => $actor,
                ]);
            }

            return $employees->count();
        }, 3);
    }

    public function editAssignments(array $data, int $actor): int
    {
        $access = app(Access::class);
        $user = User::findOrFail($actor);
        abort_unless($access->hr($user) && ShiftAssignment::whereIn('id', $data['assignment_ids'])->whereIn('employee_id', $access->employees($user)->select('id'))->count() === count($data['assignment_ids']), 403);

        return DB::transaction(function () use ($data, $actor) {
            // Match the existing setup-edit lock order, including edits that move an assignment.
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $selected = ShiftAssignment::whereIn('id', $data['assignment_ids'])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($selected->count() !== count($data['assignment_ids'])) {
                throw ValidationException::withMessages(['assignment_ids' => 'An assignment no longer exists. Refresh and select assignments again.']);
            }
            foreach ($selected as $assignment) {
                if (! empty($data['shift_id'])) {
                    $assignment->shift_id = $data['shift_id'];
                }
                if (! empty($data['starts_on'])) {
                    $assignment->starts_on = $data['starts_on'];
                }
                if ($data['end_action'] !== 'keep') {
                    $assignment->ends_on = $data['end_action'] === 'clear' ? null : $data['ends_on'];
                }
                if ($assignment->ends_on !== null && $assignment->ends_on < $assignment->starts_on) {
                    throw ValidationException::withMessages(['ends_on' => 'No assignments were updated. The end date must be on or after the start date for every selected assignment.']);
                }
            }
            $employeeIds = $selected->pluck('employee_id')->unique();
            $projected = ShiftAssignment::whereIn('employee_id', $employeeIds)->get()
                ->map(fn ($assignment) => $selected->get($assignment->id, $assignment))->groupBy('employee_id');
            $conflicts = [];
            foreach ($projected as $employeeId => $assignments) {
                $previous = null;
                foreach ($assignments->sortBy('starts_on') as $assignment) {
                    if ($previous && ($previous->ends_on === null || $previous->ends_on >= $assignment->starts_on)) {
                        $conflicts[] = $employeeId;
                        break;
                    }
                    $previous = $assignment;
                }
            }
            if ($conflicts) {
                $codes = Employee::whereIn('id', $conflicts)->orderBy('employee_no')->pluck('employee_no')->join(', ');
                throw ValidationException::withMessages(['starts_on' => 'No assignments were updated. The proposed dates overlap for employees: '.$codes.'.']);
            }
            foreach ($selected as $assignment) {
                $assignment->actor_id = $actor;
                $assignment->save();
            }

            return $selected->count();
        }, 3);
    }

    public function delete(string $kind, int $id): void
    {
        $model = $this->model($kind);
        DB::transaction(function () use ($kind, $id, $model) {
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $record = $model::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($kind === 'assignments' && auth()->check()) {
                abort_unless(app(Access::class)->manages(auth()->user(), $record->employee), 403);
            }
            if ($kind === 'holidays' && $this->holidayIntersectsApprovedLeave([$record->date])) {
                throw ValidationException::withMessages(['date' => 'This holiday intersects approved leave and requires a reviewed reconciliation rule.']);
            }
            try {
                $record->delete();
            } catch (QueryException $e) {
                if (! str_starts_with((string) $e->getCode(), '23') && ! str_contains($e->getMessage(), 'FOREIGN KEY')) {
                    throw $e;
                }
                throw ValidationException::withMessages(['configuration' => 'This configuration is referenced by module history and cannot be deleted.']);
            }
        }, 3);
    }

    public function save(string $kind, array $data, ?int $id, int $actor): void
    {
        $access = app(Access::class);
        $user = User::findOrFail($actor);
        abort_unless($access->hr($user), 403);
        if ($kind !== 'assignments') {
            abort_unless(app(EmployeeAccess::class)->configure($user), 403);
        }
        if ($kind === 'assignments') {
            abort_unless($access->employees($user)->whereKey($data['employee_id'])->exists(), 403);
            if ($id) {
                abort_unless(ShiftAssignment::whereKey($id)->whereIn('employee_id', $access->employees($user)->select('id'))->exists(), 403);
            }
            Shift::findOrFail($data['shift_id']);
        }
        $model = $this->model($kind);
        DB::transaction(function () use ($kind, $data, $id, $actor, $model) {
            // Shared lock with leave approval prevents calendar/policy edits racing approval.
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $record = $id ? $model::whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
            if ($kind === 'shifts') {
                if ((! $data['overnight'] && $data['end_time'] <= $data['start_time']) || ($data['overnight'] && $data['end_time'] > $data['start_time'])) {
                    throw ValidationException::withMessages(['end_time' => 'Same-day end must be after start; overnight end must be at or before start on the next date.']);
                }
            }
            if ($kind === 'holidays') {
                // The existing DATE column retains a fixed leap-year anchor, never a selected year.
                $date = sprintf('2000-%02d-%02d', $data['month'], $data['day']);
                if (Holiday::whereMonth('date', $data['month'])->whereDay('date', $data['day'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists()) {
                    throw ValidationException::withMessages(['day' => 'A holiday already exists for this day and month.']);
                }
                if ($this->holidayIntersectsApprovedLeave(array_filter([$date, $record->date]))) {
                    throw ValidationException::withMessages(['date' => 'This recurring holiday intersects approved leave. Calendar amendments need a reviewed reconciliation rule.']);
                }
                $data = ['name' => $data['name'], 'date' => $date];
            }
            if ($kind === 'policies') {
                if ($id && LeaveApplication::where('policy_id', $id)->where('status', 'approved')->exists()) {
                    throw ValidationException::withMessages(['policy' => 'This policy has approved usage. Period rollover and amendments require a reviewed accounting rule.']);
                }
                if ($data['allowance_kind'] !== 'numeric') {
                    $data['allowance'] = null;
                }
                $data['employment_type'] = mb_strtolower(trim(EmploymentStatus::findOrFail($data['employment_status_id'])->name));
            }
            if ($kind === 'assignments') {
                Employee::whereKey($data['employee_id'])->firstOrFail();
                $data['actor_id'] = $actor;
            }
            $record->fill($data)->save();
        }, 3);
    }

    private function holidayIntersectsApprovedLeave(array $dates): bool
    {
        $monthDays = array_unique(array_map(fn ($date) => substr($date, 5, 5), $dates));
        foreach (LeaveApplication::where('status', 'approved')->select(['starts_on', 'ends_on'])->cursor() as $leave) {
            foreach ($monthDays as $monthDay) {
                [$month, $day] = array_map('intval', explode('-', $monthDay));
                for ($year = (int) substr($leave->starts_on, 0, 4); $year <= (int) substr($leave->ends_on, 0, 4); $year++) {
                    $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
                    if (checkdate($month, $day, $year) && $date >= $leave->starts_on && $date <= $leave->ends_on) {
                        return true;
                    }
                }
            }
        }

        return false;
    }
}
