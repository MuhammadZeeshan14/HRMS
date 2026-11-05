<?php

namespace App\Models;

use App\Services\DepartmentLevelService;
use App\Services\ReportingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class Department extends Model
{
    protected $guarded = [];

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            DB::table('departments')->orderBy('id')->lockForUpdate()->get(['id']);
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);
            $reporting = app(ReportingService::class);
            $before = $reporting->invalidEdges();
            $result = parent::save($options);
            app(DepartmentLevelService::class)->synchronize();
            $reporting->protect($before);

            return $result;
        }, 3);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_department_id');
    }

    public function children()
    {
        return $this->hasMany(self::class, 'parent_department_id');
    }
}
