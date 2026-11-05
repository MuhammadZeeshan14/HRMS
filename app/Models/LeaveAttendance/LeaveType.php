<?php

namespace App\Models\LeaveAttendance;

use App\Models\Concerns\CompanyConfiguration;
use Illuminate\Database\Eloquent\Model;

class LeaveType extends Model
{
    use CompanyConfiguration;

    protected $table = 'hrla_leave_types';

    protected $guarded = [];

    public function policies()
    {
        return $this->hasMany(LeavePolicy::class);
    }
}
