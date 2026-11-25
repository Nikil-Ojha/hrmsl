<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('biometric_api_audit_logs')) {
            Schema::create('biometric_api_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->nullable()->index();
                $table->foreignId('agent_id')->nullable()->constrained('biometric_agents')->nullOnDelete();
                $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
                $table->string('agent_identifier_claimed', 100)->nullable();
                $table->string('method', 10);
                $table->string('path', 255);
                $table->string('route_name', 120)->nullable();
                $table->unsignedSmallInteger('status_code');
                $table->string('outcome', 40)->index(); // success, auth_failed, replay_blocked, https_rejected, forbidden_device, validation_failed, rate_limited, error
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->string('request_nonce', 128)->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->unsignedInteger('latency_ms')->default(0);
                $table->string('summary', 255)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent()->index();

                $table->index(['company_id', 'created_at'], 'bio_api_audit_company_created_idx');
                $table->index(['agent_id', 'created_at'], 'bio_api_audit_agent_created_idx');
                $table->index(['device_id', 'created_at'], 'bio_api_audit_device_created_idx');
                $table->index(['outcome', 'created_at'], 'bio_api_audit_outcome_created_idx');
            });
        }

        if (!Schema::hasTable('biometric_agent_nonces')) {
            Schema::create('biometric_agent_nonces', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agent_id')->constrained('biometric_agents')->cascadeOnDelete();
                $table->char('nonce_hash', 64);
                $table->timestamp('expires_at')->index();
                $table->timestamp('created_at')->useCurrent();

                $table->unique(['agent_id', 'nonce_hash'], 'bio_agent_nonces_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_agent_nonces');
        Schema::dropIfExists('biometric_api_audit_logs');
    }
};
