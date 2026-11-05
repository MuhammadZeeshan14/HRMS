<?php

namespace Tests\Feature;

use Database\Seeders\AhamsonSetupSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\EmployeeAccountFixtures;
use Tests\TestCase;

class HrisSchemaTest extends TestCase
{
    use EmployeeAccountFixtures;
    use RefreshDatabase;

    public function test_explicit_workbook_seed_contains_only_the_reviewed_workbook_masters(): void
    {
        $this->seed(AhamsonSetupSeeder::class);
        $payload = json_decode(file_get_contents(database_path('seeders/data/ahamson_setup.json')), true);
        foreach ($payload['tables'] as $table => $rows) {
            $this->assertSame(count($rows), DB::table($table)->count(), $table);
        }
        $this->assertSame(0, DB::table('employees')->count());
        $this->assertFalse(Schema::hasTable('functions'));
        $this->assertArrayHasKey('departments', config('hris_setup'));
        $this->assertArrayNotHasKey('functions', config('hris_setup'));
        $this->assertFalse(Schema::hasColumn('fiscal_years', 'is_current'));
        foreach (['leave_types', 'leave_plans', 'attendance', 'benefit_plans', 'loan_plans', 'expense_claim_plans'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }

    public function test_company_branch_is_validated_and_designations_cannot_define_reporting_or_company_access(): void
    {
        $this->seed(AhamsonSetupSeeder::class);
        $this->actingAs($this->linkedHr());
        $bankId = DB::table('banks')->insertGetId(['name' => 'Other Bank', 'code' => 'OTHER', 'status' => 'active']);
        $branch = DB::table('branches')->first();
        $this->post('/master-data/companies', ['code' => 'BAD', 'name' => 'Wrong bank company', 'status' => 'active', 'bank_id' => $bankId, 'branch_id' => $branch->id])->assertSessionHasErrors('branch_id');
        $company = DB::table('companies')->insertGetId(['code' => 'OTHER', 'name' => 'Other Company', 'status' => 'active']);
        $department = DB::table('departments')->whereNotNull('company_id')->first();
        $payload = ['code' => 'INVALID', 'name' => 'Invalid Role', 'status' => 'active', 'company_id' => $company, 'department_id' => $department->id,
            'grade_id' => DB::table('grades')->value('id'), 'company_head' => 0,
            'job_category_id' => DB::table('job_categories')->insertGetId(['name' => 'Test category'])];
        $this->post('/master-data/designations', $payload)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('designations', ['code' => 'INVALID', 'name' => 'Invalid Role']);
        foreach (['company_id', 'department_id', 'grade_id', 'company_head'] as $field) {
            $this->assertFalse(Schema::hasColumn('designations', $field));
        }

    }

    public function test_branch_code_is_unique_within_its_bank_and_master_deletion_is_restricted(): void
    {
        $this->seed(AhamsonSetupSeeder::class);
        $this->actingAs($this->linkedHr());
        $branch = DB::table('branches')->first();
        $this->post('/master-data/branches', ['code' => $branch->code, 'name' => 'Duplicate Code', 'bank_id' => $branch->bank_id, 'status' => 'active'])->assertSessionHasErrors('code');
        $bankId = DB::table('banks')->insertGetId(['name' => 'Other Bank', 'code' => 'OTHER', 'status' => 'active']);
        $this->post('/master-data/branches', ['code' => $branch->code, 'name' => 'Different Bank', 'bank_id' => $bankId, 'status' => 'active'])->assertSessionHasNoErrors();
        $this->delete('/master-data/banks/'.$branch->bank_id)->assertSessionHasErrors('master');
    }

    public function test_company_can_be_created_before_banks_and_banking_added_later(): void
    {
        $this->actingAs($this->linkedHr());
        $this->get('/master-data/companies')->assertOk()
            ->assertSee('name="bank_id"', false)
            ->assertSee('name="branch_id"', false)
            ->assertSee('name="account_no"', false);
        $company = ['code' => 'NEW', 'name' => 'Independent Company', 'status' => 'active'];
        $this->post('/master-data/companies', $company)->assertForbidden();
        $id = DB::table('companies')->value('id');
        $this->assertDatabaseHas('companies', ['id' => $id, 'bank_id' => null, 'branch_id' => null]);
        $this->get('/master-data/companies')->assertOk()->assertSee('Additional banking details (optional)');

        $this->post('/master-data/banks', ['code' => 'BANK', 'name' => 'Independent Bank'])->assertSessionHasNoErrors();
        $bankId = DB::table('banks')->value('id');
        $this->post('/master-data/branches', ['code' => 'BR', 'name' => 'Main Branch', 'bank_id' => $bankId, 'status' => 'active'])->assertSessionHasNoErrors();
        $branchId = DB::table('branches')->value('id');
        $this->put('/master-data/companies/'.$id, $company + ['bank_id' => $bankId, 'branch_id' => $branchId, 'account_no' => '123'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('companies', ['id' => $id, 'bank_id' => $bankId, 'branch_id' => $branchId, 'account_no' => '123']);
        $this->post('/master-data/companies', ['code' => 'WITHBANK', 'name' => 'Company With Banking', 'status' => 'active', 'bank_id' => $bankId, 'branch_id' => $branchId, 'account_no' => '456'])
            ->assertForbidden();
        $this->assertDatabaseMissing('companies', ['code' => 'WITHBANK']);
    }

    public function test_master_status_is_constrained_in_the_database(): void
    {
        $this->expectException(QueryException::class);
        DB::table('training_types')->insert(['name' => 'Invalid', 'status' => 'unexpected']);
    }
}
