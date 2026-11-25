<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_device_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('level', 10)->default('info'); // info, warning, error
            $table->string('type', 50);                  // online, offline, connectivity_failed, command_failed, ...
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->foreignId('sync_run_id')->nullable()->constrained('biometric_sync_runs')->nullOnDelete();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();

            $table->index(['device_id', 'level', 'occurred_at'], 'bio_events_device_level_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_device_events');
    }
};
