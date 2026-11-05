<?php

namespace App\Models\LeaveAttendance;

use Illuminate\Database\Eloquent\Model;

class PunchEvent extends Model
{
    protected $table = 'hrla_punch_events';

    protected $guarded = [];

    public $timestamps = false;
}
