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
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                if (!Schema::hasColumn('employees', 'is_biometric_enrolled')) {
                    $table->boolean('is_biometric_enrolled')->default(false)->after('biometric_emp_id');
                }
                if (!Schema::hasColumn('employees', 'biometric_enrollment_status')) {
                    $table->string('biometric_enrollment_status')->default('not_enrolled')->after('is_biometric_enrolled');
                }
                if (!Schema::hasColumn('employees', 'biometric_device_sn')) {
                    $table->string('biometric_device_sn')->nullable()->after('biometric_enrollment_status');
                }
                if (!Schema::hasColumn('employees', 'biometric_enrolled_at')) {
                    $table->timestamp('biometric_enrolled_at')->nullable()->after('biometric_device_sn');
                }
            });
        }

        if (!Schema::hasTable('biometric_device_commands')) {
            Schema::create('biometric_device_commands', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('employee_id')->nullable()->index();
                $table->string('employee_code')->index();
                $table->string('employee_name');
                $table->string('device_sn')->default('RSS20220619704')->index();
                $table->string('command_type')->default('SET_USER');
                $table->text('adms_command');
                $table->json('fkweb_payload')->nullable();
                $table->string('status')->default('pending')->index(); // pending, sent, acknowledged, failed
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('acknowledged_at')->nullable();
                $table->string('return_code')->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('biometric_device_commands');

        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                $cols = ['is_biometric_enrolled', 'biometric_enrollment_status', 'biometric_device_sn', 'biometric_enrolled_at'];
                foreach ($cols as $col) {
                    if (Schema::hasColumn('employees', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
