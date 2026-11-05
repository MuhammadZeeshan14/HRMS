<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('employees', 'function_id')) {
            Schema::table('employees', fn (Blueprint $table) => $table->dropConstrainedForeignId('function_id'));
        }
        Schema::dropIfExists('functions');

        if (Schema::hasColumn('possession_types', 'business_unit_id')) {
            Schema::table('possession_types', fn (Blueprint $table) => $table->dropConstrainedForeignId('business_unit_id'));
        }

        $designationRelations = ['company_id', 'department_id', 'business_unit_id', 'grade_id', 'job_category_id', 'report_to_designation_id', 'hierarchy_id'];
        foreach ($designationRelations as $column) {
            if (Schema::hasColumn('designations', $column)) {
                Schema::table('designations', fn (Blueprint $table) => $table->dropConstrainedForeignId($column));
            }
        }
        $designationExtras = ['domain', 'company_head', 'unit_head', 'job_description_effective_from', 'job_description_file', 'source_reporting_code', 'source_department_label', 'source_hierarchy_label', 'salary_from', 'salary_to', 'increment_by', 'increment_value', 'percentage_of'];
        $designationExtras = array_values(array_filter($designationExtras, fn ($column) => Schema::hasColumn('designations', $column)));
        if ($designationExtras) {
            Schema::table('designations', fn (Blueprint $table) => $table->dropColumn($designationExtras));
        }

        Schema::dropIfExists('business_unit_departments');
        Schema::dropIfExists('business_unit_hierarchies');
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
        Schema::create('business_unit_departments', function (Blueprint $table) {
            $table->foreignId('business_unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained('departments')->restrictOnDelete();
            $table->primary(['business_unit_id', 'department_id'], 'business_unit_department_primary');
        });
        Schema::table('designations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('job_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('report_to_designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->boolean('company_head')->default(false);
            $table->boolean('unit_head')->default(false);
            $table->date('job_description_effective_from')->nullable();
            $table->string('job_description_file')->nullable();
            $table->string('source_reporting_code', 64)->nullable();
            $table->string('source_department_label')->nullable();
            $table->decimal('salary_from', 14, 2)->nullable();
            $table->decimal('salary_to', 14, 2)->nullable();
            $table->string('increment_by')->nullable();
            $table->decimal('increment_value', 14, 2)->nullable();
            $table->string('percentage_of')->nullable();
        });
        Schema::table('possession_types', fn (Blueprint $table) => $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete());
        Schema::create('functions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->enum('status', ['active', 'inactive'])->default('active')->index();
            $table->timestamps();
        });
        Schema::table('employees', fn (Blueprint $table) => $table->foreignId('function_id')->nullable()->constrained()->nullOnDelete());
    }
};
