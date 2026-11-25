<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_policies', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_policies', 'punch_pairing_mode')) {
                $table->string('punch_pairing_mode', 30)->default('first_last')->after('overtime_rate_per_hour');
            }
            if (!Schema::hasColumn('attendance_policies', 'duplicate_punch_window_sec')) {
                $table->unsignedInteger('duplicate_punch_window_sec')->default(60)->after('punch_pairing_mode');
            }
            if (!Schema::hasColumn('attendance_policies', 'missing_clock_out_grace_hours')) {
                $table->unsignedTinyInteger('missing_clock_out_grace_hours')->default(4)->after('duplicate_punch_window_sec');
            }
            if (!Schema::hasColumn('attendance_policies', 'auto_detect_shift')) {
                $table->boolean('auto_detect_shift')->default(false)->after('missing_clock_out_grace_hours');
            }
        });

        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'is_missing_clock_out')) {
                $table->boolean('is_missing_clock_out')->default(false)->after('is_early_departure');
            }
            if (!Schema::hasColumn('attendance_records', 'late_minutes')) {
                $table->unsignedInteger('late_minutes')->default(0)->after('is_late');
            }
            if (!Schema::hasColumn('attendance_records', 'early_departure_minutes')) {
                $table->unsignedInteger('early_departure_minutes')->default(0)->after('is_early_departure');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            foreach (['is_missing_clock_out', 'late_minutes', 'early_departure_minutes'] as $col) {
                if (Schema::hasColumn('attendance_records', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('attendance_policies', function (Blueprint $table) {
            foreach (['punch_pairing_mode', 'duplicate_punch_window_sec', 'missing_clock_out_grace_hours', 'auto_detect_shift'] as $col) {
                if (Schema::hasColumn('attendance_policies', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
