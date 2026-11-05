<?php

namespace Tests\Support;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Str;

trait EmployeeAccountFixtures
{
    private function linkedHr(?Company $company = null): User
    {
        $company ??= Company::create(['code' => 'FIX-'.Str::random(8), 'name' => 'Account fixture company', 'status' => 'active']);
        $department = Department::create(['code' => 'FIX-'.Str::random(8), 'name' => 'Fixture HR', 'company_id' => $company->id, 'status' => 'active', 'is_hr' => true]);
        $employee = Employee::create(['employee_no' => 'FIX-HR-'.Str::random(8), 'first_name' => 'Fixture', 'last_name' => 'HR', 'company_id' => $company->id, 'department_id' => $department->id, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']);
        $user = $employee->user;
        $user->forceFill(['must_change_password' => false])->save();

        return $user;
    }
}
