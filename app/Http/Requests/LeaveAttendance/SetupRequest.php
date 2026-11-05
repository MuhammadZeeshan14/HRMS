<?php

namespace App\Http\Requests\LeaveAttendance;

use App\Models\LeaveAttendance\Shift;
use App\Services\LeaveAttendance\Access;
use App\Support\HrlaTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() && app(Access::class)->hr($this->user());
    }

    public function rules(): array
    {
        $id = $this->route('id');

        return match ($this->route('kind')) {
            'shifts' => ['name' => ['required', 'string', 'max:255'], 'start_time' => ['required', 'date_format:H:i:s'], 'end_time' => ['required', 'date_format:H:i:s'], 'overnight' => ['required', 'boolean']],
            'holidays' => ['name' => ['required', 'string', 'max:255'], 'holiday_date' => ['sometimes', 'required', 'string', 'regex:/^[0-9]{2}\/[0-9]{2}$/D'],
                'day' => $this->has('holiday_date') ? ['nullable', 'integer'] : ['required', 'integer', 'between:1,31'],
                'month' => $this->has('holiday_date') ? ['nullable', 'integer'] : ['required', 'integer', 'between:1,12']],
            'types' => ['code' => ['required', 'string', 'max:30', Rule::unique('hrla_leave_types', 'code')->ignore($id)], 'name' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:2000'], 'pay_treatment' => ['nullable', 'in:paid,unpaid']],
            'policies' => ['employment_status_id' => ['required', 'integer', 'exists:employment_statuses,id'], 'leave_type_id' => ['required', 'exists:hrla_leave_types,id', Rule::unique('hrla_leave_policies')->where('employment_status_id', $this->input('employment_status_id'))->ignore($id)],
                'allowance_kind' => ['required', 'in:numeric,as_needed,unspecified'], 'allowance' => ['nullable', 'required_if:allowance_kind,numeric', 'numeric', 'min:0', 'max:99999'],
                'notes' => ['nullable', 'string', 'max:2000']],
            'assignments' => ['employee_id' => ['required', 'exists:employees,id'], 'shift_id' => ['required', 'exists:hrla_shifts,id'], 'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on']],
            default => abort(404),
        };
    }

    protected function prepareForValidation(): void
    {
        if ($this->route('kind') === 'holidays' && $this->has('holiday_date')) {
            $valid = is_string($this->input('holiday_date')) && preg_match('/^([0-9]{2})\/([0-9]{2})$/D', $this->input('holiday_date'), $parts);
            $this->merge(['day' => $valid ? (int) $parts[1] : null, 'month' => $valid ? (int) $parts[2] : null]);
        }
        // Continue accepting legacy date payloads; the UI supplies one DD/MM field.
        if ($this->route('kind') === 'holidays' && ! $this->hasAny(['day', 'month']) && is_string($this->input('date'))
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $this->input('date'), $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $this->merge(['day' => (int) $parts[3], 'month' => (int) $parts[2]]);
        }
        foreach (['start_time', 'end_time'] as $field) {
            if ($this->has($field.'_hour') || $this->has($field.'_minute')) {
                $this->merge([$field => HrlaTime::fromParts($this->input($field.'_hour'), $this->input($field.'_minute'), $this->input($field.'_period'))]);
            }
            $clock = HrlaTime::parseClock($this->input($field));
            if ($clock) {
                $record = $this->route('id') ? Shift::find($this->route('id')) : null;
                $original = $record?->$field;
                if ($original && substr($original, 0, 5) === substr($clock, 0, 5)) {
                    $clock = $original;
                }
                $this->merge([$field => $clock]);
            }
            if (is_string($this->input($field)) && preg_match('/^\d{2}:\d{2}$/D', $this->input($field))) {
                $this->merge([$field => $this->input($field).':00']);
            }
        }
    }

    public function messages(): array
    {
        return ['start_time.date_format' => 'Enter Start Time as hour:minute AM/PM, for example 9:00 AM.',
            'holiday_date.regex' => 'Enter the holiday date as DD/MM, for example 25/12.',
            'end_time.date_format' => 'Enter End Time as hour:minute AM/PM, for example 6:00 PM.'];
    }

    public function withValidator($validator): void
    {
        if ($this->route('kind') === 'holidays') {
            $validator->after(function ($validator) {
                if (! $validator->errors()->any() && ! checkdate((int) $this->input('month'), (int) $this->input('day'), 2000)) {
                    $validator->errors()->add($this->has('holiday_date') ? 'holiday_date' : 'day', 'Choose a valid day for the selected month.');
                }
            });
        }
    }
}
