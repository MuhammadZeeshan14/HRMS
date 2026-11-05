<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = ['salary_from', 'salary_to', 'increment_by', 'increment_value', 'percentage_of'];
        $columns = array_values(array_filter($columns, fn ($column) => Schema::hasColumn('designations', $column)));
        if ($columns) {
            Schema::table('designations', fn (Blueprint $table) => $table->dropColumn($columns));
        }
    }

    public function down(): void
    {
        Schema::table('designations', function (Blueprint $table) {
            $table->decimal('salary_from', 14, 2)->nullable();
            $table->decimal('salary_to', 14, 2)->nullable();
            $table->string('increment_by')->nullable();
            $table->decimal('increment_value', 14, 2)->nullable();
            $table->string('percentage_of')->nullable();
        });
    }
};
