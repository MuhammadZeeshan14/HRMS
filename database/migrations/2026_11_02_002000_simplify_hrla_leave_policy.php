<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->deferSqlite();
        Schema::table('hrla_leave_policies', fn (Blueprint $table) => $table->dropColumn(['eligible', 'deduct_balance', 'period_start', 'period_end']));
        $this->finishSqlite();
    }

    public function down(): void
    {
        $this->deferSqlite();
        Schema::table('hrla_leave_policies', function (Blueprint $table) {
            $table->boolean('eligible')->nullable();
            $table->boolean('deduct_balance')->nullable();
            $table->date('period_start')->nullable();
            $table->date('period_end')->nullable();
        });
        $this->finishSqlite();
    }

    private function deferSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') DB::statement('PRAGMA defer_foreign_keys = ON');
    }

    private function finishSqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            if (DB::select('PRAGMA foreign_key_check')) throw new RuntimeException('Leave-policy migration found invalid foreign-key references.');
            DB::statement('PRAGMA defer_foreign_keys = OFF');
        }
    }
};
