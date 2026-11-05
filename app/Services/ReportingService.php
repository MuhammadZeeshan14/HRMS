<?php

namespace App\Services;

use App\Models\Employee;
use Illuminate\Validation\ValidationException;

class ReportingService
{
    public function __construct(private EmployeeAccess $access) {}

    public function eligible(Employee $e, Employee $manager): bool
    {
        return $e->id !== $manager->id && (int) $e->company_id === (int) $manager->company_id
            && in_array((int) $manager->department_id, $this->access->ancestors($e->department_id), true)
            && $this->access->source($manager) !== null;
    }

    public function candidates(Employee $e)
    {
        return Employee::where('company_id', $e->company_id)->whereIn('department_id', $this->access->ancestors($e->department_id))->get()->filter(fn ($m) => $this->eligible($e, $m))->values();
    }

    public function errors(Employee $e): array
    {
        $errors = [];
        if (! $e->department_id) {
            $errors['department_id'] = 'Assign a department before choosing reporting persons.';
        } elseif ($this->access->level($e) === null) {
            $errors['department_id'] = 'The department hierarchy contains a cycle, a missing parent, or a parent from another company. Ask an authorized administrator to repair it before changing reporting assignments.';
        }
        $ancestors = $this->access->ancestors($e->department_id);
        if ($ancestors && ! $e->primary_report_to_id) {
            $errors['primary_report_to_id'] = $this->candidates($e)->isEmpty()
                ? 'No eligible reporting Admin exists in an ancestor department. Ask a Level 1 employee to assign an active Admin in that branch.' : 'Choose an eligible primary reporting Admin.';
        }
        if ($e->dual_reporting && ! $e->secondary_report_to_id) {
            $errors['secondary_report_to_id'] = 'Choose an eligible secondary reporting Admin or disable dual reporting.';
        }
        if ($e->secondary_report_to_id && (! $e->dual_reporting || $e->secondary_report_to_id == $e->primary_report_to_id)) {
            $errors['secondary_report_to_id'] = 'Enable dual reporting and select distinct reporting persons.';
        }
        foreach (['primary_report_to_id', 'secondary_report_to_id'] as $field) {
            if (! $e->$field) {
                continue;
            }
            $m = Employee::find($e->$field);
            if (! $m || ! $this->eligible($e, $m)) {
                $errors[$field] = 'Reporting person must be an active Admin in the same company and a strict ancestor department.';
            }
            $pending = [$e->$field];
            $seen = [];
            while ($pending) {
                $id = array_pop($pending);
                if ($e->id && (int) $id === (int) $e->id) {
                    $errors[$field] = 'Reporting relationships cannot contain a cycle.';
                    break;
                }
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $node = Employee::find($id);
                if ($node) {
                    $pending = array_merge($pending, array_filter([$node->primary_report_to_id, $node->secondary_report_to_id]));
                }
            }
        }

        return $errors;
    }

    public function validate(Employee $e): void
    {
        if ($errors = $this->errors($e)) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** Called after a proposed change, inside its transaction. Historical invalid edges are preserved. */
    public function invalidEdges(): array
    {
        $invalid = [];
        foreach (Employee::all() as $e) {
            foreach (['primary_report_to_id', 'secondary_report_to_id'] as $field) {
                if (! $e->$field) {
                    continue;
                }
                $m = Employee::find($e->$field);
                if (! $m || ! $this->eligible($e, $m)) {
                    $invalid[] = "$e->id:$field";
                }
            }
        }

        return $invalid;
    }

    public function protect(array $before): void
    {
        $new = array_diff($this->invalidEdges(), $before);
        if ($new) {
            throw ValidationException::withMessages(['reporting' => 'This change invalidates reporting assignments ('.implode(', ', $new).'). Assign eligible replacements first, or submit replacements in the same access update.']);
        }
    }
}
