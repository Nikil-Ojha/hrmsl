<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();

            // Tenant (company user id) and author
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            // Identity
            $table->string('name');
            $table->string('serial_number', 100);
            $table->string('device_identifier', 100);
            $table->string('manufacturer', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->string('device_type', 30);
            $table->string('firmware_version', 100)->nullable();

            // Placement
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();

            // Communication
            $table->string('protocol', 50);              // driver key, e.g. native, zk_adms
            $table->string('connection_type', 20);       // lan, wifi, usb, cloud
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('timezone', 64)->nullable();

            // Authentication
            $table->string('auth_type', 30)->default('none');
            $table->text('credentials')->nullable();      // encrypted JSON
            $table->string('api_key_prefix', 16)->nullable()->index();
            $table->string('api_key_hash')->nullable();

            // Runtime state
            $table->boolean('is_active')->default(true);
            $table->string('connection_status', 20)->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
            $table->timestamp('last_log_received_at')->nullable();
            $table->timestamp('last_connectivity_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'serial_number'], 'bio_devices_company_serial_unique');
            $table->unique(['company_id', 'device_identifier'], 'bio_devices_company_identifier_unique');
            $table->index('serial_number', 'bio_devices_serial_index');
            $table->index(['company_id', 'is_active', 'connection_status'], 'bio_devices_company_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_devices');
    }
};
