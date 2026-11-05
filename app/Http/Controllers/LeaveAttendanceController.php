<?php

namespace App\Http\Controllers;

use App\Http\Requests\LeaveAttendance\AttendanceRequest;
use App\Http\Requests\LeaveAttendance\BulkShiftAssignmentEditRequest;
use App\Http\Requests\LeaveAttendance\BulkShiftAssignmentRequest;
use App\Http\Requests\LeaveAttendance\DecisionRequest;
use App\Http\Requests\LeaveAttendance\ImportReviewRequest;
use App\Http\Requests\LeaveAttendance\LeaveRequest;
use App\Http\Requests\LeaveAttendance\SetupRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\LeaveAttendance\AttendanceVersion;
use App\Models\LeaveAttendance\CorrectionRequest;
use App\Models\LeaveAttendance\ImportBatch;
use App\Models\LeaveAttendance\LeaveApplication;
use App\Models\LeaveAttendance\LeavePolicy;
use App\Models\LeaveAttendance\LeaveType;
use App\Models\LeaveAttendance\Shift;
use App\Models\LeaveAttendance\ShiftAssignment;
use App\Policies\LeaveAttendancePolicy;
use App\Services\EmployeeAccess;
use App\Services\LeaveAttendance\Access;
use App\Services\LeaveAttendance\AttendanceCalculator;
use App\Services\LeaveAttendance\AttendanceService;
use App\Services\LeaveAttendance\Calendar;
use App\Services\LeaveAttendance\ImportFormats;
use App\Services\LeaveAttendance\ImportInterpreter;
use App\Services\LeaveAttendance\ImportService;
use App\Services\LeaveAttendance\LeaveService;
use App\Services\LeaveAttendance\Register;
use App\Services\LeaveAttendance\SetupService;
use App\Services\LeaveAttendance\SpreadsheetReader;
use App\Support\HrlaTime;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LeaveAttendanceController extends Controller
{
    public function __construct(private Access $access, private LeaveAttendancePolicy $policy, private AttendanceService $attendance, private Calendar $calendar) {}

    private function ready(Request $request): void
    {
        abort_unless($request->user()->is_active, 403);
        if (! Schema::hasTable('hrla_shifts')) {
            abort(response()->view('leave-attendance.unavailable', [], 503));
        }
    }

    private function employee(Request $r, bool $self = false): Employee
    {
        if ($self) {
            return $r->user()->employee ?? abort(403, 'Your user account is not linked to an employee.');
        }
        $r->validate(['employee_id' => ['required', 'integer']]);

        return $this->access->employees($r->user())->whereKey($r->integer('employee_id'))->first() ?? abort(403);
    }

    public function dashboard(Request $r)
    {
        $this->ready($r);
        $today = now()->toDateString();
        $employees = $this->access->employees($r->user());
        $leaves = LeaveApplication::whereIn('employee_id', (clone $employees)->select('id'));
        $corrections = CorrectionRequest::whereIn('employee_id', (clone $employees)->select('id'));
        $metrics = [
            'employees' => (clone $employees)->count(),
            'attendance' => AttendanceVersion::whereIn('employee_id', (clone $employees)->select('id'))->where('shift_date', $today)->distinct()->count('employee_id'),
            'on_leave' => (clone $leaves)->where('status', 'approved')->where('starts_on', '<=', $today)->where('ends_on', '>=', $today)->distinct()->count('employee_id'),
            'pending_leaves' => (clone $leaves)->where('status', 'pending')->count(),
            'pending_corrections' => (clone $corrections)->where('status', 'pending')->count(),
        ];
        $recentLeaves = $leaves->with(['employee', 'leaveType'])->latest('id')->limit(5)->get();
        $recentCorrections = $corrections->with('employee')->latest('id')->limit(5)->get();

        return view('leave-attendance.dashboard', compact('today', 'metrics', 'recentLeaves', 'recentCorrections'));
    }

    public function setupOverview(Request $r)
    {
        return $this->setup($r, app(SetupService::class), app(EmployeeAccess::class)->configure($r->user()) ? 'shifts' : 'assignments');
    }

    public function register(Request $r, Register $register)
    {
        $this->ready($r);
        // Accept older single-value URLs as well as multi-select query arrays.
        foreach (['employee_id', 'company_id', 'department_id', 'status'] as $field) {
            $value = $r->input($field, []);
            $values = is_array($value) ? $value : [$value];
            $r->merge([$field => array_values(array_filter($values, fn ($item) => $item !== null && $item !== ''))]);
        }
        $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['array', 'max:500'], 'company_id' => ['array', 'max:500'], 'department_id' => ['array', 'max:500'],
            'employee_id.*' => ['integer', 'min:1', 'distinct'], 'company_id.*' => ['integer', 'min:1', 'distinct'], 'department_id.*' => ['integer', 'min:1', 'distinct'],
            'status' => ['array', 'max:12'], 'status.*' => ['string', 'distinct', Rule::in(AttendanceCalculator::STATUSES)], 'page' => ['nullable', 'integer', 'min:1']]);
        $from = $r->input('from', now()->toDateString());
        $to = $r->input('to', $from);
        if ($this->calendar->date($from)->diffInDays($this->calendar->date($to), false) > 30) {
            throw ValidationException::withMessages(['to' => 'Attendance reports support at most 31 dates per request.']);
        }
        $employees = $this->access->employees($r->user())->with(['company', 'department', 'designation', 'location'])->orderBy('employee_no');
        if ($r->input('employee_id') !== []) {
            abort_unless($this->access->employees($r->user())->whereIn('id', $r->input('employee_id'))->count() === count($r->input('employee_id')), 403);
            $employees->whereIn('id', $r->input('employee_id'));
        }
        foreach (['company_id', 'department_id'] as $field) {
            if ($r->input($field) !== []) {
                $employees->whereIn($field, $r->input($field));
            }
        }
        $employees = $employees->get();
        $days = array_keys($this->calendar->days($from, $to));
        if (count($days) * $employees->count() > 10000) {
            throw ValidationException::withMessages(['from' => 'Narrow the report to at most 10,000 employee dates.']);
        }
        $statuses = $r->input('status', []);
        $rows = array_values(array_filter($register->rows($employees, $from, $to), fn ($row) => ! $statuses || collect($statuses)->contains(fn ($status) => str_contains($row['status'], $status))));
        if ($r->boolean('export')) {
            return $this->export($rows);
        }
        $records = new LengthAwarePaginator(array_slice($rows, ($r->integer('page', 1) - 1) * 50, 50), count($rows), 50, $r->integer('page', 1), ['path' => $r->url(), 'query' => $r->query()]);
        $options = $this->options($r);

        return view('leave-attendance.register', compact('records', 'from', 'to', 'options'));
    }

    private function export(array $rows)
    {
        return response()->streamDownload(function () use ($rows) {
            $f = fopen('php://output', 'w');
            fputcsv($f, ['Code', 'Name', 'Company', 'Department', 'Date', 'Shift', 'Shift Start', 'Shift End', 'Time In', 'Time Out', 'Work Hours', 'Actual Hours', 'Diff', 'Day Status', 'Upload Date', 'Uploaded By']);
            foreach ($rows as $r) {
                $v = $r['version'];
                $s = $r['schedule'];
                $data = [$r['employee']->employee_no, $r['employee']->display_name, $r['employee']->company?->name, $r['employee']->department?->name, $r['date'], $s['shift_name'] ?? null, HrlaTime::stamp($s['shift_start'] ?? null, ''), HrlaTime::stamp($s['shift_end'] ?? null, ''), HrlaTime::stamp($v?->time_in, ''), HrlaTime::stamp($v?->time_out, ''),
                    AttendanceCalculator::duration($r['work_seconds']), AttendanceCalculator::duration($r['actual_seconds']), AttendanceCalculator::duration($r['diff_seconds']), $r['status'], HrlaTime::stamp($v?->committed_at, ''), $v?->actor?->name];
                fputcsv($f, array_map(fn ($value) => preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : $value, $data));
            }
            fclose($f);
        }, 'attendance.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function options(Request $r): array
    {
        $employees = $this->access->employees($r->user())->with(['department', 'designation', 'location'])->orderBy('employee_no')->get();

        return ['employees' => $employees, 'companies' => Company::whereIn('id', $employees->pluck('company_id')->filter()->unique())->get(),
            'departments' => Department::whereIn('id', $employees->pluck('department_id')->filter()->unique())->get()];
    }

    public function attendanceForm(Request $r, Register $register)
    {
        $this->ready($r);
        $direct = $this->access->hr($r->user());
        abort_if($r->boolean('direct') && ! $direct, 403);
        $options = $this->options($r);
        if ($direct) {
            $options['employees'] = $options['employees']->filter(fn ($e) => $this->policy->manage($r->user(), $e));
        }
        $employee = $direct ? ($r->filled('employee_id') ? $this->employee($r) : $options['employees']->first()) : $this->employee($r, true);
        if ($direct && $employee) {
            abort_unless($this->policy->manage($r->user(), $employee), 403);
        }
        $r->validate(['shift_date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $r->input('shift_date', $direct ? now()->toDateString() : now()->subDay()->toDateString());
        if (! $direct) {
            $this->attendance->validateHistoricalDate($date);
        }
        $current = $employee ? $this->attendance->latest($employee->id, $date) : null;
        $employee?->loadMissing(['department', 'designation', 'location', 'primaryReport']);
        $schedule = $current ? $current->only(['shift_name', 'shift_start', 'shift_end']) : ($employee ? $this->attendance->schedule($employee->id, $date) : null);
        $recentRows = $employee ? array_reverse($register->rows(collect([$employee]), $this->calendar->date($date)->subDays(6)->toDateString(), $date)) : [];

        return view($direct ? 'leave-attendance.attendance-form' : 'leave-attendance.employee.edit', compact('direct', 'options', 'employee', 'date', 'current', 'schedule', 'recentRows'));
    }

    public function attendanceStore(AttendanceRequest $r)
    {
        $this->ready($r);
        $data = $r->validated();
        $direct = $this->access->hr($r->user());
        abort_if($r->boolean('direct') && ! $direct, 403);
        if ($direct) {
            $e = $r->filled('employee_id') ? $this->employee($r) : $this->employee($r, true);
            abort_unless($this->policy->manage($r->user(), $e), 403);
            $this->attendance->append($e, $r->user(), $data, 'direct entry', $data['base_version_id'] ?? null);
        } else {
            $this->attendance->request($this->employee($r, true), $r->user(), $data);
        }

        return redirect()->route('la.corrections')->with('success', $direct ? 'Attendance version committed.' : 'Correction submitted to HR and authorized supervisors.');
    }

    public function corrections(Request $r)
    {
        $this->ready($r);
        $query = $this->inboxQuery($r, CorrectionRequest::class, 'shift_date', 'shift_date');
        if ($r->boolean('export')) {
            return $this->inboxExport($query, false);
        }
        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $options = $this->options($r);

        return view('leave-attendance.corrections', compact('records', 'options'));
    }

    public function correctionDecision(DecisionRequest $r, CorrectionRequest $correction)
    {
        $this->ready($r);
        $this->attendance->decide($correction, $r->user(), $r->validated('outcome'), $r->validated('reason'));

        return back()->with('success', 'Correction decision recorded.');
    }

    public function history(Request $r, Employee $employee)
    {
        $this->ready($r);
        abort_unless($this->policy->view($r->user(), $employee), 403);
        $r->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $records = AttendanceVersion::with(['actor', 'correction.decision.actor'])->where('employee_id', $employee->id)->when($r->filled('date'), fn ($q) => $q->where('shift_date', $r->input('date')))->orderByDesc('committed_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('leave-attendance.history', compact('records', 'employee'));
    }

    public function leaveForm(Request $r)
    {
        $this->ready($r);
        $options = $this->options($r);
        $employee = $r->filled('employee_id') ? $this->employee($r) : ($r->user()->employee ?? $options['employees']->first());
        $types = LeaveType::orderBy('name')->get();

        return view('leave-attendance.leave-form', compact('options', 'employee', 'types'));
    }

    public function leavePreview(Request $r)
    {
        $this->ready($r);
        $this->employee($r);
        $r->validate(['starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on']);

        return response()->json(['days' => $this->calendar->count($r->input('starts_on'), $r->input('ends_on'))]);
    }

    public function leaveStore(LeaveRequest $r, LeaveService $service)
    {
        $this->ready($r);
        // Employee subjects are bound to the session unless an authorized manager explicitly selects another employee.
        $e = $r->filled('employee_id') ? $this->employee($r) : $this->employee($r, true);
        if ($e->user_id !== $r->user()->id) {
            abort_unless($this->policy->manage($r->user(), $e), 403);
        }
        $service->submit($e, $r->user(), $r->validated());

        return redirect()->route('la.leaves')->with('success', 'Leave application submitted for approval. Incomplete policies must be configured before approval.');
    }

    public function leaves(Request $r)
    {
        $this->ready($r);
        $query = $this->inboxQuery($r, LeaveApplication::class, 'starts_on', 'ends_on')->with('leaveType');
        if ($r->boolean('export')) {
            return $this->inboxExport($query, true);
        }
        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $options = $this->options($r);

        return view('leave-attendance.leaves', compact('records', 'options'));
    }

    private function inboxQuery(Request $r, string $model, string $start, string $end): Builder
    {
        $r->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'employee_id' => ['nullable', 'integer'], 'company_id' => ['nullable', 'integer'], 'department_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:pending,approved,rejected']]);
        $employees = $this->access->employees($r->user());
        if ($r->filled('employee_id')) {
            abort_unless($this->access->employees($r->user())->whereKey($r->integer('employee_id'))->exists(), 403);
            $employees->whereKey($r->integer('employee_id'));
        }
        foreach (['company_id', 'department_id'] as $field) {
            if ($r->filled($field)) {
                $employees->where($field, $r->integer($field));
            }
        }

        return $model::with(['employee.department', 'requester', 'decision.actor'])->whereIn('employee_id', $employees->select('id'))
            ->when($r->filled('from'), fn ($q) => $q->where($end, '>=', $r->input('from')))
            ->when($r->filled('to'), fn ($q) => $q->where($start, '<=', $r->input('to')))
            ->when($r->filled('status'), fn ($q) => $q->where('status', $r->input('status')));
    }

    private function inboxExport(Builder $query, bool $leave)
    {
        $records = $query->orderBy('id')->limit(10001)->get();
        if ($records->count() > 10000) {
            throw ValidationException::withMessages(['export' => 'Narrow export filters to at most 10,000 requests.']);
        }

        return response()->streamDownload(function () use ($records, $leave) {
            $file = fopen('php://output', 'w');
            fputcsv($file, ['ID', 'Code', 'Name', 'Department', 'From', 'To', 'Leave Type / Reason Type', 'Chargeable Days', 'Time In', 'Time Out', 'Reason', 'Client Name', 'Status', 'Requested By', 'Decision By', 'Decision Date', 'Decision Reason']);
            foreach ($records as $record) {
                $values = [$record->id, $record->employee->employee_no, $record->employee->display_name, $record->employee->department?->name,
                    $leave ? $record->starts_on : $record->shift_date, $leave ? $record->ends_on : $record->shift_date, $leave ? $record->leaveType->name : $record->reason_type,
                    $leave ? $record->chargeable_days : null, $leave ? null : HrlaTime::stamp($record->time_in, ''), $leave ? null : HrlaTime::stamp($record->time_out, ''), $record->reason, $leave ? null : $record->client_name, $record->status,
                    $record->requester?->name, $record->decision?->actor?->name, HrlaTime::stamp($record->decision?->decided_at, ''), $record->decision?->reason];
                fputcsv($file, array_map(fn ($value) => preg_match('/^[\s]*[=+\-@]/u', (string) $value) ? "'".$value : $value, $values));
            }
            fclose($file);
        }, $leave ? 'leave-applications.csv' : 'attendance-corrections.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function leaveDecision(DecisionRequest $r, LeaveApplication $leave, LeaveService $service)
    {
        $this->ready($r);
        $service->decide($leave, $r->user(), $r->validated('outcome'), $r->validated('reason'));

        return back()->with('success', 'Leave decision recorded.');
    }

    public function balances(Request $r, LeaveService $service)
    {
        $this->ready($r);
        $r->validate(['balance_year' => ['nullable', 'integer', 'between:1900,9999']]);
        $balanceYear = $r->filled('balance_year') ? $r->integer('balance_year') : (int) now()->year;
        $options = $this->options($r);
        $employee = $r->filled('employee_id') ? $this->employee($r) : ($r->user()->employee ?? $options['employees']->first());
        $balances = [];
        if ($employee) {
            foreach (LeaveType::orderBy('name')->get() as $type) {
                $policy = $service->policy($employee, $type->id);
                $used = $policy ? $service->used($employee, $policy, $balanceYear) : null;
                $error = $service->accountingError($employee, $policy, $balanceYear.'-01-01', $balanceYear.'-12-31', 0);
                $balances[] = compact('type', 'policy', 'used', 'error');
            }
        }

        return view('leave-attendance.balances', compact('options', 'employee', 'balances', 'balanceYear'));
    }

    public function setup(Request $r, SetupService $service, string $kind = 'shifts')
    {
        if ($kind !== 'assignments') {
            abort_unless(app(EmployeeAccess::class)->configure($r->user()), 403);
        }
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()), 403);
        $r->validate(['q' => ['nullable', 'string', 'max:255'], 'edit' => ['nullable', 'integer']]);
        $model = $service->model($kind);
        $edit = $r->filled('edit') ? $model::findOrFail($r->integer('edit')) : null;
        if ($kind === 'assignments' && $edit) {
            abort_unless($this->access->manages($r->user(), $edit->employee), 403);
        }
        $query = $model::query();
        if ($kind === 'assignments') {
            $query->whereIn('employee_id', $this->access->employees($r->user())->select('id'));
            $query->with(['employee', 'shift']);
        } elseif ($kind === 'policies') {
            $query->with(['leaveType', 'employeeStatus']);
        }
        if ($r->filled('q')) {
            $term = '%'.$r->input('q').'%';
            $query->where(function ($query) use ($kind, $term) {
                if ($kind === 'assignments') {
                    $query->whereHas('employee', fn ($employee) => $employee->where('employee_no', 'like', $term)->orWhere('first_name', 'like', $term)->orWhere('last_name', 'like', $term))
                        ->orWhereHas('shift', fn ($shift) => $shift->where('name', 'like', $term));
                } elseif ($kind === 'policies') {
                    $query->whereHas('employeeStatus', fn ($status) => $status->where('name', 'like', $term))->orWhere('employment_type', 'like', $term)->orWhereHas('leaveType', fn ($type) => $type->where('name', 'like', $term)->orWhere('code', 'like', $term));
                } else {
                    $query->where('name', 'like', $term);
                    if ($kind === 'types') {
                        $query->orWhere('code', 'like', $term);
                    }
                }
            });
        }
        $records = $query->orderByDesc('id')->paginate(25)->withQueryString();
        $masters = config('hrla_setup');
        if (! app(EmployeeAccess::class)->configure($r->user())) {
            $masters = ['assignments' => $masters['assignments']];
        }
        $config = $masters[$kind];
        $counts = [];
        foreach (SetupService::MODELS as $key => $setupModel) {
            $counts[$key] = $key === 'assignments' ? $setupModel::whereIn('employee_id', $this->access->employees($r->user())->select('id'))->count() : $setupModel::count();
        }
        $options = $this->options($r);
        $types = LeaveType::all();
        $shifts = Shift::all();
        $employeeStatuses = EmploymentStatus::orderBy('name')->get();

        $selectedStatus = null;
        $policyEmployee = null;
        $policyEmployees = collect();
        $policyRows = collect();
        $balanceYear = (int) now()->year;
        if ($kind === 'policies') {
            $r->validate(['employment_status_id' => ['nullable', 'integer', 'exists:employment_statuses,id'], 'employee_id' => ['nullable', 'integer'], 'balance_year' => ['nullable', 'integer', 'between:1900,9999']]);
            $balanceYear = $r->filled('balance_year') ? $r->integer('balance_year') : (int) now()->year;
            $statusId = $r->input('employment_status_id') ?: ($edit?->employment_status_id ?? old('employment_status_id'));
            $selectedStatus = $employeeStatuses->firstWhere('id', $statusId);
            if ($selectedStatus) {
                $policyEmployees = $this->access->employees($r->user())->where('employment_status_id', $selectedStatus->id)->orderBy('employee_no')->get();
                if ($r->filled('employee_id')) {
                    $policyEmployee = $policyEmployees->firstWhere('id', $r->integer('employee_id'));
                    if (! $policyEmployee) {
                        throw ValidationException::withMessages(['employee_id' => 'Choose an employee with the selected HRIS employee status.']);
                    }
                } else {
                    $policyEmployee = $policyEmployees->firstWhere('user_id', $r->user()->id) ?? $policyEmployees->first();
                }
                $policies = LeavePolicy::where('employment_status_id', $selectedStatus->id)->get()->keyBy('leave_type_id');
                $leaveService = app(LeaveService::class);
                $policyRows = $types->sortBy('name')->map(function ($type) use ($policies, $policyEmployee, $leaveService, $balanceYear) {
                    $policy = $policies->get($type->id);
                    $used = $policyEmployee && $policy ? $leaveService->used($policyEmployee, $policy, $balanceYear) : null;
                    $remaining = $used !== null && $policy->allowance_kind === 'numeric' && $policy->allowance !== null
                        ? (float) $policy->allowance - $used : null;

                    return compact('type', 'policy', 'used', 'remaining');
                });
            } elseif ($r->filled('employee_id')) {
                throw ValidationException::withMessages(['employment_status_id' => 'Select an employee status first.']);
            }
        }

        $assignmentEmployees = null;
        $currentAssignments = collect();
        if ($kind === 'assignments') {
            $r->validate([
                'employee_q' => ['nullable', 'string', 'max:255'], 'company_id' => ['nullable', 'integer', 'exists:companies,id'],
                'department_id' => ['nullable', 'integer', 'exists:departments,id'],
                'status' => ['nullable', 'in:active,inactive,on_leave,exited'],
                'current_shift' => ['nullable', 'regex:/^(unassigned|[0-9]+)$/'],
                'assignment_on' => ['nullable', 'date_format:Y-m-d'],
            ]);
            $today = $r->input('assignment_on') ?: now()->toDateString();
            $current = ShiftAssignment::effectiveOn($today);
            $employeesQuery = $this->access->employees($r->user())->with(['company', 'department'])->search($r->input('employee_q'))
                ->filters($r->only('company_id', 'department_id', 'status'));
            if ($r->filled('current_shift')) {
                if ($r->input('current_shift') === 'unassigned') {
                    $employeesQuery->whereNotIn('id', (clone $current)->select('employee_id'));
                } else {
                    $employeesQuery->whereIn('id', (clone $current)->where('shift_id', $r->input('current_shift'))->select('employee_id'));
                }
            }
            $assignmentEmployees = $employeesQuery->orderBy('employee_no')->paginate(50, ['*'], 'employees_page')->withQueryString();
            $currentAssignments = (clone $current)->with('shift')->whereIn('employee_id', $assignmentEmployees->modelKeys())->get()->keyBy('employee_id');
        }

        return view('leave-attendance.setup', compact('kind', 'edit', 'records', 'options', 'types', 'shifts', 'employeeStatuses', 'masters', 'config', 'counts', 'assignmentEmployees', 'currentAssignments', 'selectedStatus', 'policyEmployee', 'policyEmployees', 'policyRows', 'balanceYear'));
    }

    public function assignShifts(BulkShiftAssignmentRequest $r, SetupService $service)
    {
        $this->ready($r);
        $count = $service->assignMany($r->validated(), $r->user()->id);

        return back()->with('success', 'Shift set for '.$count.' '.str('employee')->plural($count).'. The latest assignment takes priority for its effective dates.')->with('shift_assignment_saved', true);
    }

    public function setupSave(SetupRequest $r, SetupService $service, string $kind, ?int $id = null)
    {
        $this->ready($r);
        $service->save($kind, $r->validated(), $id, $r->user()->id);

        $parameters = ['kind' => $kind];
        if ($kind === 'policies') {
            $parameters['employment_status_id'] = $r->validated('employment_status_id');
            $year = $r->integer('_balance_year');
            if ($year >= 1900 && $year <= 9999) {
                $parameters['balance_year'] = $year;
            }
            $employeeId = $r->integer('_balance_employee_id');
            if ($this->access->employees($r->user())->whereKey($employeeId)->where('employment_status_id', $parameters['employment_status_id'])->exists()) {
                $parameters['employee_id'] = $employeeId;
            }
        }

        return redirect()->route('la.setup', $parameters)->with('success', 'Configuration saved.');
    }

    public function editShifts(BulkShiftAssignmentEditRequest $r, SetupService $service)
    {
        $this->ready($r);
        $count = $service->editAssignments($r->validated(), $r->user()->id);

        return back()->with('success', $count.' shift '.str('assignment')->plural($count).' updated.')->with('shift_assignment_edit_saved', true);
    }

    public function setupDelete(Request $r, SetupService $service, string $kind, int $id)
    {
        if ($kind !== 'assignments') {
            abort_unless(app(EmployeeAccess::class)->configure($r->user()), 403);
        }
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()), 403);
        $statusId = $kind === 'policies' ? LeavePolicy::findOrFail($id)->employment_status_id : null;
        $service->delete($kind, $id);

        return redirect()->route('la.setup', ['kind' => $kind, 'employment_status_id' => $statusId])->with('success', 'Unused configuration deleted.');
    }

    public function importForm(Request $r)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()), 403);
        $batches = ImportBatch::where('actor_id', $r->user()->id)->orderByDesc('id')->paginate(10);

        return view('leave-attendance.import', compact('batches'));
    }

    public function importUpload(Request $r, SpreadsheetReader $reader, ImportService $service)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()), 403);
        $r->validate(['file' => ['required', 'file', 'extensions:'.implode(',', array_keys(SpreadsheetReader::FORMATS)), 'max:5120']]);
        $file = $r->file('file');
        [$headers,$rows,$source] = $reader->read($file->getRealPath(), $file->getClientOriginalExtension());
        $hash = hash_file('sha256', $file->getRealPath());
        $suggested = app(ImportInterpreter::class)->suggest($headers, $rows, $r->user());
        $suggested['_source'] = $source;
        $batch = ImportBatch::firstOrCreate(['fingerprint' => $hash], ['filename' => basename($file->getClientOriginalName()), 'actor_id' => $r->user()->id, 'headers' => $headers, 'rows' => $rows, 'mapping' => $suggested, 'status' => 'uploaded']);
        abort_unless($batch->actor_id === $r->user()->id, 403, 'This file was already processed by another HR user.');

        return redirect()->route('la.import.preview', $batch);
    }

    public function importPreview(Request $r, ImportBatch $batch, ImportService $service)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()) && $batch->actor_id === $r->user()->id, 403);
        $mapping = $batch->mapping ?? app(ImportInterpreter::class)->suggest($batch->headers, $batch->rows, $r->user());
        $r->validate(['page' => ['nullable', 'integer', 'between:1,40'], 'filter' => ['nullable', 'in:all,valid,invalid,excluded']]);
        $allRows = collect($batch->status === 'committed' ? ($batch->results['rows'] ?? []) : ($batch->results ?? []));
        $summary = ['total' => $allRows->count(), 'valid' => 0, 'invalid' => 0, 'excluded' => 0];
        foreach ($allRows as $row) {
            $summary[! empty($row['excluded']) ? 'excluded' : (empty($row['errors']) ? 'valid' : 'invalid')]++;
        }
        $filter = $r->input('filter', 'all');
        $filtered = $allRows->filter(fn ($row) => $filter === 'all' || ($filter === 'excluded' ? ! empty($row['excluded']) : (empty($row['excluded']) && ($filter === 'valid' ? empty($row['errors']) : ! empty($row['errors'])))))->values();
        $page = min(max(1, $r->integer('page', 1)), max(1, (int) ceil($filtered->count() / 50)));
        $reviewRows = new LengthAwarePaginator($filtered->slice(($page - 1) * 50, 50)->values(), $filtered->count(), 50, $page, ['path' => $r->url(), 'query' => $r->query()]);
        $revision = $service->revision($batch);
        $employees = $this->access->employees($r->user())->orderBy('employee_no')->get(['id', 'employee_no', 'first_name', 'middle_name', 'last_name']);

        return view('leave-attendance.import-preview', compact('batch', 'mapping', 'reviewRows', 'summary', 'revision', 'filter', 'employees'));
    }

    public function importMap(Request $r, ImportBatch $batch, ImportService $service)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()) && $batch->actor_id === $r->user()->id, 403);
        $r->validate(['mapping' => 'required|array:employee_no,shift_date,time_in,time_out,timestamp,direction', 'mapping.*' => 'nullable|integer|min:-1|max:39', 'revision' => ['nullable', 'string', 'size:64'],
            'layout' => ['nullable', 'in:daily,punches'], 'date_order' => ['nullable', 'in:auto,dmy,mdy'], 'employee_match' => ['nullable', 'in:auto,code']]);
        DB::transaction(function () use ($r, $batch, $service) {
            $locked = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'committed') {
                throw ValidationException::withMessages(['file' => 'Committed imports cannot be remapped.']);
            }
            if ($r->filled('revision') && ! hash_equals($service->revision($locked), $r->input('revision'))) {
                throw ValidationException::withMessages(['revision' => 'This upload changed in another tab. Reload before remapping.']);
            }
            $mapping = $r->input('mapping');
            $mapping['_options'] = ['layout' => $r->input('layout', 'daily'), 'date_order' => $r->input('date_order', 'auto'), 'employee_match' => $r->input('employee_match', 'auto')];
            $mapping['_source'] = $locked->mapping['_source'] ?? null;
            $results = $service->preview($locked, $mapping, $r->user());
            $locked->update(['mapping' => $mapping, 'results' => $results, 'status' => 'mapped']);
        });

        return back()->with('success', 'Mapping validated. Review the row results before committing.');
    }

    public function importLayout(Request $r, ImportBatch $batch, ImportService $service)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()) && $batch->actor_id === $r->user()->id, 403);
        $r->validate(['header_row' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'string', 'size:64']]);
        DB::transaction(function () use ($r, $batch, $service) {
            $locked = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'committed' || ! hash_equals($service->revision($locked), $r->input('revision'))) {
                throw ValidationException::withMessages(['header_row' => 'Saved or changed uploads cannot be reinterpreted. Reload the page.']);
            }
            $source = $locked->mapping['_source'] ?? [];
            $raw = $source['rows'] ?? [];
            $header = $r->integer('header_row');
            if (! array_key_exists($header, array_slice($raw, 0, 30, true))) {
                throw ValidationException::withMessages(['header_row' => 'Select one of the first 30 source rows.']);
            }
            [$headers, $rows, $repeated] = ImportFormats::table($raw, $header);
            if (! $rows || count($rows) > 2000) {
                throw ValidationException::withMessages(['header_row' => 'Choose a header with 1–2,000 attendance rows below it.']);
            }
            $mapping = app(ImportInterpreter::class)->suggest($headers, $rows, $r->user());
            $mapping['_source'] = ['rows' => $raw, 'header_row' => $header, 'repeated_headers' => $repeated, 'excel_calendar' => $source['excel_calendar'] ?? 1900];
            $locked->update(['headers' => $headers, 'rows' => $rows, 'mapping' => $mapping, 'results' => null, 'status' => 'uploaded']);
        });

        return redirect()->route('la.import.preview', $batch)->with('success', 'Header updated and columns suggested again. Review the mapping. Previous edits have been reset.');
    }

    public function importCommit(Request $r, ImportBatch $batch, ImportService $service)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()) && $batch->actor_id === $r->user()->id, 403);
        $r->validate(['revision' => ['nullable', 'string', 'size:64']]);
        $result = $service->commit($batch, $r->user(), $r->input('revision'));

        return back()->with('success', "Imported {$result['imported']}, skipped {$result['skipped']}, failed {$result['failed']}.");
    }

    public function importReview(ImportReviewRequest $r, ImportBatch $batch, ImportService $service)
    {
        $this->ready($r);
        abort_unless($batch->actor_id === $r->user()->id, 403);
        $results = $service->review($batch, $r->validated('edits'), $r->user(), $r->validated('revision'), $r->validated('date_order'));
        $ready = count(array_filter($results, fn ($row) => empty($row['excluded']) && empty($row['errors'])));
        $invalid = count(array_filter($results, fn ($row) => empty($row['excluded']) && ! empty($row['errors'])));

        return redirect()->route('la.import.preview', ['batch' => $batch->id, 'page' => $r->integer('page', 1), 'filter' => $r->input('filter', 'all')])
            ->with('success', "Formatting checked and preview saved. {$ready} rows ready; {$invalid} rows still need attention. Attendance has not been saved yet.");
    }

    public function template(Request $r)
    {
        $this->ready($r);
        abort_unless($this->policy->setup($r->user()), 403);

        return response()->streamDownload(function () {
            $zip = new \ZipArchive;
            $path = tempnam(sys_get_temp_dir(), 'hrla');
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>');
            $zip->addFromString('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Attendance" sheetId="1" r:id="rId1"/></sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
            $cells = '';
            foreach (ImportService::FIELDS as $i => $field) {
                $cells .= '<c r="'.chr(65 + $i).'1" t="inlineStr"><is><t>'.$field.'</t></is></c>';
            }
            $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$cells.'</row></sheetData></worksheet>');
            $zip->close();
            readfile($path);
            unlink($path);
        }, 'attendance-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
