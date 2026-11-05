<?php

namespace Tests\Feature;

use App\Services\AdditiveSetupImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdditiveSetupImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_preserves_existing_records_maps_links_and_is_repeatable(): void
    {
        $company = DB::table('companies')->insertGetId(['code' => 'LOCAL', 'name' => 'Keep this name', 'phone' => '123']);
        $existing = DB::table('departments')->insertGetId(['code' => '1', 'name' => 'Management', 'company_id' => $company, 'level' => 1]);
        $before = DB::table('companies')->first();
        $row = fn ($key, $data, $refs = []) => ['key' => $key, 'data' => $data, 'refs' => $refs, 'sheet' => 'Test', 'row' => 2];
        $payload = ['source' => 'Test.xlsx', 'sha256' => 'test', 'tables' => [
            'companies' => [$row('03', ['code' => '03', 'name' => 'Source name', 'phone' => '999'])],
            'departments' => [
                $row('1', ['code' => '1', 'name' => 'Dev Ops.'], ['company_id' => ['companies', '03']]),
                $row('2', ['code' => '2', 'name' => 'Child'], ['company_id' => ['companies', '03'], 'parent_department_id' => ['departments', '1']]),
            ],
            'locations' => [$row('SITE', ['code' => 'SITE', 'name' => 'Office'], ['company_id' => ['companies', '03']])],
            'allowance_types' => [$row('Salary', ['name' => 'Salary'])],
            'allowances' => [
                $row('A', ['code' => 'A', 'name' => 'Allowance'], ['allowance_type_id' => ['allowance_types', 'Salary']]),
                $row('B', ['code' => 'B', 'name' => 'Allowance'], ['allowance_type_id' => ['allowance_types', 'Salary']]),
            ],
        ]];
        $overrides = ['companies' => ['03' => $company]];
        $importer = app(AdditiveSetupImporter::class);
        $importer->run($payload, $overrides, false);
        $this->assertDatabaseCount('departments', 1);
        $first = $importer->run($payload, $overrides, true);
        $this->assertSame((array) $before, (array) DB::table('companies')->first());
        $this->assertDatabaseHas('departments', ['id' => $existing, 'name' => 'Management', 'code' => '1']);
        $root = DB::table('departments')->where('code', 'XLS-DEP-1')->first();
        $child = DB::table('departments')->where('name', 'Child')->first();
        $this->assertSame($root->id, $child->parent_department_id);
        $this->assertSame(2, $child->level);
        $this->assertDatabaseHas('locations', ['code' => 'SITE', 'company_id' => $company]);
        $this->assertDatabaseCount('allowances', 2);
        $second = $importer->run($payload, $overrides, true);
        $this->assertSame(0, array_sum(array_column($second['summary'], 'inserted')));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->assertNotEmpty($first['differences']);
    }
}
