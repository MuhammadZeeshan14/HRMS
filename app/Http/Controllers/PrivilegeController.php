<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Services\EmployeeAccess;
use App\Services\PrivilegeService;
use App\Services\ReportingService;
use Illuminate\Http\Request;

class PrivilegeController extends Controller
{
    public function index(Request $r, EmployeeAccess $access)
    {
        abort_unless($access->levelOne($r->user()), 403);
        $employees = Employee::where('company_id', $r->user()->employee->company_id)->with(['department', 'user'])->get();

        return view('account.access', compact('employees', 'access'));
    }

    public function update(Request $r, Employee $employee, PrivilegeService $service)
    {
        $data = $r->validate(['grant' => 'required|boolean', 'current_password' => 'required|current_password', 'replacements' => 'sometimes|array', 'replacements.*' => 'array:primary_report_to_id,secondary_report_to_id,dual_reporting', 'replacements.*.primary_report_to_id' => 'nullable|integer', 'replacements.*.secondary_report_to_id' => 'nullable|integer', 'replacements.*.dual_reporting' => 'boolean']);
        $service->change($r->user(), $employee, (bool) $data['grant'], $data['replacements'] ?? []);

        return back()->with('success', 'Delegated privileges updated and audited.');
    }

    public function candidates(Request $r, EmployeeAccess $access, ReportingService $reporting)
    {
        abort_unless($access->admin($r->user()), 403);
        $data = $r->validate(['company_id' => 'required|integer', 'department_id' => 'required|integer', 'employee_id' => 'nullable|integer']);
        $e = new Employee($data);
        $e->id = $data['employee_id'] ?? null;
        abort_unless($access->canPlace($r->user(), $e), 403);

        return response()->json(['employees' => $reporting->candidates($e)->map(fn ($m) => ['id' => $m->id, 'name' => $m->display_name.' · '.$m->employee_no])->values(), 'root' => ! $access->ancestors($e->department_id)]);
    }
}
