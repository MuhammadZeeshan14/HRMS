<?php

namespace App\Models\LeaveAttendance;

use App\Models\Concerns\CompanyConfiguration;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    use CompanyConfiguration;

    protected $table = 'hrla_shifts';

    protected $guarded = [];

    protected $casts = ['overnight' => 'boolean'];

    public function assignments()
    {
        return $this->hasMany(ShiftAssignment::class);
    }
}
