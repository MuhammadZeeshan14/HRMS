<?php

namespace App\Policies;

use App\Models\Employee;
use App\Models\User;
use App\Services\LeaveAttendance\Access;

class LeaveAttendancePolicy
{
    public function __construct(private Access $access) {}

    public function view(User $user, Employee $employee): bool
    {
        return $this->access->employees($user)->whereKey($employee->id)->exists();
    }

    public function manage(User $user, Employee $employee): bool
    {
        return $this->access->manages($user, $employee);
    }

    public function decide(User $user, Employee $employee, int $requester): bool
    {
        return $user->id !== $requester && $user->employee?->id !== $employee->id && $this->access->approvers($employee)->contains('id', $user->id);
    }

    public function setup(User $user): bool
    {
        return $this->access->hr($user);
    }
}
