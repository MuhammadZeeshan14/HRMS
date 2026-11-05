<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hrla_settings', function (Blueprint $t) {
            $t->id();
            $t->boolean('secondary_approval')->default(true);
            $t->boolean('hr_leave_auto_approve')->default(false);
            $t->string('half_day_rule')->default('disabled');
            $t->timestamps();
        });
        Schema::create('hrla_shifts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->time('start_time');
            $t->time('end_time');
            $t->boolean('overnight');
            $t->timestamps();
        });
        Schema::create('hrla_shift_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('shift_id')->constrained('hrla_shifts')->restrictOnDelete();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->timestamps();
            $t->index(['employee_id', 'starts_on', 'ends_on'], 'hrla_assignment_dates');
        });
        Schema::create('hrla_holidays', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->date('date')->unique();
            $t->timestamps();
        });
        Schema::create('hrla_leave_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 30)->unique();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('pay_treatment')->nullable();
            $t->timestamps();
        });
        Schema::create('hrla_leave_policies', function (Blueprint $t) {
            $t->id();
            $t->string('employment_type');
            $t->foreignId('leave_type_id')->constrained('hrla_leave_types')->restrictOnDelete();
            $t->string('allowance_kind');
            $t->decimal('allowance', 8, 2)->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->boolean('deduct_balance')->nullable();
            $t->boolean('eligible')->nullable();
            $t->decimal('per_request_limit', 8, 2)->nullable();
            $t->text('notes')->nullable();
            $t->timestamps();
            $t->unique(['employment_type', 'leave_type_id'], 'hrla_policy_category_type');
        });
        Schema::create('hrla_leave_applications', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $t->foreignId('leave_type_id')->constrained('hrla_leave_types')->restrictOnDelete();
            $t->date('form_date');
            $t->date('starts_on');
            $t->date('ends_on');
            $t->string('session')->nullable();
            $t->decimal('chargeable_days', 8, 2);
            $t->text('reason');
            $t->string('status')->default('pending');
            $t->foreignId('policy_id')->nullable()->constrained('hrla_leave_policies')->restrictOnDelete();
            $t->json('policy_snapshot')->nullable();
            $t->timestamps();
            $t->index(['employee_id', 'status', 'starts_on', 'ends_on'], 'hrla_leave_calendar');
        });
        Schema::create('hrla_leave_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('application_id')->unique()->constrained('hrla_leave_applications')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('outcome');
            $t->text('reason');
            $t->timestamp('decided_at');
        });
        Schema::create('hrla_import_batches', function (Blueprint $t) {
            $t->id();
            $t->string('fingerprint', 64)->unique();
            $t->string('filename');
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->json('headers');
            $t->json('rows');
            $t->json('mapping')->nullable();
            $t->json('results')->nullable();
            $t->string('status')->default('preview');
            $t->timestamp('committed_at')->nullable();
            $t->timestamps();
        });
        Schema::create('hrla_correction_requests', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            $t->date('shift_date');
            $t->unsignedBigInteger('base_version_id')->nullable();
            $t->dateTime('time_in')->nullable();
            $t->dateTime('time_out')->nullable();
            $t->string('reason_type');
            $t->text('reason');
            $t->string('client_name')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
            $t->index(['employee_id', 'status']);
        });
        Schema::create('hrla_correction_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('correction_id')->unique()->constrained('hrla_correction_requests')->restrictOnDelete();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('outcome');
            $t->text('reason');
            $t->timestamp('decided_at');
        });
        Schema::create('hrla_attendance_versions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->date('shift_date');
            $t->foreignId('shift_id')->constrained('hrla_shifts')->restrictOnDelete();
            $t->string('shift_name');
            $t->dateTime('shift_start');
            $t->dateTime('shift_end');
            $t->dateTime('time_in')->nullable();
            $t->dateTime('time_out')->nullable();
            $t->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $t->string('source');
            $t->string('reason_type');
            $t->text('reason');
            $t->string('client_name')->nullable();
            $t->foreignId('correction_id')->nullable()->unique()->constrained('hrla_correction_requests')->restrictOnDelete();
            $t->foreignId('batch_id')->nullable()->constrained('hrla_import_batches')->restrictOnDelete();
            $t->unsignedInteger('source_row')->nullable();
            $t->timestamp('committed_at');
            $t->index(['employee_id', 'shift_date', 'committed_at', 'id'], 'hrla_effective_version');
            $t->unique(['batch_id', 'source_row']);
        });
        Schema::table('hrla_correction_requests', function (Blueprint $t) {
            $t->foreign('base_version_id')->references('id')->on('hrla_attendance_versions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hrla_correction_requests', fn (Blueprint $t) => $t->dropForeign(['base_version_id']));
        foreach (['attendance_versions', 'correction_decisions', 'correction_requests', 'import_batches', 'leave_decisions', 'leave_applications', 'leave_policies', 'leave_types', 'holidays', 'shift_assignments', 'shifts', 'settings'] as $name) {
            Schema::dropIfExists('hrla_'.$name);
        }
    }
};
