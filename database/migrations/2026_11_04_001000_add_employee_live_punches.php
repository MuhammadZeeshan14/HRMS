<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
        });
        Schema::create('hrla_punch_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->string('action', 12);
            $table->dateTime('recorded_at');
            $table->date('attendance_date');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->double('accuracy');
            $table->foreignId('office_location_id')->constrained('locations')->restrictOnDelete();
            $table->decimal('office_latitude', 10, 7);
            $table->decimal('office_longitude', 10, 7);
            $table->double('distance_metres');
            $table->uuid('submission_id')->unique();
            $table->foreignId('attendance_version_id')->nullable()->constrained('hrla_attendance_versions')->restrictOnDelete();
            $table->dateTime('created_at');
            $table->index(['employee_id', 'attendance_date', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hrla_punch_events');
        Schema::table('locations', fn (Blueprint $table) => $table->dropColumn(['latitude', 'longitude']));
    }
};
