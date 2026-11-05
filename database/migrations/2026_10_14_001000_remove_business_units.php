<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['designations', 'possession_types'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('business_unit_id');
            });
        }
        Schema::table('designations', fn (Blueprint $table) => $table->dropColumn('unit_head'));
        Schema::dropIfExists('business_unit_departments');
        DB::table('business_units')->update(['parent_business_unit_id' => null]);
        Schema::dropIfExists('business_units');
    }

    public function down(): void
    {
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('parent_business_unit_id')->nullable()->constrained('business_units')->restrictOnDelete();
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });
        foreach (['designations', 'possession_types'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete());
        }
        Schema::table('designations', fn (Blueprint $table) => $table->boolean('unit_head')->default(false));
        Schema::create('business_unit_departments', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->primary(['business_unit_id', 'department_id'], 'business_unit_department_primary');
        });
    }
};
