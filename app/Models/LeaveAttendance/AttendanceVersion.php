<?php

namespace App\Models\LeaveAttendance;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AttendanceVersion extends Model
{
    protected $table = 'hrla_attendance_versions';

    protected $guarded = [];

    public $timestamps = false;

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function correction()
    {
        return $this->belongsTo(CorrectionRequest::class, 'correction_id');
    }
}
