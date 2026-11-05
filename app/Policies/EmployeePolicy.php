<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccess;

class EmployeePolicy
{
    public function viewAny(User $u): bool
    {
        return app(EmployeeAccess::class)->admin($u);
    }

    public function view(User $u, Employee $e): bool
    {
        return app(EmployeeAccess::class)->employees($u)->whereKey($e->id)->exists();
    }

    public function create(User $u): bool
    {
        return app(EmployeeAccess::class)->admin($u);
    }

    public function update(User $u, Employee $e): bool
    {
        return app(EmployeeAccess::class)->canWrite($u, $e);
    }
}
