<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private array $permissions = [
        ['name' => 'manage-biometric-enrollments', 'label' => 'Manage Biometric Enrollments', 'description' => 'Can view and manage employee biometric enrollments'],
        ['name' => 'view-biometric-enrollments', 'label' => 'View Biometric Enrollments', 'description' => 'Can view employee biometric enrollment status and device mappings'],
        ['name' => 'enroll-biometric-employees', 'label' => 'Enroll Biometric Employees', 'description' => 'Can start enrollment and re-enroll employees on biometric devices'],
        ['name' => 'remove-biometric-enrollments', 'label' => 'Remove Biometric Enrollments', 'description' => 'Can remove biometric registrations from devices'],
        ['name' => 'sync-biometric-enrollments', 'label' => 'Sync Biometric Enrollments', 'description' => 'Can synchronize employee enrollments across multiple authorized devices'],
    ];

    public function up(): void
    {
        // 1. Authoritative Employee <-> Device biometric enrollment mapping
        Schema::create('biometric_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('device_user_id', 50);                   // PIN / User ID on the biometric terminal
            $table->string('enrollment_reference', 64)->unique();   // Unique HRMS enrollment reference (ENR-...)
            $table->string('enrollment_status', 30)->default('pending');
            $table->json('enrolled_modalities')->nullable();        // e.g. ["fingerprint", "face"]
            $table->unsignedTinyInteger('fingerprint_count')->default(0);
            $table->boolean('face_enrolled')->default(false);
            $table->unsignedInteger('re_enrollment_count')->default(0);
            $table->foreignId('source_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('last_command_id')->nullable()->constrained('biometric_commands')->nullOnDelete();
            $table->foreignId('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'device_id'], 'bio_enrollments_emp_device_unique');
            $table->index(['company_id', 'employee_id', 'enrollment_status'], 'bio_enrollments_company_emp_idx');
            $table->index(['device_id', 'device_user_id'], 'bio_enrollments_device_pin_idx');
        });

        // 2. Per-modality / per-finger slot metadata (supports multiple fingerprints per employee; NO images)
        Schema::create('biometric_enrollment_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('biometric_enrollments')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('modality', 20);                         // fingerprint, face, card, pin, palm, iris
            $table->unsignedTinyInteger('slot_index')->default(0);  // 0..9 for fingers; 0 for face/card/pin
            $table->string('slot_label', 50)->nullable();           // e.g. "Right Index", "Face"
            $table->string('status', 30)->default('pending');       // pending, awaiting_capture, enrolled, failed, removed
            $table->string('device_credential_ref', 120)->nullable(); // opaque hardware-side reference only
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique(['enrollment_id', 'modality', 'slot_index'], 'bio_enroll_creds_unique');
            $table->index(['company_id', 'employee_id', 'modality'], 'bio_enroll_creds_emp_mod_idx');
        });

        // 3. Strictly isolated encrypted template transfer vault (used ONLY when device transfer is enabled; NEVER raw images)
        Schema::create('biometric_encrypted_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('source_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->string('modality', 20);
            $table->unsignedTinyInteger('slot_index')->default(0);
            $table->string('template_format', 64);                  // vendor algorithm/version identifier
            $table->string('template_hash', 64)->index();           // SHA-256 of plaintext template (for audit/dedupe)
            $table->longText('template_data');                      // Laravel encrypted ciphertext (never exposed in APIs)
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_transferred_at')->nullable();
            $table->timestamps();

            $table->unique(['employee_id', 'modality', 'slot_index', 'template_format'], 'bio_enc_templates_unique');
        });

        foreach ($this->permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['module' => 'biometric_enrollments', 'label' => $permission['label'], 'description' => $permission['description']]
            );
        }

        $names = array_column($this->permissions, 'name');
        foreach (['superadmin', 'company'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_column($this->permissions, 'name'))->get()->each(function ($p) {
            $p->roles()->detach();
            $p->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::dropIfExists('biometric_encrypted_templates');
        Schema::dropIfExists('biometric_enrollment_credentials');
        Schema::dropIfExists('biometric_enrollments');
    }
};
