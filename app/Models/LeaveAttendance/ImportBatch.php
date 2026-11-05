<?php

namespace App\Models\LeaveAttendance;

use Illuminate\Database\Eloquent\Model;

class ImportBatch extends Model
{
    protected $table = 'hrla_import_batches';

    protected $guarded = [];

    protected $casts = ['headers' => 'array', 'rows' => 'array', 'mapping' => 'array', 'results' => 'array'];
}
