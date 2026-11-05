<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DepartmentMigrationTest extends TestCase
{
    public function test_existing_departments_and_employee_links_survive_consolidation(): void
    {
        // Build the actual historical schema first, rather than applying later column removals before consolidation.
        $paths = array_filter(glob(database_path('migrations/*.php')), fn ($path) => basename($path) < '2026_10_13_003000_consolidate_departments.php');
        Artisan::call('migrate:fresh', ['--path' => array_values($paths), '--realpath' => true, '--force' => true]);
        $company = DB::table('companies')->insertGetId(['code' => 'C', 'name' => 'Company']);
        $hierarchy = DB::table('hierarchies')->insertGetId(['code' => 'D', 'name' => 'Existing structure', 'company_id' => $company]);
        $parent = DB::table('departments')->insertGetId(['code' => 'D', 'name' => 'Employee department', 'company_id' => $company]);
        $child = DB::table('departments')->insertGetId(['code' => 'CHILD', 'name' => 'Child', 'company_id' => $company, 'parent_department_id' => $parent]);
        DB::table('employees')->insert(['employee_no' => '1', 'first_name' => 'Test', 'last_name' => 'Person', 'date_of_joining' => '2026-01-01', 'company_id' => $company, 'department_id' => $child]);
        DB::table('designations')->insert(['code' => 'ROLE', 'name' => 'Role', 'hierarchy_id' => $hierarchy]);
        Artisan::call('migrate', ['--path' => [database_path('migrations/2026_10_13_003000_consolidate_departments.php')], '--realpath' => true, '--force' => true]);
        $this->assertFalse(Schema::hasTable('hierarchies'));
        $this->assertDatabaseCount('departments', 3);
        $newChild = DB::table('departments')->where('name', 'Child')->first();
        $newParent = DB::table('departments')->where('name', 'Employee department')->first();
        $this->assertSame($newParent->id, $newChild->parent_department_id);
        $this->assertSame(2, $newChild->level);
        $this->assertSame($newChild->id, DB::table('employees')->value('department_id'));
        $this->assertSame($hierarchy, DB::table('designations')->value('department_id'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame($newChild->id, DB::table('employees')->value('department_id'));
        $this->assertSame([], DB::select('PRAGMA foreign_key_check'));
    }
}
