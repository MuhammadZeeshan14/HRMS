<?php

namespace App\Http\Requests\LeaveAttendance;

use App\Services\EmployeeAccess;
use App\Support\HrlaTime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->is_active;
    }

    protected function prepareForValidation(): void
    {
        $data = [];
        foreach (['time_in', 'time_out'] as $field) {
            $value = $this->input($field);
            $clock = $this->input($field.'_clock');
            if ($this->has($field.'_clock_hour') || $this->has($field.'_clock_minute')) {
                $clock = HrlaTime::fromParts($this->input($field.'_clock_hour'), $this->input($field.'_clock_minute'), $this->input($field.'_clock_period'));
            }
            if ($this->has($field.'_date') || $this->has($field.'_clock')) {
                $data[$field] = blank($clock) ? null : HrlaTime::combine($this->input($field.'_date'), $clock, $value);

                continue;
            }
            $value = HrlaTime::parseStamp($value);
            if ($value !== $this->input($field)) {
                $data[$field] = $value;
            }
            if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/D', $value)) {
                $data[$field] = str_replace('T', ' ', $value).(strlen($value) === 16 ? ':00' : '');
            }
        }
        if ($this->filled('base_version_id') && ctype_digit((string) $this->input('base_version_id'))) {
            $data['base_version_id'] = (int) $this->input('base_version_id');
        }
        $this->merge($data);
    }

    public function rules(): array
    {
        $employee = ! app(EmployeeAccess::class)->admin($this->user());

        return ['employee_id' => ['nullable', 'integer', 'exists:employees,id'], 'shift_date' => $employee ? ['required', 'date_format:Y-m-d', 'before:'.now(config('app.timezone'))->toDateString()] : ['required', 'date_format:Y-m-d'],
            'base_version_id' => ['nullable', 'integer'], 'time_in' => ['nullable', 'date_format:Y-m-d H:i:s'], 'time_out' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'reason_type' => ['required', Rule::in(['missed_punch', 'client_meeting', 'correction', 'other'])], 'reason' => [$employee ? 'required' : 'nullable', 'required_if:reason_type,other', 'string', 'max:2000'],
            'client_name' => ['nullable', 'required_if:reason_type,client_meeting', 'string', 'max:255']];
    }

    public function messages(): array
    {
        return ['shift_date.before' => 'Edit / Mark Attendance is for previous days only. Use Attendance Check In / Check Out for today.',
            'reason.required' => 'Explain the missed attendance or correction before submitting.',
            'reason.required_if' => 'Remarks are required when the reason type is Other.',
            'time_in.date_format' => 'Enter a valid check-in date and time, for example 9:05 AM.',
            'time_out.date_format' => 'Enter a valid check-out date and time, for example 6:05 PM.'];
    }
}
