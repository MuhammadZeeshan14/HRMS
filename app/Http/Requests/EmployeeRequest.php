<?php

namespace App\Http\Requests;

use App\Models\Employee;
use App\Models\EmploymentStatus;
use App\Services\EmployeeAccess;
use App\Services\EmployeeValidationService;
use App\Services\ReportingService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class EmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $access = app(EmployeeAccess::class);
        $employee = $this->route('employee');

        return $this->user() && ($employee ? $access->canWrite($this->user(), $employee) : $access->admin($this->user()));
    }

    public function rules(): array
    {
        $id = $this->route('employee')?->id;

        $rules = [
            'employee_no' => ['required', 'string', 'max:30', Rule::unique('employees', 'employee_no')->ignore($id)],
            'full_name' => ['nullable', 'string', 'max:240'],
            'first_name' => ['required_without:full_name', 'nullable', 'string', 'max:80'],
            'middle_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['required_without:full_name', 'nullable', 'string', 'max:80'],
            'father_or_husband_name' => ['nullable', 'string', 'max:120'], 'cnic' => ['nullable', 'string', 'max:30', Rule::unique('employees', 'cnic')->ignore($id)],
            'passport_no' => ['nullable', 'string', 'max:40'], 'ntn' => ['nullable', 'string', 'max:40'], 'eobi_no' => ['nullable', 'string', 'max:40'],
            'gender' => ['nullable', Rule::in(['male', 'female', 'other', 'prefer_not_to_say'])], 'place_of_birth' => ['nullable', 'string', 'max:120'],
            'date_of_birth' => ['nullable', 'date', 'before:today'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:3072'],
            'date_of_joining' => ['required', 'date'], 'company_id' => ['required', 'exists:companies,id'],
            'location_id' => ['nullable', 'exists:locations,id'], 'reporting_location_id' => ['nullable', 'exists:locations,id'], 'placement_location_id' => ['nullable', 'exists:locations,id'],
            'department_id' => ['nullable', 'exists:departments,id'], 'designation_search' => ['nullable', 'string', 'max:255'], 'designation_id' => ['nullable', 'exists:designations,id'], 'grade_id' => ['nullable', 'exists:grades,id'],
            'employment_status' => ['required', Rule::in(['active', 'inactive', 'on_leave', 'exited'])],
            'employment_status_id' => ['nullable', 'required_if:_status_source,hris', 'integer', 'exists:employment_statuses,id'],
            'employment_type' => ['required', Rule::in(array_merge(['permanent', 'contract', 'temporary', 'intern', 'consultant'], EmploymentStatus::pluck('name')->map(fn ($name) => mb_strtolower(trim($name)))->all()))],
            'profile_description' => ['nullable', 'string', 'max:5000'], 'job_description' => ['nullable', 'string', 'max:10000'], 'dual_reporting' => ['nullable', 'boolean'],
            'primary_report_to_id' => ['nullable', 'exists:employees,id', Rule::notIn(array_filter([$id]))], 'secondary_report_to_id' => ['nullable', 'exists:employees,id', Rule::notIn(array_filter([$id]))],
            'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:30'], 'address' => ['nullable', 'string', 'max:500'], 'religion_id' => ['nullable', 'exists:religions,id'],
            'language_ids' => ['nullable', 'array'], 'language_ids.*' => ['integer', 'exists:languages,id'],

            'trainings' => ['nullable', 'array'], 'trainings.*.id' => ['nullable', 'integer'], 'trainings.*._delete' => ['nullable', 'boolean'],
            'trainings.*.training_type_id' => ['nullable', 'exists:training_types,id'], 'trainings.*.title' => ['nullable', 'string', 'max:255'], 'trainings.*.provider' => ['nullable', 'string', 'max:255'], 'trainings.*.starts_on' => ['nullable', 'date'], 'trainings.*.ends_on' => ['nullable', 'date'], 'trainings.*.notes' => ['nullable', 'string', 'max:2000'],
            'certifications' => ['nullable', 'array'], 'certifications.*.id' => ['nullable', 'integer'], 'certifications.*._delete' => ['nullable', 'boolean'],
            'certifications.*.certification_id' => ['nullable', 'exists:certifications,id'], 'certifications.*.title' => ['nullable', 'string', 'max:255'], 'certifications.*.credential_no' => ['nullable', 'string', 'max:100'], 'certifications.*.issued_on' => ['nullable', 'date'], 'certifications.*.expires_on' => ['nullable', 'date'],
            'qualifications' => ['nullable', 'array'], 'qualifications.*.id' => ['nullable', 'integer'], 'qualifications.*._delete' => ['nullable', 'boolean'],
            'qualifications.*.institution' => ['nullable', 'string', 'max:255'], 'qualifications.*.degree' => ['nullable', 'string', 'max:255'], 'qualifications.*.field_of_study' => ['nullable', 'string', 'max:255'], 'qualifications.*.starts_on' => ['nullable', 'date'], 'qualifications.*.ends_on' => ['nullable', 'date'], 'qualifications.*.result' => ['nullable', 'string', 'max:100'], 'qualifications.*.document' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'],
            'experiences' => ['nullable', 'array'], 'experiences.*.id' => ['nullable', 'integer'], 'experiences.*._delete' => ['nullable', 'boolean'],
            'experiences.*.employer' => ['nullable', 'string', 'max:255'], 'experiences.*.job_title' => ['nullable', 'string', 'max:255'], 'experiences.*.starts_on' => ['nullable', 'date'], 'experiences.*.ends_on' => ['nullable', 'date'], 'experiences.*.description' => ['nullable', 'string', 'max:3000'],
            'relatives' => ['nullable', 'array'], 'relatives.*.id' => ['nullable', 'integer'], 'relatives.*._delete' => ['nullable', 'boolean'],
            'relatives.*.relationship' => ['nullable', 'string', 'max:100'], 'relatives.*.name' => ['nullable', 'string', 'max:255'], 'relatives.*.phone' => ['nullable', 'string', 'max:30'], 'relatives.*.date_of_birth' => ['nullable', 'date'], 'relatives.*.is_dependent' => ['nullable', 'boolean'],
            'references' => ['nullable', 'array'], 'references.*.id' => ['nullable', 'integer'], 'references.*._delete' => ['nullable', 'boolean'],
            'references.*.name' => ['nullable', 'string', 'max:255'], 'references.*.relationship' => ['nullable', 'string', 'max:100'], 'references.*.organization' => ['nullable', 'string', 'max:255'], 'references.*.email' => ['nullable', 'email', 'max:255'], 'references.*.phone' => ['nullable', 'string', 'max:30'],
            'projects' => ['nullable', 'array'], 'projects.*.id' => ['nullable', 'integer'], 'projects.*._delete' => ['nullable', 'boolean'],
            'projects.*.project_name' => ['nullable', 'string', 'max:255'], 'projects.*.role' => ['nullable', 'string', 'max:255'], 'projects.*.client' => ['nullable', 'string', 'max:255'], 'projects.*.starts_on' => ['nullable', 'date'], 'projects.*.ends_on' => ['nullable', 'date'], 'projects.*.responsibilities' => ['nullable', 'string', 'max:3000'], 'projects.*.skills' => ['nullable', 'string', 'max:1000'], 'projects.*.description' => ['nullable', 'string', 'max:3000'],
            'salaries' => ['nullable', 'array'], 'salaries.*.id' => ['nullable', 'integer'], 'salaries.*._delete' => ['nullable', 'boolean'],
            'salaries.*.effective_from' => ['nullable', 'date'], 'salaries.*.effective_to' => ['nullable', 'date'], 'salaries.*.basic_salary' => ['nullable', 'numeric', 'min:0'], 'salaries.*.allowances' => ['nullable', 'numeric', 'min:0'], 'salaries.*.other_components' => ['nullable', 'numeric', 'min:0'], 'salaries.*.currency' => ['nullable', 'string', 'size:3'], 'salaries.*.remarks' => ['nullable', 'string', 'max:2000'],
            'documents' => ['nullable', 'array'], 'documents.*.id' => ['nullable', 'integer'], 'documents.*._delete' => ['nullable', 'boolean'],
            'documents.*.document_type_id' => ['nullable', 'exists:document_types,id'], 'documents.*.title' => ['nullable', 'string', 'max:255'], 'documents.*.file' => ['nullable', 'file', 'mimes:pdf,doc,docx,jpg,jpeg,png', 'max:10240'], 'documents.*.issued_on' => ['nullable', 'date'], 'documents.*.expires_on' => ['nullable', 'date'], 'documents.*.status' => ['nullable', Rule::in(['pending', 'verified', 'rejected', 'expired'])],
            'exits' => ['nullable', 'array'], 'exits.*.id' => ['nullable', 'integer'], 'exits.*._delete' => ['nullable', 'boolean'],
            'exits.*.leaving_reason_id' => ['nullable', 'exists:leaving_reasons,id'], 'exits.*.resignation_date' => ['nullable', 'date'], 'exits.*.last_working_date' => ['nullable', 'date'], 'exits.*.notice_end_date' => ['nullable', 'date'], 'exits.*.clearance_status' => ['nullable', Rule::in(['pending', 'in_progress', 'cleared'])], 'exits.*.status' => ['nullable', Rule::in(['initiated', 'approved', 'completed', 'cancelled'])], 'exits.*.remarks' => ['nullable', 'string', 'max:3000'],
        ];
        $rules['cnic'][] = 'regex:/^[0-9]{13}$/';
        $rules['date_of_birth'][] = 'before:date_of_joining';
        $rules['language_ids.*'][] = 'distinct';
        $rules['relatives.*.date_of_birth'][] = 'before_or_equal:today';
        foreach (['phone', 'relatives.*.phone', 'references.*.phone'] as $field) {
            $rules[$field][] = 'regex:/^\\+?[0-9]{7,15}$/';
        }
        foreach (['basic_salary', 'allowances', 'other_components'] as $field) {
            $rules['salaries.*.'.$field][] = 'decimal:0,2';
            $rules['salaries.*.'.$field][] = 'max:999999999999.99';
        }
        $rules['salaries.*.currency'][] = Rule::in(config('hris_validation.currencies'));
        foreach (['trainings', 'certifications', 'qualifications', 'experiences', 'relatives', 'references', 'projects', 'salaries', 'documents', 'exits'] as $section) {
            $rules[$section.'.*'] = ['array'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('employment_status_id') && is_scalar($this->input('employment_status_id'))) {
            $status = EmploymentStatus::find($this->input('employment_status_id'));
            if ($status) {
                $this->merge(['employment_type' => mb_strtolower(trim($status->name))]);
            }
        } elseif (! $this->has('employment_status_id') && is_string($this->input('employment_type'))) {
            $matches = EmploymentStatus::whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower(trim($this->input('employment_type')))])->limit(2)->get();
            $this->merge(['employment_status_id' => $matches->count() === 1 ? $matches->first()->id : null]);
        }
        $nameData = [];
        if (is_string($fullName = $this->input('full_name'))) {
            $parts = preg_split('/\s+/', trim($fullName));
            $parts = $parts === false ? [] : array_values(array_filter($parts, fn ($part) => $part !== ''));
            $firstName = array_shift($parts) ?? '';
            $lastName = count($parts) > 1 ? array_pop($parts) : (array_pop($parts) ?? '');

            $nameData = [
                'first_name' => $firstName,
                'middle_name' => $parts ? implode(' ', $parts) : null,
                'last_name' => $lastName,
            ];
        }

        $normalized = ['dual_reporting' => $this->boolean('dual_reporting')];
        if (! $normalized['dual_reporting']) {
            $normalized['secondary_report_to_id'] = null;
        }
        if (is_string($this->input('cnic'))) {
            $normalized['cnic'] = preg_replace('/[\\s-]+/', '', $this->input('cnic'));
        }
        foreach (['phone', 'relatives', 'references', 'salaries'] as $key) {
            $value = $this->input($key);
            if ($key === 'phone' && is_string($value)) {
                $normalized[$key] = preg_replace('/[\\s().-]+/', '', $value);
            }
            if (is_array($value)) {
                foreach ($value as &$row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (isset($row['phone']) && is_string($row['phone'])) {
                        $row['phone'] = preg_replace('/[\\s().-]+/', '', $row['phone']);
                    }
                    if (isset($row['currency']) && is_string($row['currency'])) {
                        $row['currency'] = strtoupper(trim($row['currency']));
                    }
                }
                unset($row);
                $normalized[$key] = $value;
            }
        }
        $this->merge($nameData + $normalized);
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (! $this instanceof EmployeeExitRequest) {
                $employee = $this->route('employee') ? clone $this->route('employee') : new Employee;
                $employee->fill($this->only(['company_id', 'department_id', 'primary_report_to_id', 'secondary_report_to_id', 'dual_reporting', 'employment_status']));
                if (! app(EmployeeAccess::class)->canPlace($this->user(), $employee)) {
                    $validator->errors()->add('department_id', 'Select a department in your company and permitted hierarchy.');
                }
                foreach (app(ReportingService::class)->errors($employee) as $field => $message) {
                    $validator->errors()->add($field, $message);
                }
            }
            app(EmployeeValidationService::class)->validate($this, $validator);
            $search = trim((string) $this->input('designation_search', ''));
            if ($search === '') {
                return;
            }

            $designation = $this->filled('designation_id')
                ? DB::table('designations')->where('id', $this->input('designation_id'))->first(['name', 'code'])
                : null;
            if (! $designation || $search !== $designation->name.' ('.$designation->code.')') {
                $validator->errors()->add('designation_search', 'Choose a designation from the list.');
            }
        }];
    }
}
