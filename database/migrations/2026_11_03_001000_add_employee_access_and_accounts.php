<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $t) {
            $t->boolean('is_hr')->default(false);
        });
        Schema::table('employees', function (Blueprint $t) {
            $t->boolean('delegated_admin')->default(false);
            $t->boolean('is_test_employee')->default(false);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->string('login_id', 80)->nullable()->unique();
            $t->boolean('must_change_password')->default(true);
            $t->unsignedInteger('session_version')->default(1);
            $t->string('pending_recovery_email')->nullable();
        });
        Schema::create('account_access_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('employee_id')->constrained()->restrictOnDelete();
            $t->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action');
            $t->json('details')->nullable();
            $t->timestamp('created_at');
        });
        if (! Schema::hasTable('notifications')) {
            Schema::create('notifications', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('type');
                $t->morphs('notifiable');
                $t->text('data');
                $t->timestamp('read_at')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('account_access_events');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['login_id', 'must_change_password', 'session_version', 'pending_recovery_email']));
        Schema::table('employees', fn (Blueprint $t) => $t->dropColumn(['delegated_admin', 'is_test_employee']));
        Schema::table('departments', fn (Blueprint $t) => $t->dropColumn('is_hr'));
    }
};
