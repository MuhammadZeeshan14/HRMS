<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AhamsonSetupSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AhamsonSetupSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_workbook_setup_is_imported_with_relationships_and_is_repeatable(): void
    {
        $this->seed(DemoSeeder::class);
        User::query()->update(['must_change_password' => false]);
        $employeesBefore = DB::table('employees')->get()->toJson();
        $seeder = new AhamsonSetupSeeder;
        $seeder->run();
        $this->assertDatabaseHas('companies', ['code' => '03', 'name' => 'A Hamson (Pvt) Limited']);
        $this->assertDatabaseHas('languages', ['code' => '001', 'name' => 'English']);
        $this->assertSame(1, DB::table('languages')->where('name', 'English')->count());
        $this->assertSame(1, DB::table('banks')->where('name', 'Habib Bank Limited')->count());
        $this->assertSame(36, DB::table('qualifications')->count());
        $this->assertSame(53, DB::table('specializations')->count());
        $ceo = DB::table('designations')->where('code', '2')->first();
        $director = DB::table('designations')->where('code', '3')->first();
        $this->assertNotNull($ceo);
        $this->assertNotNull($director);
        // Reporting belongs to employees and departments; the simplified master cannot restore legacy authority.
        foreach (['report_to_designation_id', 'grade_id', 'company_head', 'source_reporting_code'] as $field) {
            $this->assertFalse(Schema::hasColumn('designations', $field));
        }
        $this->assertDatabaseHas('designations', ['code' => 'XLS-DES-69', 'name' => 'Office Boy']);
        $this->assertSame($employeesBefore, DB::table('employees')->get()->toJson());
        $snapshot = [];
        foreach (array_keys($seeder->summary) as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        $seeder->run();
        foreach ($snapshot as $table => $data) {
            $this->assertSame($data, DB::table($table)->orderBy('id')->get()->toJson(), $table.' changed on second import');
            $this->assertSame(0, $seeder->summary[$table]['inserted']);
        }
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        $this->actingAs(User::where('role', 'admin')->first());
        foreach (array_keys($seeder->summary) as $table) {
            $this->get(route('master-data.index', $table))->assertOk();
        }
        $this->get(route('master-data.index', 'designations'))->assertDontSee('name="report_to_designation_id"', false);
    }
}
