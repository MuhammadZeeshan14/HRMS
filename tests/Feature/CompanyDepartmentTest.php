<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\EmployeeAccess;
use App\Services\ReportingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\EmployeeAccountFixtures;
use Tests\TestCase;

class CompanyDepartmentTest extends TestCase
{
    use EmployeeAccountFixtures, RefreshDatabase;

    private function fixture(): array
    {
        $company = Company::create(['code' => 'TEST', 'name' => 'Test Company', 'status' => 'active']);
        $level = Department::create(['code' => 'DIV', 'name' => 'Division', 'company_id' => $company->id, 'status' => 'active']);
        $child = Department::create(['code' => 'OPS', 'name' => 'Operations', 'company_id' => $company->id, 'parent_department_id' => $level->id, 'status' => 'active']);
        $head = DB::table('designations')->insertGetId(['code' => 'HEAD', 'name' => 'Operations Head', 'status' => 'active']);
        $role = DB::table('designations')->insertGetId(['code' => 'OFF', 'name' => 'Operations Officer', 'status' => 'active']);
        $manager = Employee::create(['employee_no' => 'MANAGER', 'first_name' => 'Manager', 'last_name' => 'Person', 'company_id' => $company->id, 'department_id' => $level->id, 'designation_id' => $head, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']);
        $employee = Employee::create(['employee_no' => 'STAFF', 'first_name' => 'Staff', 'last_name' => 'Person', 'company_id' => $company->id, 'department_id' => $child->id, 'designation_id' => $role, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active', 'primary_report_to_id' => $manager->id]);
        $actor = $this->linkedHr($company);
        $this->actingAs($actor);

        return ['company' => $company->id, 'level' => $level->id, 'child' => $child->id, 'manager' => $manager->id, 'employee' => $employee->id];
    }

    /** Preserve historical unassigned data for authorized review. */
    private function legacyEmployee(int $company, string $number, string $name): int
    {
        return DB::table('employees')->insertGetId(['employee_no' => $number, 'first_name' => $name, 'last_name' => 'Person', 'company_id' => $company, 'date_of_joining' => '2026-01-01', 'employment_status' => 'active']);
    }

    public function test_employees_are_placed_in_their_actual_departments_and_ancestor_reporting_positions(): void
    {
        $f = $this->fixture();
        $response = $this->get('/company-department?company_id='.$f['company'])->assertOk();
        $nodes = $response->viewData('nodes');
        $this->assertSame('department-'.$f['level'], $nodes['department-'.$f['child']]['parent']);
        $this->assertSame([$f['manager']], $nodes['department-'.$f['level']]['employees']->pluck('id')->all());
        $this->assertSame([$f['employee']], $nodes['department-'.$f['child']]['employees']->pluck('id')->all());
        $this->assertSame('Manager Person', $nodes['department-'.$f['child']]['employees']->first()->primary_manager);
        $response->assertSee('Level 1')->assertSee('Level 2')->assertSee('Staff Person')->assertSee('Manager Person');
    }

    public function test_company_and_status_filters_and_unassigned_staff(): void
    {
        $f = $this->fixture();
        $other = DB::table('companies')->insertGetId(['code' => 'OTHER', 'name' => 'Other Company', 'status' => 'active']);
        $this->legacyEmployee($other, 'OTHER', 'Outside');
        $unassigned = $this->legacyEmployee($f['company'], 'UNASSIGNED', 'Unassigned');
        $inactive = $this->legacyEmployee($f['company'], 'INACTIVE', 'Inactive');
        DB::table('employees')->where('id', $inactive)->update(['employment_status' => 'inactive']);
        $response = $this->get('/company-department?company_id='.$f['company'])->assertOk()->assertSee('Unassigned Person')->assertSee('Employees without a department')->assertDontSee('Inactive Person')->assertDontSee('Outside Person');
        $this->assertSame([$unassigned], $response->viewData('unassigned')->pluck('id')->all());
        $this->get('/company-department?company_id='.$f['company'].'&staff=all')->assertOk()->assertSee('Inactive Person');
        $this->get('/company-department?company_id='.$other)->assertForbidden();
    }

    public function test_circular_imported_department_links_remain_visible_without_granting_ancestry(): void
    {
        $f = $this->fixture();
        DB::table('departments')->where('id', $f['level'])->update(['parent_department_id' => $f['child']]);
        $this->get('/company-department')->assertOk()->assertSee('Circular department link')->assertSee('Staff Person')->assertSee('Manager Person');
        $this->assertDatabaseHas('departments', ['id' => $f['level'], 'parent_department_id' => $f['child']]);
        $access = app(EmployeeAccess::class);
        $this->assertNull($access->source(Employee::findOrFail($f['manager'])));
        $this->assertSame([], $access->ancestors($f['child']));
        $this->assertArrayHasKey('department_id', app(ReportingService::class)->errors(Employee::findOrFail($f['employee'])));
    }

    public function test_access_validation_and_company_selection(): void
    {
        $this->get('/company-department')->assertRedirect('/login');
        $this->actingAs(User::factory()->create(['role' => 'employee']))->get('/company-department')->assertRedirect('/login');
        $this->actingAs($this->linkedHr())->get('/company-department')->assertOk();
        $this->get('/company-department?company_id=99999')->assertSessionHasErrors('company_id');
    }
}
