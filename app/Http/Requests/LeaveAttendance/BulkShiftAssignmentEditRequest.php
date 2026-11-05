<?php

namespace App\Http\Requests\LeaveAttendance;

use App\Services\LeaveAttendance\Access;
use Illuminate\Foundation\Http\FormRequest;

class BulkShiftAssignmentEditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->hr($this->user());
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('assignment_ids_payload')) {
            $value = $this->input('assignment_ids_payload');
            $this->merge(['assignment_ids' => is_string($value) ? json_decode($value, true) : null]);
        }
    }

    public function rules(): array
    {
        return [
            'assignment_ids' => ['bail', 'required', 'array', 'min:1', 'max:5000', 'exists:hrla_shift_assignments,id'],
            'assignment_ids.*' => ['required', 'integer', 'distinct'],
            'shift_id' => ['nullable', 'integer', 'exists:hrla_shifts,id'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'end_action' => ['required', 'in:keep,set,clear'],
            'ends_on' => ['nullable', 'required_if:end_action,set', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (! $this->filled('shift_id') && ! $this->filled('starts_on') && $this->input('end_action') === 'keep') {
                $validator->errors()->add('changes', 'Choose a shift or effective date to update for the selected assignments.');
            }
        });
    }
}
