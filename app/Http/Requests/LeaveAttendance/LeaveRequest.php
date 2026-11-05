<?php

namespace App\Http\Requests\LeaveAttendance;

use Illuminate\Foundation\Http\FormRequest;

class LeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    public function rules(): array
    {
        return ['employee_id' => ['nullable', 'integer', 'exists:employees,id'], 'leave_type_id' => ['required', 'integer', 'exists:hrla_leave_types,id'],
            'form_date' => ['required', 'date_format:Y-m-d'], 'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'], 'reason' => ['required', 'string', 'max:2000'],
            'session' => ['nullable', 'in:disabled']];
    }
}
