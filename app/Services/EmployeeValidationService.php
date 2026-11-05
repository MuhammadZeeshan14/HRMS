<?php

namespace App\Services;

use App\Http\Requests\EmployeeRequest;
use App\Http\Requests\EmployeeExitRequest;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

class EmployeeValidationService
{
    public function validate(EmployeeRequest $request, Validator $validator): void
    {
        $employee = $request->route('employee');
        $id = $employee?->id;
        $error = fn ($field, $message) => $validator->errors()->add($field, $message);
        $warnings = [];
        $joining = $request instanceof EmployeeExitRequest ? $employee?->date_of_joining?->format('Y-m-d') : $request->input('date_of_joining');
        if (! $request instanceof EmployeeExitRequest) {
            if ($request->filled('cnic') && DB::table('employees')->when($id, fn ($query) => $query->where('id', '!=', $id))->whereRaw("REPLACE(REPLACE(cnic, '-', ''), ' ', '') = ?", [$request->cnic])->exists()) $error('cnic', 'This CNIC is already assigned to another employee.');
            if ($request->filled('date_of_birth') && Carbon::parse($request->date_of_birth)->age >= config('hris_validation.review_age', 80)) $warnings[] = 'Please review the employee date of birth: the recorded age is unusually high.';
            $minimum = config('hris_validation.minimum_joining_age');
            if ($minimum !== null && $request->filled('date_of_birth') && Carbon::parse($request->date_of_birth)->addYears((int) $minimum)->gt(Carbon::parse($joining))) {
                $error('date_of_joining', 'The employee does not meet the configured minimum joining age.');
            }
            foreach (['department_id' => 'departments', 'grade_id' => 'grades', 'location_id' => 'locations', 'placement_location_id' => 'locations', 'reporting_location_id' => 'locations'] as $field => $table) {
                if (! $request->filled($field)) continue;
                $company = DB::table($table)->where('id', $request->input($field))->value('company_id');
                if ($company !== null && (int) $company !== (int) $request->company_id) $error($field, 'Select a record belonging to the selected company.');
            }
            if ($request->boolean('dual_reporting') && ! $request->filled('secondary_report_to_id')) $error('secondary_report_to_id', 'Choose a secondary manager for dual reporting.');
            if ($request->filled('secondary_report_to_id') && $request->secondary_report_to_id == $request->primary_report_to_id) $error('secondary_report_to_id', 'Primary and secondary managers must differ.');
            foreach (['primary_report_to_id', 'secondary_report_to_id'] as $field) {
                $pending = array_filter([$request->input($field)]);
                $visited = [];
                while ($pending) {
                    $manager = array_pop($pending);
                    if ($id && (int) $manager === (int) $id) {
                        $error($field, 'This manager would create a reporting loop.');
                        break;
                    }
                    if (isset($visited[$manager])) continue;
                    $visited[$manager] = true;
                    $record = DB::table('employees')->find($manager, ['primary_report_to_id', 'secondary_report_to_id']);
                    if ($record) $pending = array_merge($pending, array_filter([$record->primary_report_to_id, $record->secondary_report_to_id]));
                }
            }
        }
        $sections = [
            'trainings' => ['employee_trainings', 'title', 'starts_on', 'ends_on'],
            'certifications' => ['employee_certifications', 'title', 'issued_on', 'expires_on'],
            'qualifications' => ['employee_qualifications', 'institution', 'starts_on', 'ends_on'],
            'experiences' => ['employee_experiences', 'employer', 'starts_on', 'ends_on'],
            'relatives' => ['employee_relatives', 'name'],
            'references' => ['employee_references', 'name'],
            'projects' => ['employee_projects', 'project_name', 'starts_on', 'ends_on'],
            'salaries' => ['salary_histories', 'effective_from', 'effective_from', 'effective_to'],
            'documents' => ['employee_documents', 'title', 'issued_on', 'expires_on'],
            'exits' => ['employee_exits', 'status'],
        ];
        foreach ($sections as $section => $settings) {
            [$table, $required, $start, $end] = array_pad($settings, 4, null);
            $seen = [];
            foreach ($request->input($section, []) ?? [] as $index => $row) {
                $prefix = "$section.$index";
                if (! empty($row['id'])) {
                    if (isset($seen[$row['id']])) $error("$prefix.id", 'Do not submit the same record twice.');
                    $seen[$row['id']] = true;
                    if (! $id || ! DB::table($table)->where('employee_id', $id)->where('id', $row['id'])->exists()) $error("$prefix.id", 'This record does not belong to this employee.');
                }
                if (! empty($row['_delete'])) continue;
                if (in_array($section, ['documents', 'certifications']) && filled($row['expires_on'] ?? null) && Carbon::parse($row['expires_on'])->startOfDay()->lt(today())) $warnings[] = ucfirst($section).' row '.($index + 1).' has expired; please review it.';
                $meaningful = array_filter($row, fn ($value, $key) => ! in_array($key, ['id', '_delete', 'is_dependent', 'status', 'clearance_status', 'currency']) && filled($value), ARRAY_FILTER_USE_BOTH);
                if (! $meaningful && empty($row['id']) && ! ($section === 'exits' && ($row['status'] ?? 'initiated') !== 'initiated')) continue;
                if (blank($row[$required] ?? null)) $error("$prefix.$required", 'Complete this field or remove the incomplete row.');
                if ($section === 'salaries' && ! isset($row['basic_salary'])) $error("$prefix.basic_salary", 'Enter the basic salary.');
                if ($start && filled($row[$start] ?? null) && filled($row[$end] ?? null) && Carbon::parse($row[$end])->lt(Carbon::parse($row[$start]))) $error("$prefix.$end", 'The end date cannot be before the start date.');
                if ($section === 'documents') {
                    if (empty($row['document_type_id'])) $error("$prefix.document_type_id", 'Select a document type.');
                    if (empty($row['id']) && ! $request->hasFile("$prefix.file")) $error("$prefix.file", 'Upload the document file.');
                }
                if ($section === 'exits') {
                    if (empty($row['leaving_reason_id'])) $error("$prefix.leaving_reason_id", 'Select a leaving reason.');
                    foreach (['resignation_date', 'last_working_date', 'notice_end_date'] as $date) {
                        if (filled($row[$date] ?? null) && $joining && Carbon::parse($row[$date])->lt(Carbon::parse($joining))) $error("$prefix.$date", 'Exit dates cannot precede the joining date.');
                    }
                    if (filled($row['last_working_date'] ?? null) && filled($row['resignation_date'] ?? null) && Carbon::parse($row['last_working_date'])->lt(Carbon::parse($row['resignation_date']))) $error("$prefix.last_working_date", 'Last working date cannot precede resignation.');
                    if (($row['status'] ?? '') === 'completed') {
                        if (empty($row['last_working_date'])) $error("$prefix.last_working_date", 'A completed exit requires the last working date.');
                        if (config('hris_validation.exit_requires_clearance') && ($row['clearance_status'] ?? '') !== 'cleared') $error("$prefix.clearance_status", 'Complete clearance before completing the exit.');
                    }
                }
            }
        }
        if (! $request instanceof EmployeeExitRequest) $this->validateSalaryPeriods($request, $validator, $id);
        if ($warnings && $validator->errors()->isEmpty()) $request->session()->flash('validation_warnings', array_unique($warnings));
    }

    private function validateSalaryPeriods(EmployeeRequest $request, Validator $validator, ?int $id): void
    {
        $rows = $request->input('salaries', []) ?? [];
        $submittedIds = array_filter(array_column($rows, 'id'));
        $periods = $id ? DB::table('salary_histories')->where('employee_id', $id)->whereNotIn('id', $submittedIds)->get()->map(fn ($row) => (array) $row)->all() : [];
        foreach ($rows as $index => $row) {
            if (! empty($row['_delete']) || empty($row['effective_from'])) continue;
            $row['_field'] = "salaries.$index.effective_from";
            $periods[] = $row;
        }
        foreach ($periods as $i => $first) {
            foreach (array_slice($periods, $i + 1) as $second) {
                if (! isset($first['_field']) && ! isset($second['_field'])) continue;
                if (Carbon::parse($first['effective_from'])->lte(Carbon::parse($second['effective_to'] ?? '9999-12-31')) && Carbon::parse($second['effective_from'])->lte(Carbon::parse($first['effective_to'] ?? '9999-12-31'))) {
                    $validator->errors()->add($second['_field'] ?? $first['_field'], 'Salary periods cannot overlap; close the previous period first.');
                }
            }
        }
    }
}
