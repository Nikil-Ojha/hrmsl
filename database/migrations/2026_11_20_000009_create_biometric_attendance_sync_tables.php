<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Canonical vendor-independent biometric attendance events
        Schema::create('biometric_punches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // employees.user_id
            $table->string('device_user_id', 50);

            // Timestamps with explicit timezone preservation
            $table->dateTime('punched_at_utc');
            $table->dateTime('punched_at_local');
            $table->string('device_timezone', 64)->default('UTC');

            // Normalized attributes
            $table->string('punch_type', 20)->default('unknown');           // in, out, unknown
            $table->string('verification_method', 20)->default('unknown');  // fingerprint, face, pin, card, ...
            $table->string('original_device_log_id', 100)->nullable();
            $table->string('idempotency_key', 100)->nullable();
            $table->string('dedupe_hash', 64);                              // SHA-256(company|device|user|utc_ts)
            $table->string('source', 30)->default('local_agent');           // local_agent, direct_device, adms_push, ...
            $table->json('raw_metadata')->nullable();

            // Synchronization & attendance processing linkage
            $table->timestamp('synced_at')->useCurrent();
            $table->foreignId('sync_run_id')->nullable()->constrained('biometric_sync_runs')->nullOnDelete();
            $table->date('attendance_date')->nullable();
            $table->foreignId('attendance_record_id')->nullable()->constrained('attendance_records')->nullOnDelete();
            $table->string('processing_status', 30)->default('pending');
            $table->string('processing_note', 500)->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Multi-layer duplicate punch prevention at the database level
            $table->unique('dedupe_hash', 'bio_punches_dedupe_hash_unique');
            $table->unique(['device_id', 'original_device_log_id'], 'bio_punches_device_orig_log_unique');
            $table->unique(['device_id', 'idempotency_key'], 'bio_punches_device_idem_unique');

            $table->index(['company_id', 'employee_id', 'attendance_date'], 'bio_punches_emp_att_date_idx');
            $table->index(['company_id', 'device_id', 'punched_at_utc'], 'bio_punches_device_time_idx');
            $table->index(['company_id', 'processing_status'], 'bio_punches_status_idx');
        });

        // 2. Failed synchronization & processing logs (with automatic & manual retry)
        Schema::create('biometric_sync_failures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('biometric_agents')->nullOnDelete();
            $table->foreignId('sync_run_id')->nullable()->constrained('biometric_sync_runs')->nullOnDelete();
            $table->string('source', 30)->default('local_agent');
            $table->string('idempotency_key', 100)->nullable()->index();
            $table->string('device_user_id', 50)->nullable();
            $table->string('original_device_log_id', 100)->nullable();
            $table->string('punched_at_raw', 64)->nullable();
            $table->string('error_code', 50);
            $table->text('error_message');
            $table->json('payload');
            $table->string('status', 20)->default('pending_retry'); // pending_retry, resolved, abandoned
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('next_retry_at')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_punch_id')->nullable()->constrained('biometric_punches')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'status', 'next_retry_at'], 'bio_sync_failures_retry_idx');
        });

        // 3. Batch-level API idempotency store (safe HTTP retries for identical batches)
        Schema::create('biometric_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 100);                // e.g. agent:12 or device:45
            $table->string('idempotency_key', 120);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status')->default(200);
            $table->json('response_body');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique(['scope', 'idempotency_key'], 'bio_idempotency_scope_key_unique');
        });

        // 4. Additive columns on existing attendance_records (preserves all existing logic & columns!)
        Schema::table('attendance_records', function (Blueprint $table) {
            if (!Schema::hasColumn('attendance_records', 'source')) {
                $table->string('source', 30)->default('manual')->after('status');
            }
            if (!Schema::hasColumn('attendance_records', 'is_manual_override')) {
                $table->boolean('is_manual_override')->default(false)->after('source');
            }
            if (!Schema::hasColumn('attendance_records', 'clock_in_at')) {
                $table->dateTime('clock_in_at')->nullable()->after('clock_in');
            }
            if (!Schema::hasColumn('attendance_records', 'clock_out_at')) {
                $table->dateTime('clock_out_at')->nullable()->after('clock_out');
            }
            if (!Schema::hasColumn('attendance_records', 'first_punch_id')) {
                $table->unsignedBigInteger('first_punch_id')->nullable()->after('biometric_id');
            }
            if (!Schema::hasColumn('attendance_records', 'last_punch_id')) {
                $table->unsignedBigInteger('last_punch_id')->nullable()->after('first_punch_id');
            }
            if (!Schema::hasColumn('attendance_records', 'punch_count')) {
                $table->unsignedSmallInteger('punch_count')->default(0)->after('last_punch_id');
            }
        });

        // Permission for reprocessing/retrying biometric attendance sync
        Permission::updateOrCreate(
            ['name' => 'reprocess-biometric-punches', 'guard_name' => 'web'],
            ['module' => 'biometric_attendance', 'label' => 'Reprocess Biometric Punches', 'description' => 'Can retry failed biometric sync logs and reprocess attendance calculations']
        );
        foreach (['superadmin', 'company'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo('reprocess-biometric-punches');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'reprocess-biometric-punches')->get()->each(function ($p) {
            $p->roles()->detach();
            $p->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::table('attendance_records', function (Blueprint $table) {
            foreach (['source', 'is_manual_override', 'clock_in_at', 'clock_out_at', 'first_punch_id', 'last_punch_id', 'punch_count'] as $col) {
                if (Schema::hasColumn('attendance_records', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::dropIfExists('biometric_idempotency_keys');
        Schema::dropIfExists('biometric_sync_failures');
        Schema::dropIfExists('biometric_punches');
    }
};
