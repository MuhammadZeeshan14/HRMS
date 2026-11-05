<?php

namespace App\Services\LeaveAttendance;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccess;
use App\Services\ReportingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class Access
{
    public function hr(User $user): bool
    {
        return app(EmployeeAccess::class)->admin($user);
    }

    public function settings(): object
    {
        return (Schema::hasTable('hrla_settings') ? DB::table('hrla_settings')->first() : null)
            ?? (object) ['secondary_approval' => true, 'hr_leave_auto_approve' => false, 'half_day_rule' => 'disabled'];
    }

    public function employees(User $user, bool $includeSelf = true): Builder
    {
        return app(EmployeeAccess::class)->employees($user, $includeSelf);
    }

    public function manages(User $user, Employee $employee): bool
    {
        return app(EmployeeAccess::class)->canWrite($user, $employee);
    }

    public function approvers(Employee $employee)
    {
        $ids = [$employee->primary_report_to_id];
        if ($employee->dual_reporting && $this->settings()->secondary_approval) {
            $ids[] = $employee->secondary_report_to_id;
        }

        return User::where('is_active', true)->whereHas('employee', fn ($q) => $q->where('company_id', $employee->company_id))->get()->filter(function ($u) use ($employee, $ids) {
            $a = app(EmployeeAccess::class);
            $e = $a->employee($u);

            return $e && $e->id !== $employee->id && ($a->hr($u) || (in_array($e->id, $ids) && app(ReportingService::class)->eligible($employee, $e)));
        });
    }
}
