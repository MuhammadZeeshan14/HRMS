<?php

namespace App\Models\LeaveAttendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeaveApplication extends Model
{
    protected $table = 'hrla_leave_applications';

    protected $guarded = [];

    protected $casts = ['policy_snapshot' => 'array', 'chargeable_days' => 'decimal:2'];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function leaveType()
    {
        return $this->belongsTo(LeaveType::class);
    }

    public function decision()
    {
        return $this->hasOne(LeaveDecision::class, 'application_id');
    }
}
