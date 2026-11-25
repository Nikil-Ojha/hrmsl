<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('action', 30);                 // connectivity, time_sync, employee_sync, log_fetch
            $table->string('trigger', 20)->default('manual'); // manual, scheduled, system
            $table->string('status', 20)->default('queued');
            $table->unsignedInteger('total_items')->default(0);
            $table->unsignedInteger('success_items')->default(0);
            $table->unsignedInteger('failed_items')->default(0);
            $table->json('parameters')->nullable();
            $table->json('result')->nullable();
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['device_id', 'action', 'status'], 'bio_sync_runs_device_action_index');
            $table->index(['device_id', 'created_at'], 'bio_sync_runs_device_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_sync_runs');
    }
};
