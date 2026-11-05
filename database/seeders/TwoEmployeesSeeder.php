<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class TwoEmployeesSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $before = [];
            foreach (array_keys(config('hris_setup')) as $table) {
                $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            $company = DB::table('companies')->where('status', 'active')
                ->whereExists(fn ($q) => $q->selectRaw('1')->from('locations')->whereColumn('locations.company_id', 'companies.id')->where('locations.status', 'active'))
                ->orderBy('id')->first();
            if (! $company) throw new RuntimeException('An existing active company and location are required.');
            $location = DB::table('locations')->where('company_id', $company->id)->where('status', 'active')->orderBy('id')->first();
            $departments = DB::table('departments')->where('status', 'active')
                ->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))
                ->orderByDesc('level')->orderBy('id')->get();
            $designations = DB::table('designations')->where('status', 'active')->orderBy('name')->get();
            $grade = DB::table('grades')->where('status', 'active')
                ->where(fn ($q) => $q->where('company_id', $company->id)->orWhereNull('company_id'))->orderBy('rank')->first();
            if ($departments->isEmpty() || $designations->isEmpty() || ! $grade) {
                throw new RuntimeException('Existing departments, designations and grades are required; no master data will be created.');
            }
            $religionId = DB::table('religions')->where('name', 'Islam')->value('id');
            $profiles = [
                ['SEED-2001', 'Hassan', 'Raza', 'Muhammad Raza', 'male', '1995-04-18', '2024-03-04', 'Islamabad'],
                ['SEED-2002', 'Ayesha', 'Siddiqui', 'Ahmed Siddiqui', 'female', '1997-08-23', '2025-02-10', 'Rawalpindi'],
            ];
            $created = 0;
            foreach ($profiles as $index => [$number, $first, $last, $father, $gender, $birth, $joining, $city]) {
                $existing = DB::table('employees')->where('employee_no', $number)->first();
                if ($existing) {
                    if ($existing->first_name !== $first || $existing->last_name !== $last) throw new RuntimeException('Employee number already in use: '.$number);
                    continue;
                }
                $department = $departments[$index % $departments->count()];
                $designation = $designations[$index % $designations->count()];
                DB::table('employees')->insert([
                    'employee_no' => $number, 'first_name' => $first, 'last_name' => $last,
                    'father_or_husband_name' => $father, 'gender' => $gender,
                    'date_of_birth' => $birth, 'date_of_joining' => $joining, 'place_of_birth' => $city,
                    'company_id' => $company->id, 'location_id' => $location->id,
                    'reporting_location_id' => $location->id, 'placement_location_id' => $location->id,
                    'department_id' => $department->id, 'designation_id' => $designation->id, 'grade_id' => $grade->id,
                    'religion_id' => $religionId, 'employment_status' => 'active', 'employment_type' => 'permanent',
                    'email' => strtolower($first.'.'.$last).'@example.test',
                    'profile_description' => 'Sample employee profile for HRIS demonstration.',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $created++;
                $this->command?->info("{$number}: {$first} {$last} | {$company->name} | {$department->name} | {$designation->name}");
            }
            foreach ($before as $table => $snapshot) {
                if (DB::table($table)->orderBy('id')->get()->toJson() !== $snapshot) throw new RuntimeException('Master data changed: '.$table);
            }
            $this->command?->info("{$created} new employees added. All master data verified unchanged.");
        });
    }
}
