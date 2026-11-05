<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\User;
use App\Services\AccountService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class LinkEmployeeAccount extends Command
{
    protected $signature = 'hrms:link-account {employee} {user}';

    protected $description = 'Explicitly link a reviewed orphan account to an employee without copying legacy role privileges';

    public function handle(AccountService $accounts): int
    {
        DB::transaction(function () use ($accounts) {
            $e = Employee::findOrFail($this->argument('employee'));
            $u = User::findOrFail($this->argument('user'));
            if ($e->user_id || $u->employee()->exists()) {
                throw new \RuntimeException('Employee or account is already linked.');
            }
            $e->forceFill(['user_id' => $u->id])->saveQuietly();
            $u->forceFill(['role' => 'employee', 'must_change_password' => true, 'is_active' => $e->employment_status === 'active'])->save();
            $accounts->revoke($u);
            $accounts->provision($e);
            DB::table('account_access_events')->insert(['employee_id' => $e->id, 'actor_id' => null, 'action' => 'account.link.reviewed', 'details' => json_encode(['user_id' => $u->id]), 'created_at' => now()]);
        });
        $this->info('Account linked; access is derived from the employee hierarchy.');

        return 0;
    }
}
