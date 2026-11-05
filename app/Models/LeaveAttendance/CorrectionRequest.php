<?php

namespace App\Models\LeaveAttendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CorrectionRequest extends Model
{
    protected $table = 'hrla_correction_requests';

    protected $guarded = [];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function requester()
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function decision()
    {
        return $this->hasOne(CorrectionDecision::class, 'correction_id');
    }
}
