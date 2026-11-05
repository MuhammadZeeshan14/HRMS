<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hrla_shifts', 'hrla_holidays', 'hrla_leave_types', 'hrla_leave_policies', 'hr_documents'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('company_id')->nullable()->constrained()->restrictOnDelete());
            $companies = DB::table('employees')->distinct()->pluck('company_id');
            if ($companies->count() === 1) {
                DB::table($table)->update(['company_id' => $companies->first()]);
            }
        }
    }

    public function down(): void
    {
        foreach (['hrla_shifts', 'hrla_holidays', 'hrla_leave_types', 'hrla_leave_policies', 'hr_documents'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropConstrainedForeignId('company_id');
            });
        }
    }
};
