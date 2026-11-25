<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('biometric_sync_runs')->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('type', 30);                   // vendor-neutral CommandType
            $table->json('payload')->nullable();          // vendor-neutral payload
            $table->text('wire_payload')->nullable();     // driver-encoded payload, filled when sent
            $table->string('status', 20)->default('queued');
            $table->unsignedSmallInteger('priority')->default(100); // lower = sooner
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('return_code', 50)->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'status', 'priority', 'id'], 'bio_commands_dispatch_index');
            $table->index(['sync_run_id', 'status'], 'bio_commands_run_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_commands');
    }
};
