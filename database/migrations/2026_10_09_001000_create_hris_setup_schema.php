<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function status(Blueprint $table): void
    {
        $table->enum('status', ['active', 'inactive'])->default('active')->index();
        $table->timestamps();
    }

    private function salary(Blueprint $table): void
    {
        $table->decimal('salary_from', 14, 2)->nullable();
        $table->decimal('salary_to', 14, 2)->nullable();
        $table->string('increment_by')->nullable();
        $table->decimal('increment_value', 14, 2)->nullable();
        $table->string('percentage_of')->nullable();
    }

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'hr_manager', 'employee'])->default('employee')->index();
            $table->boolean('is_active')->default(true);
        });
        Schema::create('banks', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $this->status($table);
        });
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->restrictOnDelete();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->unique(['bank_id', 'code']);
            $table->unique(['bank_id', 'name']);
            $this->status($table);
        });
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('address', 500)->nullable();
            $table->string('website')->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('fax', 64)->nullable();
            $table->string('ntn', 64)->nullable();
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
            $table->string('account_no', 100)->nullable();
            $this->status($table);
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('address', 500)->nullable();
            $table->string('phone', 64)->nullable();
            $table->string('fax', 64)->nullable();
            $this->status($table);
        });
        Schema::create('hierarchies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name');
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('level')->nullable();
            $table->foreignId('parent_hierarchy_id')->nullable()->constrained('hierarchies')->restrictOnDelete();
            $this->status($table);
        });
        Schema::create('business_units', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name'); // Names repeat at different hierarchy levels in Excel.
            $table->text('description')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('hierarchy_id')->nullable()->constrained('hierarchies')->restrictOnDelete();
            $table->foreignId('parent_business_unit_id')->nullable()->constrained('business_units')->restrictOnDelete();
            $this->status($table);
        });
        Schema::create('job_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $this->status($table);
        });
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $this->status($table);
        });
        Schema::create('grades', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('rank')->default(1)->index();
            $table->decimal('award_amount', 14, 2)->nullable();
            $table->text('reference_titles')->nullable();
            $this->salary($table);
            $this->status($table);
        });
        Schema::create('designations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('hierarchy_id')->nullable()->constrained('hierarchies')->restrictOnDelete();
            $table->foreignId('business_unit_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('job_category_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('domain')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('report_to_designation_id')->nullable()->constrained('designations')->restrictOnDelete();
            $table->boolean('company_head')->default(false);
            $table->boolean('unit_head')->default(false);
            $table->date('job_description_effective_from')->nullable();
            $table->string('job_description_file')->nullable();
            $table->string('source_reporting_code', 64)->nullable();
            $table->string('source_hierarchy_label')->nullable();
            $this->salary($table);
            $this->status($table);
        });
        Schema::create('certification_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable()->unique();
            $table->string('name')->unique();
            $this->status($table);
        });
        Schema::create('certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('certification_type_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('validity_months')->nullable();
            $this->status($table);
        });
        foreach (['religions', 'languages'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('code', 64)->nullable()->unique();
                $table->string('name')->unique();
                $table->text('description')->nullable();
                $this->status($table);
            });
        }
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('file_extensions')->nullable();
            $this->status($table);
        });
        Schema::create('hr_documents', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->nullable();
            $table->string('name');
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('document_file');
            $table->boolean('publish_on_portal')->default(false)->index();
            $table->timestamps();
        });
        Schema::create('training_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $this->status($table);
        });
        Schema::create('leaving_reasons', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type')->nullable();
            $table->text('description')->nullable();
            $this->status($table);
        });
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('level')->nullable();
            $this->status($table);
        });
        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('qualification_id')->constrained()->restrictOnDelete();
            $this->status($table);
        });
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->date('published_on');
            $table->string('title');
            $table->text('body');
            $table->enum('status', ['draft', 'published', 'archived'])->default('published')->index();
            $table->timestamps();
        });
        Schema::create('news_companies', function (Blueprint $table) {
            $table->foreignId('news_id')->constrained('news')->cascadeOnDelete();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->primary(['news_id', 'company_id']);
        });
        // Existing employee screens depend on these; they are not HRIS setup forms.
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_department_id')->nullable()->constrained('departments')->restrictOnDelete();
            $table->string('code', 64)->unique();
            $table->string('name');
            $this->status($table);
        });
        Schema::create('functions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $this->status($table);
        });
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->string('auditable_type')->index();
            $table->unsignedBigInteger('auditable_id')->nullable()->index();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Clear self references before dropping populated tables on SQLite.
        foreach (['departments' => 'parent_department_id', 'designations' => 'report_to_designation_id', 'business_units' => 'parent_business_unit_id', 'hierarchies' => 'parent_hierarchy_id'] as $name => $parent) {
            if (Schema::hasTable($name)) {
                DB::table($name)->update([$parent => null]);
            }
        }
        foreach (['audit_logs', 'functions', 'departments', 'news_companies', 'news', 'specializations', 'qualifications', 'leaving_reasons', 'training_types', 'hr_documents', 'document_types', 'languages', 'religions', 'certifications', 'certification_types', 'designations', 'grades', 'fiscal_years', 'job_categories', 'business_units', 'hierarchies', 'locations', 'companies', 'branches', 'banks'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
