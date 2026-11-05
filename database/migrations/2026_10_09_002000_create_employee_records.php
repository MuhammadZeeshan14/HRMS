<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('employee_no')->unique();
            $table->string('first_name');
            $table->string('middle_name')->nullable();
            $table->string('last_name');
            $table->string('father_or_husband_name')->nullable();
            $table->string('cnic')->nullable()->unique();
            $table->string('passport_no')->nullable();
            $table->string('ntn')->nullable();
            $table->string('eobi_no')->nullable();
            $table->string('gender')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('photo_path')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_joining');
            $table->foreignId('company_id')->constrained();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('reporting_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('placement_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('function_id')->nullable()->constrained('functions')->nullOnDelete();
            $table->foreignId('designation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained()->nullOnDelete();
            $table->string('employment_status')->default('active')->index();
            $table->string('employment_type')->default('permanent');
            $table->text('profile_description')->nullable();
            $table->text('job_description')->nullable();
            $table->boolean('dual_reporting')->default(false);
            $table->foreignId('primary_report_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('secondary_report_to_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('address')->nullable();
            $table->foreignId('religion_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['company_id', 'department_id', 'employment_status']);
        });
        Schema::create('employee_languages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->string('proficiency')->default('working');
            $table->timestamps();
            $table->unique(['employee_id', 'language_id']);
        });
        Schema::create('employee_trainings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('training_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('provider')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_certifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('certification_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->string('credential_no')->nullable();
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_qualifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('institution');
            $table->string('degree');
            $table->string('field_of_study')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->string('result')->nullable();
            $table->string('document_path')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_experiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('employer');
            $table->string('job_title');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_relatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('relationship');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->boolean('is_dependent')->default(false);
            $table->timestamps();
        });
        Schema::create('employee_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('relationship')->nullable();
            $table->string('organization')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('project_name');
            $table->string('role')->nullable();
            $table->string('client')->nullable();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->text('responsibilities')->nullable();
            $table->text('skills')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
        });
        Schema::create('salary_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('effective_from')->index();
            $table->date('effective_to')->nullable();
            $table->decimal('basic_salary', 14, 2);
            $table->decimal('allowances', 14, 2)->default(0);
            $table->decimal('other_components', 14, 2)->default(0);
            $table->string('currency', 3)->default('PKR');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('file_path');
            $table->date('issued_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });
        Schema::create('employee_exits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('leaving_reason_id')->nullable()->constrained()->nullOnDelete();
            $table->date('resignation_date')->nullable();
            $table->date('last_working_date')->nullable();
            $table->date('notice_end_date')->nullable();
            $table->string('clearance_status')->default('pending');
            $table->string('status')->default('initiated')->index();
            $table->text('remarks')->nullable();
            $table->foreignId('processed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['employee_exits', 'employee_documents', 'salary_histories', 'employee_projects', 'employee_references', 'employee_relatives', 'employee_experiences', 'employee_qualifications', 'employee_certifications', 'employee_trainings', 'employee_languages', 'employees'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
