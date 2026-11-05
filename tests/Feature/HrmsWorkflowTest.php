<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrmsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\DemoSeeder::class);
        \App\Models\User::query()->update(['must_change_password'=>false]);
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Ahamson HR')->assertSee('ahamson-logo.png');
    }

    public function test_hris_dashboard_contains_only_employee_metrics(): void
    {
        $this->actingAs(User::where('role', 'admin')->first())
            ->get('/')->assertOk()->assertSee('Total employees')->assertSee('Active companies')
            ->assertDontSee('Pending leave')->assertDontSee('Open requisitions');
    }

    public function test_employee_entry_form_has_eight_tabs_with_personal_and_relative_subsections(): void
    {
        $this->actingAs(User::where('role', 'admin')->first());
        $response = $this->get(route('employees.create'))->assertOk();
        foreach (['Employee Info', 'Personal Info', 'Trainings / Certifications', 'Qualifications', 'Experiences', 'Relatives', 'References', 'Project Experience', 'Salary', 'Documents'] as $tab) {
            $response->assertSee($tab);
        }
        $response->assertDontSee('data-tab="form-exit"', false);
        $response->assertSee('name="full_name"', false)
            ->assertDontSee('name="first_name"', false)
            ->assertDontSee('name="middle_name"', false)
            ->assertDontSee('name="last_name"', false);
        $response->assertDontSee('data-tab="form-personal"', false)
            ->assertDontSee('data-tab="form-relatives"', false)
            ->assertSee('aria-label="Personal information"', false)
            ->assertSee('aria-label="Relatives and dependants"', false)
            ->assertSee('data-language-dialog', false)
            ->assertSee('data-open-language-dialog', false)
            ->assertSee('data-relative-dialog', false)
            ->assertSee('data-open-relative-dialog', false)
            ->assertDontSee('data-add="relatives"', false);
        $content = $response->getContent();
        $orderedLabels = ['for="employee_no"', 'for="full_name"', 'for="father_name"', 'for="date_of_joining"', 'for="gender"', 'for="company_id"', 'for="department_id"', 'for="designation_search"', 'for="grade_id"', 'for="employment_status"', 'for="employment_status_id"', 'for="location_id"', 'for="placement_location_id"', 'for="reporting_location_id"', 'for="primary_report"', 'for="secondary_report"', 'for="profile_description"', 'for="job_description"', 'for="email"', 'for="phone"', 'for="address"', 'for="cnic"', 'for="passport_no"', 'for="ntn"', 'for="eobi_no"', 'for="date_of_birth"', 'for="place_of_birth"', 'for="religion_id"'];
        $lastPosition = -1;
        foreach ($orderedLabels as $label) {
            $position = strpos($content, $label);
            $this->assertNotFalse($position, "Missing employee form field {$label}.");
            $this->assertGreaterThan($lastPosition, $position, "Employee form field {$label} is out of order.");
            $lastPosition = $position;
        }
        $this->assertSame(8, substr_count($response->getContent(), 'data-tab="form-'));
    }

    public function test_employee_edit_form_uses_redesign_with_saved_values(): void
    {
        $employee = Employee::with('designation')->firstOrFail();
        $this->actingAs(User::where('role', 'admin')->first())
            ->get(route('employees.edit', $employee))->assertOk()
            ->assertSee('employee-entry-refresh.css', false)
            ->assertSee('class="entry-grid"', false)
            ->assertSee('value="'.$employee->display_name.'"', false)
            ->assertSee('value="'.$employee->designation->name.' ('.$employee->designation->code.')"', false)
            ->assertSee(route('employees.update', $employee), false)
            ->assertSee('name="_method" value="PUT"', false)
            ->assertSee('Save changes');
    }

    public function test_employee_directory_renders_search_and_filter_results(): void
    {
        $employee = Employee::firstOrFail();
        $employee->update(['email' => 'directory-check@example.test']);
        $this->actingAs(User::where('role', 'admin')->first())
            ->get(route('employees.index', ['q' => 'directory-check@example.test', 'company_id' => $employee->company_id, 'per_page' => 25]))
            ->assertOk()->assertSee($employee->display_name)->assertSee('Details Completion')->assertSee('Company:');
        $this->get(route('employees.index', ['q' => 'no-matching-person-123']))
            ->assertOk()->assertSee('No employees match');
    }

    public function test_exit_workflow_is_available_from_directory_and_saves_separately(): void
    {
        $employee = Employee::firstOrFail();
        $this->actingAs(User::where('role', 'admin')->first());
        $this->get(route('employees.index'))->assertOk()->assertSee(route('employees.exit', $employee), false);
        $this->get(route('employees.show', $employee))->assertOk()->assertDontSee('data-tab="exit"', false);
        $this->get(route('employees.exit', $employee))->assertOk()->assertSee('Save Exit Records');
        $originalName = $employee->first_name;
        $languageCount = \Illuminate\Support\Facades\DB::table('employee_languages')->where('employee_id', $employee->id)->count();
        $this->put(route('employees.exit.update', $employee), ['exits' => [['status' => 'initiated', 'leaving_reason_id' => \Illuminate\Support\Facades\DB::table('leaving_reasons')->value('id'), 'remarks' => 'Separate exit workflow test']]])
            ->assertRedirect(route('employees.exit', $employee));
        $this->assertDatabaseHas('employee_exits', ['employee_id' => $employee->id, 'remarks' => 'Separate exit workflow test']);
        $this->assertSame($originalName, $employee->fresh()->first_name);
        $this->assertSame($languageCount, \Illuminate\Support\Facades\DB::table('employee_languages')->where('employee_id', $employee->id)->count());
        $this->actingAs(User::where('role', 'employee')->first())->get(route('employees.exit', $employee))->assertForbidden();
    }

    public function test_admin_can_create_employee_with_related_hris_records(): void
    {
        $admin = User::where('role', 'admin')->first();
        $company = Company::first();
        $this->actingAs($admin)->post(route('employees.store'), [
            'employee_no' => 'PC-2001', 'first_name' => 'Sana', 'last_name' => 'Ahmed', 'date_of_joining' => '2026-09-01',
            'company_id' => $company->id, 'department_id'=>\App\Models\Department::whereNull('parent_department_id')->value('id'), 'employment_status' => 'active', 'employment_type' => 'permanent',
            'dual_reporting' => 0,
            'qualifications' => [['institution' => 'City University', 'degree' => 'MBA', 'field_of_study' => 'Human Resources']],
            'salaries' => [['effective_from' => '2026-09-01', 'basic_salary' => 150000, 'allowances' => 10000, 'other_components' => 0, 'currency' => 'PKR']],
        ])->assertRedirect();

        $employee = Employee::where('employee_no', 'PC-2001')->firstOrFail();
        $this->assertDatabaseHas('employee_qualifications', ['employee_id' => $employee->id, 'degree' => 'MBA']);
        $this->assertDatabaseHas('salary_histories', ['employee_id' => $employee->id, 'basic_salary' => 150000]);
    }

    public function test_admin_can_create_employee_with_a_single_full_name_field(): void
    {
        $admin = User::where('role', 'admin')->first();
        $company = Company::first();

        $this->actingAs($admin)->post(route('employees.store'), [
            'employee_no' => 'PC-2002', 'full_name' => 'Sana Noor Ahmed', 'date_of_joining' => '2026-09-01',
            'company_id' => $company->id, 'department_id'=>\App\Models\Department::whereNull('parent_department_id')->value('id'), 'employment_status' => 'active', 'employment_type' => 'permanent',
        ])->assertRedirect();

        $this->assertDatabaseHas('employees', [
            'employee_no' => 'PC-2002', 'first_name' => 'Sana',
            'middle_name' => 'Noor', 'last_name' => 'Ahmed',
        ]);
    }

    public function test_hr_manager_can_manage_master_data(): void
    {
        $this->actingAs(User::where('role', 'hr_manager')->first())
            ->put(route('master-data.update', ['companies',Company::first()->id]), ['code' => 'LAB', 'name' => 'Ahamson Labs', 'status' => 'active'])
            ->assertRedirect();
        $this->assertDatabaseHas('companies', ['code' => 'LAB', 'name' => 'Ahamson Labs']);
    }

    public function test_employee_cannot_manage_master_data(): void
    {
        $this->actingAs(User::where('role', 'employee')->first())
            ->get(route('master-data.index'))->assertForbidden();
    }
}
