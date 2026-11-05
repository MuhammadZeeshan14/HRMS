<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('employee')->index();
            $table->boolean('is_active')->default(true);
        });

        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('city')->nullable();
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('status')->default('active')->index();
            $table->timestamps();
        });
        foreach (['functions', 'designations', 'grades'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name) {
                $table->id();
                $table->string('code')->unique();
                $table->string('name');
                if ($name === 'grades') {
                    $table->unsignedInteger('rank')->default(1);
                }
                $table->string('status')->default('active')->index();
                $table->timestamps();
            });
        }
        Schema::create('fiscal_years', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->date('starts_on');
            $table->date('ends_on');
            $table->boolean('is_current')->default(false)->index();
            $table->timestamps();
        });
        foreach (['languages', 'religions', 'banks', 'certification_types', 'certifications', 'document_types', 'training_types', 'leaving_reasons'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('name')->unique();
                $table->string('status')->default('active')->index();
                $table->timestamps();
            });
        }
        Schema::create('branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->unique(['bank_id', 'name']);
        });
        Schema::create('news', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->date('published_on')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamps();
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
        foreach (['audit_logs', 'news', 'branches', 'leaving_reasons', 'training_types', 'document_types', 'certifications', 'certification_types', 'banks', 'religions', 'languages', 'fiscal_years', 'grades', 'designations', 'functions', 'departments', 'locations', 'companies'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'is_active']));
    }
};
