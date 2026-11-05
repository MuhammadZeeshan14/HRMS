<?php

namespace App\Http\Requests\LeaveAttendance;

use App\Services\LeaveAttendance\Access;
use Illuminate\Foundation\Http\FormRequest;

class ImportReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->hr($this->user());
    }

    protected function prepareForValidation(): void
    {
        $value = $this->input('changes');
        $this->merge(['edits' => is_string($value) ? json_decode($value, true) : null]);
    }

    public function rules(): array
    {
        $autoFix = $this->boolean('auto_fix');
        $rules = ['revision' => ['required', 'string', 'size:64'], 'auto_fix' => ['sometimes', 'boolean'], 'date_order' => ['nullable', 'in:auto,dmy,mdy'], 'edits' => [$autoFix ? 'present' : 'required', 'array', $autoFix ? 'min:0' : 'min:1', 'max:50'],
            'edits.*.row' => ['required', 'integer', 'distinct', 'min:2'],
            'edits.*.employee_no' => ['sometimes', 'nullable', 'string', 'max:255'],
            'edits.*.shift_date' => ['sometimes', 'nullable', 'string', 'max:255'],
            'edits.*.excluded' => ['sometimes', 'boolean'], 'edits.*.refresh_current' => ['sometimes', 'boolean'],
            'page' => ['nullable', 'integer', 'between:1,40'], 'filter' => ['nullable', 'in:all,valid,invalid,excluded']];
        foreach (['time_in', 'time_out'] as $field) {
            $rules['edits.*.'.$field] = ['sometimes', 'nullable', 'string', 'max:255'];
            $rules['edits.*.'.$field.'_date'] = ['sometimes', 'nullable', 'string', 'max:255'];
            foreach (['hour', 'minute'] as $part) $rules['edits.*.'.$field.'_'.$part] = ['sometimes', 'nullable', 'regex:/^[0-9]{1,2}$/D'];
            $rules['edits.*.'.$field.'_period'] = ['sometimes', 'nullable', 'string', 'max:10'];
        }

        return $rules;
    }
}
