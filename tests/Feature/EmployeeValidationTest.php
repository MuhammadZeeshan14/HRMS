<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EmployeeValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoSeeder::class);
        \App\Models\User::query()->update(['must_change_password'=>false]);
        $this->actingAs(User::where('role', 'admin')->firstOrFail());
    }

    private function payload(array $extra = []): array
    {
        return array_replace(['employee_no' => 'VALIDATION-1', 'full_name' => 'Test Employee', 'date_of_joining' => '2026-01-01', 'company_id' => Company::value('id'), 'department_id'=>\App\Models\Department::whereNull('parent_department_id')->value('id'), 'employment_status' => 'active', 'employment_type' => 'permanent'], $extra);
    }

    public function test_invalid_dates_contact_details_and_duplicate_languages_are_rejected(): void
    {
        $language = DB::table('languages')->value('id');
        $this->post(route('employees.store'), $this->payload(['date_of_birth' => today()->toDateString(), 'cnic' => '123', 'phone' => 'abc', 'language_ids' => [$language, $language], 'relatives' => [['name' => 'Child', 'date_of_birth' => today()->addDay()->toDateString()]]]))
            ->assertSessionHasErrors(['date_of_birth', 'cnic', 'phone', 'language_ids.0', 'relatives.0.date_of_birth']);
        $this->assertDatabaseMissing('employees', ['employee_no' => 'VALIDATION-1']);
    }

    public function test_date_ranges_incomplete_rows_and_salary_overlaps_are_rejected(): void
    {
        $this->post(route('employees.store'), $this->payload([
            'trainings' => [['title' => 'Training', 'starts_on' => '2026-02-01', 'ends_on' => '2026-01-01']],
            'qualifications' => [['degree' => 'MBA']],
            'salaries' => [['effective_from' => '2026-01-01', 'basic_salary' => 100], ['effective_from' => '2026-02-01', 'basic_salary' => 200]],
        ]))->assertSessionHasErrors(['trainings.0.ends_on', 'qualifications.0.institution', 'salaries.1.effective_from']);
    }

    public function test_reporting_loops_and_foreign_child_records_are_rejected(): void
    {
        $employee = Employee::firstOrFail();
        $manager = Employee::whereKeyNot($employee->id)->firstOrFail();
        $manager->update(['primary_report_to_id' => $employee->id]);
        $child = DB::table('employee_relatives')->insertGetId(['employee_id' => $manager->id, 'name' => 'Other child', 'relationship' => 'child']);
        $this->put(route('employees.update', $employee), $this->payload(['employee_no' => $employee->employee_no, 'primary_report_to_id' => $manager->id, 'relatives' => [['id' => $child, '_delete' => 1]]]))
            ->assertSessionHasErrors(['primary_report_to_id', 'relatives.0.id']);
        $this->assertDatabaseHas('employee_relatives', ['id' => $child]);
    }

    public function test_exit_only_endpoint_enforces_completion_and_ownership(): void
    {
        $employee = Employee::firstOrFail();
        $this->put(route('employees.exit.update', $employee), ['exits' => [['status' => 'completed', 'remarks' => 'Exit']]])
            ->assertSessionHasErrors(['exits.0.leaving_reason_id', 'exits.0.last_working_date']);
    }

    public function test_valid_normalized_contacts_and_nonoverlapping_salary_periods_save(): void
    {
        $this->post(route('employees.store'), $this->payload(['cnic' => '12345-1234567-1', 'phone' => '+92 300 1234567', 'salaries' => [['effective_from' => '2026-01-01', 'effective_to' => '2026-01-31', 'basic_salary' => 100, 'currency' => 'pkr'], ['effective_from' => '2026-02-01', 'basic_salary' => 200, 'currency' => 'PKR']]]))
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('employees', ['employee_no' => 'VALIDATION-1', 'cnic' => '1234512345671', 'phone' => '+923001234567']);
    }

    public function test_company_consistency_and_configured_age_policy_are_enforced(): void
    {
        config(['hris_validation.minimum_joining_age' => 18]);
        $company = Company::create(['code' => 'OTHER', 'name' => 'Other Company', 'status' => 'active']);
        $department = DB::table('departments')->insertGetId(['code' => 'OTHER', 'name' => 'Other Department', 'company_id' => $company->id, 'status' => 'active']);
        $this->post(route('employees.store'), $this->payload(['date_of_birth' => '2015-01-01', 'department_id' => $department]))->assertSessionHasErrors(['date_of_joining', 'department_id']);
    }

    public function test_used_master_record_cannot_be_deleted(): void
    {
        $employee = Employee::whereNotNull('department_id')->firstOrFail();
        $this->delete(route('master-data.destroy', ['master' => 'departments', 'id' => $employee->department_id]))->assertSessionHasErrors('master');
        $this->assertDatabaseHas('departments', ['id' => $employee->department_id]);
    }
}
