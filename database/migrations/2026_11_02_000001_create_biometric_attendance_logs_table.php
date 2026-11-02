<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('biometric_attendance_logs')) {
            Schema::create('biometric_attendance_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('employee_code')->index();
                $table->dateTime('log_datetime')->index();
                $table->date('log_date')->index();
                $table->time('log_time');
                $table->dateTime('downloaded_at')->nullable();
                $table->string('device_sn')->index();
                $table->string('punch_state')->nullable()->default('Check In');
                $table->boolean('is_synced')->default(false);
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();

                $table->unique(['employee_code', 'log_datetime', 'device_sn'], 'bio_emp_datetime_device_unique');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_attendance_logs');
    }
};
