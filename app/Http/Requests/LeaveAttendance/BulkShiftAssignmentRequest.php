<?php

namespace App\Http\Requests\LeaveAttendance;

use App\Services\LeaveAttendance\Access;
use Illuminate\Foundation\Http\FormRequest;

class BulkShiftAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->hr($this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('employee_ids_payload')) {
            $value = $this->input('employee_ids_payload');
            $this->merge(['employee_ids' => is_string($value) ? json_decode($value, true) : null]);
        }
    }

    public function rules(): array
    {
        return [
            'employee_ids' => ['bail', 'required', 'array', 'min:1', 'max:5000', 'exists:employees,id'],
            'employee_ids.*' => ['required', 'integer', 'distinct'],
            'shift_id' => ['required', 'integer', 'exists:hrla_shifts,id'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ];
    }

    public function messages(): array
    {
        return ['employee_ids.required' => 'Select at least one employee to assign a shift.'];
    }
}
