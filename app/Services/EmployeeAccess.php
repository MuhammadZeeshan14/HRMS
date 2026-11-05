<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** All effective permissions are calculated from current database state, never legacy role. */
class EmployeeAccess
{
    public function active(Employee $e): bool
    {
        return $e->employment_status === 'active' && (! $e->user_id || (bool) User::whereKey($e->user_id)->value('is_active'));
    }

    public function level(Employee $e): ?int
    {
        $path = $this->departmentPath($e->department_id, $e->company_id);

        return $path === null ? null : (app(DepartmentLevelService::class)->levels($path)[$e->department_id] ?? null);
    }

    public function ancestors(?int $department): array
    {
        $path = $this->departmentPath($department);

        return $path === null ? [] : $path->skip(1)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
    }

    /** A corrupt imported branch must neither confer ancestry nor disable unrelated companies. */
    private function departmentPath(?int $department, ?int $company = null): ?Collection
    {
        $rows = Department::all(['id', 'parent_department_id', 'company_id'])->keyBy('id');
        $company ??= $rows[$department]->company_id ?? null;
        $path = collect();
        $seen = [];
        $cursor = $department;
        if (! $cursor) {
            return null;
        }
        while ($cursor !== null) {
            if (! isset($rows[$cursor]) || isset($seen[$cursor])) {
                return null;
            }
            $row = $rows[$cursor];
            if ($company && $row->company_id && (int) $row->company_id !== (int) $company) {
                return null;
            }
            $seen[$cursor] = true;
            $path->push($row);
            $cursor = $row->parent_department_id;
        }

        return $path;
    }

    public function source(Employee $e): ?string
    {
        if (! $this->active($e)) {
            return null;
        }
        $d = Department::find($e->department_id);
        if (! $d || $d->status !== 'active' || ($d->company_id && (int) $d->company_id !== (int) $e->company_id)) {
            return null;
        }
        if ($this->level($e) === 1) {
            return 'Level 1';
        }
        if ($d->is_hr) {
            return 'HR';
        }

        return $e->delegated_admin && in_array($this->level($e), [2, 3], true) ? 'delegated' : null;
    }

    public function employee(User $u): ?Employee
    {
        return $u->is_active ? Employee::where('user_id', $u->id)->first() : null;
    }

    public function enabled(User $u): bool
    {
        $e = $this->employee($u);

        return $e && $this->active($e);
    }

    public function admin(User $u): bool
    {
        $e = $this->employee($u);

        return $e && $this->source($e) !== null;
    }

    public function hr(User $u): bool
    {
        $e = $this->employee($u);

        return $e && $this->source($e) && (bool) Department::whereKey($e->department_id)->value('is_hr');
    }

    public function levelOne(User $u): bool
    {
        $e = $this->employee($u);

        return $e && $this->source($e) && $this->level($e) === 1;
    }

    public function configure(User $u): bool
    {
        return $this->admin($u);
    }

    public function departments(User $u): array
    {
        $e = $this->employee($u);
        if (! $e || ! $this->admin($u)) {
            return [];
        }

        return Department::where(fn ($q) => $q->where('company_id', $e->company_id)->orWhereNull('company_id'))->pluck('id')->all();
    }

    public function employees(User $u, bool $self = true): Builder
    {
        $q = Employee::query();
        $e = $this->employee($u);
        if (! $e || ! $this->active($e)) {
            return $q->whereRaw('1=0');
        }
        $q->where('employees.company_id', $e->company_id);
        if (! $this->admin($u)) {
            return $self ? $q->whereKey($e->id) : $q->whereRaw('1=0');
        }

        return $self ? $q : $q->whereKeyNot($e->id);
    }

    public function canWrite(User $u, Employee $e): bool
    {
        return $this->admin($u) && $this->employees($u)->whereKey($e->id)->exists();
    }

    public function canPlace(User $u, Employee $e): bool
    {
        $actor = $this->employee($u);

        return $actor && $this->admin($u) && (int) $e->company_id === (int) $actor->company_id && in_array((int) $e->department_id, array_map('intval', $this->departments($u)), true);
    }
}
