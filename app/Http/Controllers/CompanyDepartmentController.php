<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\EmployeeAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CompanyDepartmentController extends Controller
{
    public function __invoke(Request $request)
    {
        $input = $request->validate([
            'company_id' => ['nullable', 'integer', Rule::exists('companies', 'id')],
            'staff' => ['nullable', Rule::in(['active', 'all'])],
        ]);
        $companies = Company::whereKey($request->user()->employee->company_id)->get();
        abort_if(! empty($input['company_id']) && (int) $input['company_id'] !== (int) $request->user()->employee->company_id, 403);
        $company = $companies->firstWhere('id', $input['company_id'] ?? $companies->first()?->id);
        $staff = $input['staff'] ?? 'active';
        $nodes = [];
        $roots = [];
        $employeeCount = 0;
        $unassigned = collect();

        if ($company) {
            $employees = DB::table('employees')->where('company_id', $company->id)
                ->whereIn('id', app(EmployeeAccess::class)->employees($request->user())->select('id'))
                ->when($staff === 'active', fn ($q) => $q->where('employment_status', 'active'))
                ->orderBy('first_name')->orderBy('last_name')
                ->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name', 'designation_id', 'department_id', 'grade_id', 'primary_report_to_id', 'secondary_report_to_id', 'employment_status']);
            $employeeCount = $employees->count();
            // Only resolve manager names inside the selected company.
            $managers = DB::table('employees')->where('company_id', $company->id)
                ->whereIn('id', app(EmployeeAccess::class)->employees($request->user())->select('id'))
                ->get(['id', 'first_name', 'middle_name', 'last_name'])->keyBy('id');
            $grades = DB::table('grades')->get(['id', 'name', 'rank'])->keyBy('id');
            $levels = DB::table('departments')->where('company_id', $company->id)->orWhereNull('company_id')->orderBy('level')->orderBy('name')->get()->keyBy('id');
            $designationNames = DB::table('designations')->whereIn('id', $employees->pluck('designation_id')->filter()->unique())->pluck('name', 'id');

            foreach ($employees as $employee) {
                $employee->display_name = $this->name($employee);
                $employee->grade_name = $grades[$employee->grade_id]->name ?? null;
                $employee->designation_name = $designationNames[$employee->designation_id] ?? null;
                $employee->department_name = $levels[$employee->department_id]->name ?? null;
                $employee->primary_manager = isset($managers[$employee->primary_report_to_id]) ? $this->name($managers[$employee->primary_report_to_id]) : ($employee->primary_report_to_id ? 'Outside this company' : null);
                $employee->secondary_manager = isset($managers[$employee->secondary_report_to_id]) ? $this->name($managers[$employee->secondary_report_to_id]) : ($employee->secondary_report_to_id ? 'Outside this company' : null);
            }
            foreach ($levels as $level) {
                $nodes['department-'.$level->id] = [
                    'name' => $level->name, 'kind' => 'Department', 'level' => null,
                    'level_number' => $level->level,
                    'parent' => $levels->has($level->parent_department_id) ? 'department-'.$level->parent_department_id : null,
                    'employees' => $employees->where('department_id', $level->id)->values(), 'children' => [], 'note' => null,
                ];
            }
            // Imported data may contain cycles. Break only the display edge, never the data.
            foreach (array_keys($nodes) as $key) {
                $visited = [];
                $cursor = $key;
                while ($cursor !== null && isset($nodes[$cursor])) {
                    if (isset($visited[$cursor])) {
                        $nodes[$key]['parent'] = null;
                        $nodes[$key]['note'] = 'Circular department link — shown at company level for review.';
                        break;
                    }
                    $visited[$cursor] = true;
                    $cursor = $nodes[$cursor]['parent'];
                }
            }
            foreach ($nodes as $key => $node) {
                if ($node['parent'] !== null) {
                    $nodes[$node['parent']]['children'][] = $key;
                } else {
                    $roots[] = $key;
                }
            }
            $unassigned = $employees->filter(fn ($employee) => ! $levels->has($employee->department_id));
        }

        return view('company-department.index', compact('companies', 'company', 'staff', 'nodes', 'roots', 'employeeCount', 'unassigned'));
    }

    private function name(object $employee): string
    {
        return trim(implode(' ', array_filter([$employee->first_name, $employee->middle_name, $employee->last_name])));
    }
}
