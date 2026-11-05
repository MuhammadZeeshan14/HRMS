<?php

namespace App\Models\LeaveAttendance;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LeaveDecision extends Model
{
    protected $table = 'hrla_leave_decisions';

    protected $guarded = [];

    public $timestamps = false;

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
