<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['hierarchies', 'business_units', 'job_categories'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('status')->default('active');
                $table->timestamps();
            });
        }
        Schema::table('fiscal_years', fn (Blueprint $table) => $table->string('status')->default('active'));
        foreach (['grades', 'religions', 'languages', 'banks', 'certifications', 'document_types', 'training_types', 'leaving_reasons', 'designations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->text('description')->nullable());
        }
        foreach (['religions', 'languages', 'banks'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->string('code')->nullable()->unique());
        }
        foreach (['grades', 'designations'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->decimal('salary_from', 14, 2)->nullable();
                $table->decimal('salary_to', 14, 2)->nullable();
                $table->string('increment_by')->nullable();
                $table->decimal('increment_value', 14, 2)->nullable();
                $table->string('percentage_of')->nullable();
            });
        }
        Schema::table('grades', fn (Blueprint $table) => $table->decimal('award_amount', 14, 2)->nullable());
        Schema::table('designations', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('hierarchy_id')->nullable()->constrained('hierarchies')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('job_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('domain')->nullable();
            $table->foreignId('report_to_designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->boolean('company_head')->default(false);
            $table->boolean('unit_head')->default(false);
            $table->date('job_description_effective_from')->nullable();
            $table->string('job_description_file')->nullable();
        });
        Schema::table('certifications', function (Blueprint $table) {
            $table->foreignId('certification_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('validity_months')->nullable();
        });
        Schema::table('document_types', fn (Blueprint $table) => $table->string('file_extensions')->nullable());
        Schema::table('leaving_reasons', fn (Blueprint $table) => $table->string('type')->nullable());
        Schema::create('hr_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name');
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('document_file');
            $table->boolean('publish_on_portal')->default(false);
            $table->timestamps();
        });
        Schema::create('news_companies', function (Blueprint $table) {
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->primary(['news_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_companies');
        Schema::dropIfExists('hr_documents');
        Schema::table('designations', function (Blueprint $table) {
            foreach (['company_id', 'hierarchy_id', 'business_unit_id', 'grade_id', 'job_category_id', 'report_to_designation_id'] as $column) {
                $table->dropConstrainedForeignId($column);
            }
            $table->dropColumn(['domain', 'company_head', 'unit_head', 'job_description_effective_from', 'job_description_file']);
        });
        Schema::table('certifications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certification_type_id');
            $table->dropColumn('validity_months');
        });
        Schema::table('fiscal_years', fn (Blueprint $table) => $table->dropColumn('status'));
        Schema::table('document_types', fn (Blueprint $table) => $table->dropColumn('file_extensions'));
        Schema::table('leaving_reasons', fn (Blueprint $table) => $table->dropColumn('type'));
        Schema::table('grades', fn (Blueprint $table) => $table->dropColumn('award_amount'));
        foreach (['grades', 'designations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn(['salary_from', 'salary_to', 'increment_by', 'increment_value', 'percentage_of']));
        }
        foreach (['religions', 'languages', 'banks'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('code'));
        }
        foreach (['grades', 'religions', 'languages', 'banks', 'certifications', 'document_types', 'training_types', 'leaving_reasons', 'designations'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('description'));
        }
        foreach (['hierarchies', 'business_units', 'job_categories'] as $name) {
            Schema::dropIfExists($name);
        }
    }
};
