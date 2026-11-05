<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Preserve legacy employee departments separately by ID when merging.
        $legacy = DB::table('departments')->orderBy('id')->get();
        $map = [];
        foreach ($legacy as $row) {
            $code = $row->code;
            while (DB::table('hierarchies')->where('code', $code)->exists()) {
                $code = 'D-'.$code;
            }
            $map[$row->id] = DB::table('hierarchies')->insertGetId([
                'code' => $code, 'name' => $row->name, 'company_id' => $row->company_id,
                'status' => $row->status, 'created_at' => $row->created_at, 'updated_at' => $row->updated_at,
            ]);
        }
        foreach ($legacy as $row) {
            DB::table('hierarchies')->where('id', $map[$row->id])->update(['parent_hierarchy_id' => $map[$row->parent_department_id] ?? null]);
        }
        Schema::table('employees', fn (Blueprint $table) => $table->dropForeign(['department_id']));
        // One update avoids remapping an employee twice if old/new IDs overlap.
        foreach (DB::table('employees')->whereNotNull('department_id')->get(['id', 'department_id']) as $employee) {
            DB::table('employees')->where('id', $employee->id)->update(['department_id' => $map[$employee->department_id]]);
        }
        DB::table('departments')->update(['parent_department_id' => null]);
        Schema::drop('departments');
        foreach (['hierarchies' => 'parent_hierarchy_id', 'designations' => 'hierarchy_id', 'business_units' => 'hierarchy_id', 'possession_types' => 'hierarchy_id', 'business_unit_hierarchies' => 'hierarchy_id'] as $name => $column) {
            Schema::table($name, fn (Blueprint $table) => $table->dropForeign([$column]));
            Schema::table($name, fn (Blueprint $table) => $table->renameColumn($column, str_replace('hierarchy', 'department', $column)));
        }
        Schema::rename('hierarchies', 'departments');
        Schema::rename('business_unit_hierarchies', 'business_unit_departments');
        Schema::table('designations', fn (Blueprint $table) => $table->renameColumn('source_hierarchy_label', 'source_department_label'));
        foreach (['departments' => 'parent_department_id', 'designations' => 'department_id', 'business_units' => 'department_id', 'possession_types' => 'department_id', 'business_unit_departments' => 'department_id'] as $name => $column) {
            Schema::table($name, fn (Blueprint $table) => $table->foreign($column)->references('id')->on('departments')->restrictOnDelete());
        }
        Schema::table('employees', fn (Blueprint $table) => $table->foreign('department_id')->references('id')->on('departments')->nullOnDelete());
        app(\App\Services\DepartmentLevelService::class)->synchronize();
    }

    public function down(): void
    {
        throw new RuntimeException('Department consolidation preserves merged records. Restore the pre-migration database backup to reverse it.');
    }
};
