<?php

namespace App\Models\LeaveAttendance;

use App\Models\Concerns\CompanyConfiguration;
use App\Models\EmploymentStatus;
use Illuminate\Database\Eloquent\Model;

class LeavePolicy extends Model
{
    use CompanyConfiguration;

    protected $table = 'hrla_leave_policies';

    protected $guarded = [];

    protected $casts = ['allowance' => 'decimal:2', 'per_request_limit' => 'decimal:2'];

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function employeeStatus()
    {
        return $this->belongsTo(EmploymentStatus::class, 'employment_status_id');
    }
}
