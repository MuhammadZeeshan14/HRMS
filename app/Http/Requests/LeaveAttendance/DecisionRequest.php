<?php

namespace App\Http\Requests\LeaveAttendance;

use Illuminate\Foundation\Http\FormRequest;

class DecisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    public function rules(): array
    {
        return ['outcome' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'max:2000']];
    }
}
