<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SetupRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasRole('admin', 'hr_manager') ?? false;
    }

    public function rules(): array
    {
        $master = $this->route('master');
        $config = config('hris_setup.'.$master);
        abort_unless($config, 404);
        $id = $this->route('id');
        $record = $id ? DB::table($master)->find($id) : null;
        abort_if($id && ! $record, 404);
        $rules = [];
        foreach ($config['fields'] as $field => $meta) {
            if ($master === 'locations' && in_array($field, ['latitude', 'longitude'], true)) {
                $rules[$field] = ['nullable', 'numeric', $field === 'latitude' ? 'between:-90,90' : 'between:-180,180', 'required_with:'.($field === 'latitude' ? 'longitude' : 'latitude')];
                continue;
            }
            if ($master === 'departments' && $field === 'level') {
                $rules[$field] = ['exclude'];
                continue;
            }
            $type = $meta['type'] ?? 'text';
            $optional = ($meta['optional'] ?? false) || ($type === 'file' && $record && $record->$field);
            $rule = [$optional ? 'nullable' : 'required'];
            $rule = array_merge($rule, match ($type) {
                'relation' => ['integer', Rule::exists($meta['table'], 'id')],
                'multiple' => ['array', 'min:'.($optional ? '0' : '1')],
                'select' => [Rule::in(array_keys($meta['options']))],
                'boolean' => ['boolean'],
                'date' => ['date'],
                'integer' => ['integer', 'min:'.($field === 'rank' ? '1' : '0'), 'max:2147483647'],
                'decimal' => ['numeric', 'decimal:0,2', 'min:0', 'max:999999999999.99'],
                'file' => ['file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,jpg,jpeg,png', 'max:10240'],
                'textarea' => ['string', 'max:10000'],
                default => ['string', 'max:'.($meta['max'] ?? 255)],
            });
            if ($meta['unique'] ?? false) {
                $rule[] = Rule::unique($master, $field)->ignore($id);
            }
            if ($master === 'branches' && in_array($field, ['code', 'name'])) {
                $rule[] = Rule::unique('branches', $field)->where('bank_id', $this->input('bank_id'))->ignore($id);
            }
            if ($master === 'compensation_plan_details' && $field === 'allowance_id') {
                $rule[] = Rule::unique($master, $field)->where('compensation_plan_id', $this->input('compensation_plan_id'))->ignore($id);
            }
            $rules[$field] = $rule;
            if ($type === 'multiple') {
                $rules[$field.'.*'] = ['integer', 'distinct', Rule::exists($meta['table'], 'id')];
            }
        }
        if ($master === 'fiscal_years') {
            $rules['ends_on'][] = 'after_or_equal:starts_on';
        }
        if (isset($rules['salary_to']) && $this->filled('salary_from')) {
            $rules['salary_to'][] = 'gte:salary_from';
        }

        return $rules;
    }

    public function attributes(): array
    {
        return collect(config('hris_setup.'.$this->route('master').'.fields', []))->mapWithKeys(fn ($meta, $field) => [$field => $meta['label']])->all();
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $master = $this->route('master');
            $id = (int) $this->route('id');
            foreach (['parent_department_id', 'employee_contribution_allowance_id'] as $parentField) {
                if ($id && $this->filled($parentField)) {
                    $parent = (int) $this->input($parentField);
                    $visited = [];
                    while ($parent) {
                        if ($parent === $id || isset($visited[$parent])) {
                            $validator->errors()->add($parentField, 'The parent/reporting department cannot contain a cycle.');
                            break;
                        }
                        $visited[$parent] = true;
                        $parent = (int) DB::table($master)->where('id', $parent)->value($parentField);
                    }
                }
            }
            if ($master === 'possession_items' && $this->filled('possession_type_id')) {
                $category = DB::table('possession_types')->where('id', $this->input('possession_type_id'))->value('possession_category_id');
                if ((int) $category !== (int) $this->input('possession_category_id')) {
                    $validator->errors()->add('possession_type_id', 'Select a possession type belonging to the selected category.');
                }
            }
            if ($master === 'compensation_plan_details') {
                if ($this->input('allowance_limit') === 'percentage') {
                    if (! $this->filled('percentage') || (float) $this->input('percentage') > 100) $validator->errors()->add('percentage', 'Enter a percentage between 0 and 100.');
                    if (! $this->filled('percentage_of')) $validator->errors()->add('percentage_of', 'Select the salary basis.');
                    if ($this->filled('fixed_amount')) $validator->errors()->add('fixed_amount', 'Leave fixed amount empty for a percentage allowance.');
                } else {
                    if (! $this->filled('fixed_amount')) $validator->errors()->add('fixed_amount', 'Enter a fixed amount.');
                    if ($this->filled('percentage') || $this->filled('percentage_of')) $validator->errors()->add('percentage', 'Leave percentage fields empty for a fixed allowance.');
                }
            }
            $config = config('hris_setup.'.$master);
            $company = $this->input('company_id');
            if ($company) {
                foreach (['department_id', 'grade_id', 'parent_department_id'] as $field) {
                    if ($this->filled($field) && isset($config['fields'][$field]['table'])) {
                        $targetCompany = DB::table($config['fields'][$field]['table'])->where('id', $this->input($field))->value('company_id');
                        if ($targetCompany !== null && (int) $targetCompany !== (int) $company) {
                            $validator->errors()->add($field, 'Select a record belonging to the selected company.');
                        }
                    }
                }
            }
            if ($master === 'companies' && $this->filled('branch_id')) {
                $bank = DB::table('branches')->where('id', $this->input('branch_id'))->value('bank_id');
                if ((int) $bank !== (int) $this->input('bank_id')) {
                    $validator->errors()->add('branch_id', 'Select a branch belonging to the selected bank.');
                }
            }
        }];
    }
}
