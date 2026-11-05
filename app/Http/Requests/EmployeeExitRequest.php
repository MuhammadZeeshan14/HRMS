<?php

namespace App\Http\Requests;

class EmployeeExitRequest extends EmployeeRequest
{
    public function rules(): array
    {
        return array_filter(parent::rules(), fn ($key) => $key === 'exits' || str_starts_with($key, 'exits.'), ARRAY_FILTER_USE_KEY);
    }

    protected function prepareForValidation(): void {}
}
