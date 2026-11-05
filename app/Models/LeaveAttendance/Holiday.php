<?php

namespace App\Models\LeaveAttendance;

use App\Models\Concerns\CompanyConfiguration;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Holiday extends Model
{
    use CompanyConfiguration;

    protected $table = 'hrla_holidays';

    protected $guarded = [];

    protected function day(): Attribute
    {
        return Attribute::get(fn () => (int) substr($this->date, 8, 2));
    }

    protected function month(): Attribute
    {
        return Attribute::get(fn () => (int) substr($this->date, 5, 2));
    }
}
