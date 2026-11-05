<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PrivilegeService
{
    public function change(User $actor, Employee $target, bool $grant, array $replacements = []): void
    {
        DB::transaction(function () use ($actor, $target, $grant, $replacements) {
            Employee::orderBy('id')->lockForUpdate()->get();
            $actor = $actor->fresh();
            $access = app(EmployeeAccess::class);
            $reporting = app(ReportingService::class);
            $target = $target->fresh();
            abort_unless($access->levelOne($actor) && $actor->employee?->id !== $target->id
                && (int) $actor->employee->company_id === (int) $target->company_id, 403);
            abort_unless(in_array($access->level($target), [2, 3], true), 422, 'Delegation requires a Level 2 or Level 3 employee.');
            $before = $reporting->invalidEdges();
            DB::table('employees')->where('id', $target->id)->update(['delegated_admin' => $grant, 'updated_at' => now()]);
            foreach ($replacements as $id => $fields) {
                $e = Employee::findOrFail($id);
                abort_unless($access->canWrite($actor, $e), 403);
                $e->fill(collect($fields)->only(['primary_report_to_id', 'secondary_report_to_id', 'dual_reporting'])->all());
                $reporting->validate($e);
                $e->save();
            }
            $reporting->protect($before);
            DB::table('account_access_events')->insert(['employee_id' => $target->id, 'actor_id' => $actor->id,
                'action' => $grant ? 'admin.granted' : 'admin.revoked', 'details' => json_encode(['delegated_admin' => $grant]), 'created_at' => now()]);
        }, 3);
    }
}
