<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            foreach (['address', 'website', 'phone', 'fax', 'ntn', 'account_no'] as $column) {
                $table->string($column)->nullable();
            }
            $table->foreignId('bank_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->restrictOnDelete();
        });
        Schema::table('locations', function (Blueprint $table) {
            foreach (['phone', 'fax', 'address'] as $column) {
                $table->string($column)->nullable();
            }
        });
        foreach (['hierarchies', 'business_units'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropUnique(['name']);
                $table->string('code')->nullable()->unique();
                $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
                $table->foreignId($name === 'hierarchies' ? 'parent_hierarchy_id' : 'parent_business_unit_id')->nullable()->constrained($name)->restrictOnDelete();
                if ($name === 'hierarchies') {
                    $table->unsignedInteger('level')->nullable();
                } else {
                    $table->text('description')->nullable();
                    $table->foreignId('hierarchy_id')->nullable()->constrained('hierarchies')->restrictOnDelete();
                }
            });
        }
        Schema::table('grades', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->constrained()->restrictOnDelete();
            $table->text('reference_titles')->nullable();
        });
        Schema::table('certification_types', fn (Blueprint $table) => $table->string('code')->nullable()->unique());
        Schema::table('designations', function (Blueprint $table) {
            $table->string('source_reporting_code')->nullable();
            $table->string('source_hierarchy_label')->nullable();
        });
        Schema::create('qualifications', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('level')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });
        Schema::create('specializations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->foreignId('qualification_id')->constrained()->restrictOnDelete();
            $table->string('status')->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('specializations');
        Schema::dropIfExists('qualifications');
        Schema::table('designations', fn (Blueprint $table) => $table->dropColumn(['source_reporting_code', 'source_hierarchy_label']));
        Schema::table('certification_types', fn (Blueprint $table) => $table->dropColumn('code'));
        Schema::table('grades', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
            $table->dropColumn('reference_titles');
        });
        foreach (['business_units', 'hierarchies'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropConstrainedForeignId('company_id');
                $table->dropConstrainedForeignId($name === 'hierarchies' ? 'parent_hierarchy_id' : 'parent_business_unit_id');
                if ($name === 'business_units') {
                    $table->dropConstrainedForeignId('hierarchy_id');
                    $table->dropColumn('description');
                } else {
                    $table->dropColumn('level');
                }
                $table->dropColumn('code');
                // Repeated business-unit names in the source must remain valid.
            });
        }
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn(['phone', 'fax', 'address']));
        Schema::table('companies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_id');
            $table->dropConstrainedForeignId('branch_id');
            $table->dropColumn(['address', 'website', 'phone', 'fax', 'ntn', 'account_no']);
        });
    }
};
