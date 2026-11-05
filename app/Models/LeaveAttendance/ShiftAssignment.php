<?php

namespace App\Models\LeaveAttendance;

use App\Models\Employee;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class ShiftAssignment extends Model
{
    protected $table = 'hrla_shift_assignments';

    protected $guarded = [];

    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where('starts_on', '<=', $date)
            ->where(fn ($q) => $q->whereNull('ends_on')->orWhere('ends_on', '>=', $date))
            ->whereNotExists(function ($q) use ($date) {
                $q->selectRaw('1')->from('hrla_shift_assignments as newer')
                    ->whereColumn('newer.employee_id', 'hrla_shift_assignments.employee_id')
                    ->whereColumn('newer.id', '>', 'hrla_shift_assignments.id')
                    ->where('newer.starts_on', '<=', $date)
                    ->where(fn ($dates) => $dates->whereNull('newer.ends_on')->orWhere('newer.ends_on', '>=', $date));
            });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }
}
