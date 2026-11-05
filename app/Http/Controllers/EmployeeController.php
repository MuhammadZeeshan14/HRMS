<?php

namespace App\Http\Controllers;

use App\Http\Requests\EmployeeExitRequest;
use App\Http\Requests\EmployeeRequest;
use App\Models\Company;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Models\Grade;
use App\Models\Location;
use App\Services\AuditService;
use App\Services\DepartmentLevelService;
use App\Services\EmployeeAccess;
use App\Services\ReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class EmployeeController extends Controller
{
    private const CORE_FIELDS = [
        'employee_no', 'first_name', 'middle_name', 'last_name', 'father_or_husband_name', 'cnic', 'passport_no', 'ntn', 'eobi_no',
        'gender', 'place_of_birth', 'date_of_birth', 'date_of_joining', 'company_id', 'location_id', 'reporting_location_id', 'placement_location_id',
        'department_id', 'designation_id', 'grade_id', 'employment_status', 'employment_type', 'employment_status_id', 'profile_description', 'job_description',
        'dual_reporting', 'primary_report_to_id', 'secondary_report_to_id', 'email', 'phone', 'address', 'religion_id',
    ];

    private const RELATED = [
        'trainings' => ['employee_trainings', 'title'],
        'certifications' => ['employee_certifications', 'title'],
        'qualifications' => ['employee_qualifications', 'institution', 'document', 'document_path'],
        'experiences' => ['employee_experiences', 'employer'],
        'relatives' => ['employee_relatives', 'name'],
        'references' => ['employee_references', 'name'],
        'projects' => ['employee_projects', 'project_name'],
        'salaries' => ['salary_histories', 'effective_from'],
        'documents' => ['employee_documents', 'title', 'file', 'file_path'],
        'exits' => ['employee_exits', 'status'],
    ];

    public function index(Request $request)
    {
        $this->authorize('viewAny', Employee::class);
        $perPage = in_array((int) $request->input('per_page', 10), [10, 25, 50], true) ? (int) $request->input('per_page', 10) : 10;
        $employees = app(EmployeeAccess::class)->employees($request->user())->with(['company', 'department', 'designation', 'location'])
            ->search($request->q)->filters($request->only('company_id', 'department_id', 'designation_id', 'status'))
            ->latest()->paginate($perPage)->withQueryString();

        return view('employees.index', [
            'employees' => $employees,
            'companies' => Company::whereKey($request->user()->employee->company_id)->get(),
            'departments' => Department::whereIn('id', app(EmployeeAccess::class)->departments($request->user()))->get(),
            'departmentPaths' => app(DepartmentLevelService::class)->paths(Department::all()),
            'designations' => Designation::orderBy('name')->get(),
            'activeCount' => app(EmployeeAccess::class)->employees($request->user())->where('employment_status', 'active')->count(),
        ]);
    }

    public function create()
    {
        $this->authorize('create', Employee::class);

        return view('employees.form', $this->formData(new Employee));
    }

    public function store(EmployeeRequest $request, AuditService $audit)
    {
        $validated = $request->validated();
        $employee = DB::transaction(function () use ($request, $validated, $audit) {
            $data = Arr::only($validated, self::CORE_FIELDS);
            if ($request->hasFile('photo')) {
                $data['photo_path'] = $request->file('photo')->store('employee-photos', 'public');
            }
            $employee = Employee::create($data);
            $this->syncProfile($request, $employee, $validated);
            $audit->record('employee.created', Employee::class, $employee->id, [], $employee->toArray());

            return $employee;
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee profile created.');
    }

    public function show(Employee $employee)
    {
        $this->authorize('view', $employee);
        $employee->load(['company', 'department', 'designation', 'grade', 'location', 'reportingLocation', 'placementLocation', 'primaryReport', 'secondaryReport']);
        $related = $this->relatedRecords($employee);

        return view('employees.show', compact('employee', 'related'));
    }

    public function edit(Employee $employee)
    {
        $this->authorize('update', $employee);

        return view('employees.form', $this->formData($employee));
    }

    public function update(EmployeeRequest $request, Employee $employee, AuditService $audit)
    {
        $validated = $request->validated();
        DB::transaction(function () use ($request, $validated, $employee, $audit) {
            $old = $employee->toArray();
            $data = Arr::only($validated, self::CORE_FIELDS);
            if ($request->hasFile('photo')) {
                if ($employee->photo_path) {
                    Storage::disk('public')->delete($employee->photo_path);
                }
                $data['photo_path'] = $request->file('photo')->store('employee-photos', 'public');
            }
            $employee->update($data);
            $this->syncProfile($request, $employee, $validated);
            $audit->record('employee.updated', Employee::class, $employee->id, $old, $employee->fresh()->toArray());
        });

        return redirect()->route('employees.show', $employee)->with('success', 'Employee profile updated.');
    }

    public function exit(Employee $employee)
    {
        $this->authorize('update', $employee);

        return view('employees.exit', [
            'employee' => $employee,
            'exits' => old('exits', DB::table('employee_exits')->where('employee_id', $employee->id)->orderByDesc('id')->get()->map(fn ($row) => (array) $row)->all()),
            'leavingReasons' => DB::table('leaving_reasons')->orderBy('name')->get(),
        ]);
    }

    public function updateExit(EmployeeExitRequest $request, Employee $employee, AuditService $audit)
    {
        $this->authorize('update', $employee);
        DB::transaction(function () use ($request, $employee, $audit) {
            $old = DB::table('employee_exits')->where('employee_id', $employee->id)->get()->toArray();
            $this->syncProfile($request, $employee, $request->validated(), true);
            $audit->record('employee.exit.updated', Employee::class, $employee->id, $old, DB::table('employee_exits')->where('employee_id', $employee->id)->get()->toArray());
        });

        return redirect()->route('employees.exit', $employee)->with('success', 'Employee exit records saved.');
    }

    private function formData(Employee $employee): array
    {
        return [
            'employee' => $employee,
            'employmentStatuses' => EmploymentStatus::where('status', 'active')->when($employee->employment_status_id, fn ($q, $id) => $q->orWhere('id', $id))->orderBy('name')->get(),
            'companies' => Company::whereKey(auth()->user()->employee->company_id)->get(),
            'departments' => Department::whereIn('id', app(EmployeeAccess::class)->departments(auth()->user()))->where('status', 'active')->get(),
            'departmentPaths' => app(DepartmentLevelService::class)->paths(Department::all()),
            'designations' => Designation::where('status', 'active')
                ->when($employee->designation_id, fn ($query, $id) => $query->orWhere('id', $id))
                ->orderBy('name')->get(),
            'grades' => Grade::where('status', 'active')->orderBy('rank')->get(),
            'locations' => Location::where('status', 'active')->orderBy('name')->get(),
            'religions' => DB::table('religions')->where('status', 'active')->orderBy('name')->get(),
            'languages' => DB::table('languages')->where('status', 'active')->orderBy('name')->get(),
            'trainingTypes' => DB::table('training_types')->where('status', 'active')->orderBy('name')->get(),
            'certificationOptions' => DB::table('certifications')->where('status', 'active')->orderBy('name')->get(),
            'documentTypes' => DB::table('document_types')->where('status', 'active')->orderBy('name')->get(),
            'leavingReasons' => DB::table('leaving_reasons')->where('status', 'active')->orderBy('name')->get(),
            'reportingEmployees' => app(ReportingService::class)->candidates($employee),
            'languageIds' => $employee->exists ? DB::table('employee_languages')->where('employee_id', $employee->id)->pluck('language_id')->all() : [],
            'related' => $this->relatedRecords($employee),
        ];
    }

    private function relatedRecords(Employee $employee): array
    {
        $records = [];
        foreach (self::RELATED as $key => [$table]) {
            $records[$key] = $employee->exists ? DB::table($table)->where('employee_id', $employee->id)->orderByDesc('id')->get() : collect();
        }

        return $records;
    }

    private function syncProfile(EmployeeRequest $request, Employee $employee, array $validated, bool $exitOnly = false): void
    {
        if (! $exitOnly) {
            $languageIds = $validated['language_ids'] ?? [];
            DB::table('employee_languages')->where('employee_id', $employee->id)->whereNotIn('language_id', $languageIds ?: [0])->delete();
            foreach ($languageIds as $languageId) {
                DB::table('employee_languages')->updateOrInsert(
                    ['employee_id' => $employee->id, 'language_id' => $languageId],
                    ['proficiency' => 'working', 'updated_at' => now(), 'created_at' => now()]
                );
            }

        }
        foreach (self::RELATED as $key => $settings) {
            if ($exitOnly && $key !== 'exits') {
                continue;
            }
            [$table, $requiredField, $fileInput, $fileColumn] = array_pad($settings, 4, null);
            foreach ($validated[$key] ?? [] as $index => $row) {
                $id = Arr::pull($row, 'id');
                $delete = (bool) Arr::pull($row, '_delete', false);
                if ($delete && $id) {
                    $existing = DB::table($table)->where('employee_id', $employee->id)->where('id', $id)->first();
                    if ($existing && $fileColumn && $existing->$fileColumn) {
                        Storage::disk('local')->delete($existing->$fileColumn);
                    }
                    DB::table($table)->where('employee_id', $employee->id)->where('id', $id)->delete();

                    continue;
                }
                if (blank($row[$requiredField] ?? null)) {
                    continue;
                }
                if ($fileInput && $request->hasFile($key.'.'.$index.'.'.$fileInput)) {
                    $row[$fileColumn] = $request->file($key.'.'.$index.'.'.$fileInput)->store('employee-files/'.$employee->id, 'local');
                }
                unset($row[$fileInput]);
                if ($table === 'salary_histories') {
                    $row += ['allowances' => 0, 'other_components' => 0, 'currency' => 'PKR', 'created_by' => $request->user()->id];
                } elseif ($table === 'employee_documents') {
                    $row += ['status' => 'pending'];
                    if (! $id && empty($row['file_path'])) {
                        continue;
                    }
                } elseif ($table === 'employee_exits') {
                    $row += ['status' => 'initiated', 'clearance_status' => 'pending', 'processed_by' => $request->user()->id];
                }
                $row['updated_at'] = now();
                if ($id) {
                    DB::table($table)->where('employee_id', $employee->id)->where('id', $id)->update($row);
                } else {
                    $row += ['employee_id' => $employee->id, 'created_at' => now()];
                    DB::table($table)->insert($row);
                }
            }
        }
    }
}
