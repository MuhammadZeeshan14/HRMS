<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EmployeeAccountFixtures;
use Tests\TestCase;

class BusinessUnitDepartmentsTest extends TestCase
{
    use EmployeeAccountFixtures, RefreshDatabase;

    public function test_retired_business_unit_routes_cannot_recreate_or_change_departments(): void
    {
        $this->actingAs($this->linkedHr());
        $before = DB::table('departments')->orderBy('id')->get()->toJson();
        $this->get('/master-data/business_units')->assertNotFound();
        $this->post('/master-data/business_units', ['code' => 'BU', 'name' => 'Unit', 'department_ids' => [1]])->assertNotFound();
        $this->put('/master-data/business_units/1', ['name' => 'Changed'])->assertNotFound();
        $this->delete('/master-data/business_units/1')->assertNotFound();
        $this->assertFalse(Schema::hasTable('business_units'));
        $this->assertFalse(Schema::hasTable('business_unit_departments'));
        $this->assertSame($before, DB::table('departments')->orderBy('id')->get()->toJson());
    }

    public function test_department_parent_cannot_reference_another_company(): void
    {
        $actor = $this->linkedHr();
        $other = Company::create(['code' => 'OTHER', 'name' => 'Other company', 'status' => 'active']);
        $foreign = Department::create(['code' => 'FOREIGN', 'name' => 'Foreign root', 'company_id' => $other->id, 'status' => 'active']);
        $this->actingAs($actor)->post('/master-data/departments', [
            'code' => 'CHILD', 'name' => 'Child', 'status' => 'active', 'company_id' => $actor->employee->company_id, 'parent_department_id' => $foreign->id,
        ])->assertSessionHasErrors('parent_department_id');
        $this->assertDatabaseMissing('departments', ['code' => 'CHILD']);
    }
}
