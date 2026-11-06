<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Employee extends Model
{
    protected $guarded = [];

    protected $casts = ['date_of_birth' => 'date', 'date_of_joining' => 'date', 'dual_reporting' => 'boolean', 'delegated_admin' => 'boolean', 'is_test_employee' => 'boolean'];

    protected $appends = ['display_name'];

    public function photoUrl(): string
    {
        $disk = Storage::disk('public');

        return config('filesystems.disks.public.driver') === 's3'
            ? $disk->temporaryUrl($this->photo_path, now()->addMinutes(5))
            : $disk->url($this->photo_path);
    }

    public function save(array $options = [])
    {
        return DB::transaction(function () use ($options) {
            // Serialize placement and reporting edits with access revocation and attendance decisions.
            DB::table('employees')->orderBy('id')->lockForUpdate()->get(['id']);

            return parent::save($options);
        }, 3);
    }

    protected function displayName(): Attribute
    {
        return Attribute::get(fn () => trim(collect([$this->first_name, $this->middle_name, $this->last_name])->filter()->join(' ')));
    }

    protected function profileCompletion(): Attribute
    {
        return Attribute::get(function () {
            $fields = ['employee_no', 'first_name', 'last_name', 'date_of_joining', 'company_id', 'department_id', 'designation_id', 'location_id', 'gender', 'date_of_birth', 'cnic', 'email', 'phone', 'address', 'employment_status', 'employment_type', 'grade_id', 'primary_report_to_id', 'photo_path', 'father_or_husband_name'];

            return (int) round(collect($fields)->filter(fn ($field) => filled($this->$field))->count() / count($fields) * 100);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function company()
    {
        return $this->belongsTo(Company::class);
    }

    public function employeeStatus()
    {
        return $this->belongsTo(EmploymentStatus::class, 'employment_status_id');
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function designation()
    {
        return $this->belongsTo(Designation::class);
    }

    public function grade()
    {
        return $this->belongsTo(Grade::class);
    }

    public function location()
    {
        return $this->belongsTo(Location::class);
    }

    public function reportingLocation()
    {
        return $this->belongsTo(Location::class, 'reporting_location_id');
    }

    public function placementLocation()
    {
        return $this->belongsTo(Location::class, 'placement_location_id');
    }

    public function primaryReport()
    {
        return $this->belongsTo(self::class, 'primary_report_to_id');
    }

    public function secondaryReport()
    {
        return $this->belongsTo(self::class, 'secondary_report_to_id');
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        return $q->when($term, fn ($x) => $x->where(fn ($s) => $s->where('employee_no', 'like', "%$term%")->orWhere('first_name', 'like', "%$term%")->orWhere('middle_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('cnic', 'like', "%$term%")->orWhere('email', 'like', "%$term%")));
    }

    public function scopeFilters(Builder $q, array $f): Builder
    {
        return $q->when($f['company_id'] ?? null, fn ($x, $v) => $x->where('company_id', $v))->when($f['department_id'] ?? null, fn ($x, $v) => $x->where('department_id', $v))->when($f['designation_id'] ?? null, fn ($x, $v) => $x->where('designation_id', $v))->when($f['status'] ?? null, fn ($x, $v) => $x->where('employment_status', $v));
    }
}
