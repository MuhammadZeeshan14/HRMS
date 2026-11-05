<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('employment_status_id')->nullable()->constrained('employment_statuses')->restrictOnDelete();
        });
        Schema::table('hrla_leave_policies', function (Blueprint $table) {
            $table->dropUnique('hrla_policy_category_type');
            $table->foreignId('employment_status_id')->nullable()->constrained('employment_statuses')->restrictOnDelete();
            $table->unique(['employment_status_id', 'leave_type_id'], 'hrla_policy_status_type');
        });
        // Match only unambiguous classifications; preserve unmatched legacy values.
        $statuses = DB::table('employment_statuses')->get(['id', 'name'])->groupBy(fn ($status) => mb_strtolower(trim($status->name)));
        foreach ($statuses as $name => $matches) {
            if ($matches->count() !== 1) {
                continue;
            }
            foreach (['employees', 'hrla_leave_policies'] as $table) {
                DB::table($table)->whereNull('employment_status_id')->whereRaw('LOWER(TRIM(employment_type)) = ?', [$name])
                    ->update(['employment_status_id' => $matches->first()->id]);
            }
        }
        $this->finishSqliteRebuild();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }
        Schema::table('hrla_leave_policies', function (Blueprint $table) {
            $table->dropUnique('hrla_policy_status_type');
            $table->dropConstrainedForeignId('employment_status_id');
            $table->unique(['employment_type', 'leave_type_id'], 'hrla_policy_category_type');
        });
        Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('employment_status_id'));
        $this->finishSqliteRebuild();
    }

    private function finishSqliteRebuild(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // Rebuilt parent tables can leave SQLite's deferred-constraint counter stale.
            // Verify actual references before resetting it so a populated migration can commit.
            if (DB::select('PRAGMA foreign_key_check')) {
                throw new RuntimeException('Employee-status migration found invalid foreign-key references.');
            }
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }
    }
};
