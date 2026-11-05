<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\AccountService;
use App\Services\ReportingService;
use Illuminate\Validation\ValidationException;

class EmployeeObserver
{
    public function saving(Employee $e): void
    {
        if (auth()->check() && $e->exists && (int) $e->user_id === (int) auth()->id() && $e->isDirty(['company_id', 'department_id', 'employment_status', 'user_id'])) {
            throw ValidationException::withMessages(['department_id' => 'You cannot change your own organizational placement, account linkage, or employment status. Ask another authorized Admin.']);
        }
        if ($e->isDirty('delegated_admin') && $e->delegated_admin) {
            throw ValidationException::withMessages(['delegated_admin' => 'Use the audited Level 1 privilege workflow.']);
        }
        if ($e->exists && $e->isDirty('delegated_admin')) {
            throw ValidationException::withMessages(['delegated_admin' => 'Use the audited privilege workflow.']);
        }
        if (! $e->exists || $e->isDirty(['company_id', 'department_id', 'primary_report_to_id', 'secondary_report_to_id', 'dual_reporting'])) {
            app(ReportingService::class)->validate($e);
        }
        if ($e->exists && $e->isDirty(['company_id', 'department_id', 'employment_status', 'user_id'])) {
            $reporting = app(ReportingService::class);
            $old = $e->fresh();
            foreach (Employee::where(fn ($q) => $q->where('primary_report_to_id', $e->id)->orWhere('secondary_report_to_id', $e->id))->get() as $child) {
                if ($reporting->eligible($child, $old) && ! $reporting->eligible($child, $e)) {
                    throw ValidationException::withMessages(['department_id' => "Update reporting employee {$child->employee_no} with an eligible replacement before transferring or deactivating this Admin."]);
                }
            }
        }
    }

    public function created(Employee $e): void
    {
        $user = app(AccountService::class)->provision($e);
        $e->user_id = $user->id;
        $e->syncOriginalAttribute('user_id');
        $e->unsetRelation('user');
    }

    public function updated(Employee $e): void
    {
        if ($e->wasChanged('employment_status') && ($user = $e->user()->first())) {
            $user->forceFill(['is_active' => $e->employment_status === 'active'])->save();
            app(AccountService::class)->revoke($user);
            $e->unsetRelation('user');
        }
    }
}
