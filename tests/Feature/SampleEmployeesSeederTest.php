<?php

namespace Tests\Feature;

use Database\Seeders\AhamsonSetupSeeder;
use Database\Seeders\SampleEmployeesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SampleEmployeesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_ten_varied_profiles_have_complete_non_overlapping_related_records_and_can_be_seeded_twice(): void
    {
        $this->seed(AhamsonSetupSeeder::class);
        config(['employee_access.sample_department_id' => DB::table('departments')->where('company_id', DB::table('companies')->where('code', '03')->value('id'))->whereNull('parent_department_id')->value('id')]);
        $this->seed(SampleEmployeesSeeder::class);
        $employees = DB::table('employees')->where('employee_no', 'like', 'SAMPLE-%')->get();
        $this->assertCount(10, $employees);
        $this->assertCount(10, $employees->pluck('designation_id')->unique());
        $snapshot = [];
        foreach (['employees', 'employee_experiences', 'employee_trainings', 'salary_histories'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach ($employees as $employee) {
            $this->assertSame(2, DB::table('employee_experiences')->where('employee_id', $employee->id)->count());
            $this->assertSame(2, DB::table('employee_trainings')->where('employee_id', $employee->id)->count());
            $salaries = DB::table('salary_histories')->where('employee_id', $employee->id)->orderBy('effective_from')->get();
            $this->assertCount(3, $salaries);
            $this->assertSame(substr($employee->date_of_joining, 0, 10), $salaries[0]->effective_from);
            $this->assertLessThan($salaries[1]->effective_from, $salaries[0]->effective_to);
            $this->assertLessThan($salaries[2]->effective_from, $salaries[1]->effective_to);
            $this->assertNull($salaries[2]->effective_to);
            $this->assertGreaterThan($salaries[0]->basic_salary, $salaries[2]->basic_salary);
        }
        $this->seed(SampleEmployeesSeeder::class);
        foreach ($snapshot as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->orderBy('id')->get()->toJson());
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
