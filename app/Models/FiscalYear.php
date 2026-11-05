<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FiscalYear extends Model
{
    protected $guarded = [];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date'];
}
