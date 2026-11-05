<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function provision(Employee $employee): User
    {
        return DB::transaction(function () use ($employee) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $user = $employee->user;
            if (! $user && $employee->email) {
                $candidate = User::where('email', $employee->email)->first();
                // Email alone does not prove account ownership. Never silently link an orphan.
                if ($candidate) {
                    throw ValidationException::withMessages(['email' => "Employee {$employee->employee_no}: email matches an unlinked account. Resolve ownership with hrms:link-account."]);
                }
            }
            if (! $user) {
                $user = User::create(['name' => $employee->display_name, 'email' => $employee->email ?: 'employee-'.$employee->id.'@accounts.invalid',
                    'password' => Str::password(24), 'role' => 'employee', 'is_active' => $employee->employment_status === 'active']);
                $employee->forceFill(['user_id' => $user->id])->saveQuietly();
            }
            if (! $user->login_id || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $user->login_id)) {
                $base = preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$/D', $employee->employee_no) ? $employee->employee_no : 'EMP-'.$employee->id;
                $id = $base;
                $i = 0;
                while (User::where('login_id', $id)->whereKeyNot($user->id)->exists()) {
                    $id = $base.'-'.(++$i);
                }
                $user->forceFill(['login_id' => $id])->save();
            }

            return $user;
        });
    }

    public function revoke(User $user): void
    {
        DB::table('users')->where('id', $user->id)->increment('session_version', 1, ['remember_token' => Str::random(60), 'updated_at' => now()]);
        $user->refresh();
        DB::table('sessions')->where('user_id', $user->id)->delete();
    }

    public function temporary(Employee $employee, ?User $actor = null, bool $simpleTesting = false): string
    {
        abort_if($simpleTesting && $actor !== null, 403, 'Simple temporary passwords are available only for the explicit testing export.');
        if ($actor) {
            abort_unless(app(EmployeeAccess::class)->canWrite($actor, $employee), 403);
        }

        return DB::transaction(function () use ($employee, $actor, $simpleTesting) {
            $user = $this->provision($employee);
            $words = ['Maple', 'River', 'Cedar', 'Mango', 'Tulip', 'Robin', 'Sunny', 'Cloud', 'Coral', 'Olive', 'Peach', 'Daisy', 'Apple', 'Lemon', 'Tiger', 'Lotus'];
            $password = $simpleTesting ? $words[random_int(0, count($words) - 1)].random_int(1000, 9999) : Str::random(4).Str::password(16);
            $user->forceFill(['password' => Hash::make($password), 'must_change_password' => true])->save();
            $this->revoke($user);
            DB::table('account_access_events')->insert(['employee_id' => $employee->id, 'actor_id' => $actor?->id,
                'action' => $actor ? 'password.reset.assisted' : 'password.reset.testing', 'details' => null, 'created_at' => now()]);

            return $password;
        });
    }

    public function assignCompanyEmail(Employee $employee, string $domain): string
    {
        $domain = strtolower(trim($domain));
        if (! str_contains($domain, '.') || ! filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw ValidationException::withMessages(['email_domain' => 'Provide a valid company email domain without @ or a URL.']);
        }

        return DB::transaction(function () use ($employee, $domain) {
            $employee = Employee::whereKey($employee->id)->lockForUpdate()->firstOrFail();
            $user = $this->provision($employee);
            $base = $employee->is_test_employee ? Str::slug($user->login_id) : Str::slug($employee->display_name, '.');
            $base = substr($base ?: 'employee-'.$employee->id, 0, 48);
            $email = $base.'@'.$domain;
            if (filter_var($employee->email, FILTER_VALIDATE_EMAIL) && str_ends_with(strtolower($employee->email), '@'.$domain)) {
                $email = strtolower($employee->email);
            }
            $suffix = 0;
            while (User::where('email', $email)->whereKeyNot($user->id)->exists()
                || User::where('pending_recovery_email', $email)->whereKeyNot($user->id)->exists()
                || Employee::where('email', $email)->whereKeyNot($employee->id)->exists()) {
                $email = $base.'.'.$employee->id.($suffix ? '.'.$suffix : '').'@'.$domain;
                $suffix++;
            }
            $old = ['employee_email' => $employee->email, 'account_email' => $user->email];
            $employee->update(['email' => $email]);
            // A verified recovery address remains active until the proposed corporate address is verified.
            if ($user->email !== $email) {
                $user->forceFill($user->email_verified_at
                    ? ['pending_recovery_email' => $email]
                    : ['email' => $email, 'email_verified_at' => null, 'pending_recovery_email' => null])->save();
            }
            if ($old['employee_email'] !== $email || $old['account_email'] !== $user->email) {
                DB::table('account_access_events')->insert(['employee_id' => $employee->id, 'actor_id' => null,
                    'action' => 'email.company.assigned', 'details' => json_encode($old + ['company_email' => $email]), 'created_at' => now()]);
            }

            return $email;
        });
    }
}
