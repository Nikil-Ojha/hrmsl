<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * Granular biometric permissions introduced in Phase 11 (complements the
     * existing view/create/edit/delete/sync/enroll permissions).
     */
    private array $permissions = [
        [
            'name' => 'view-biometric-logs',
            'module' => 'biometric_attendance',
            'label' => 'View Biometric Logs',
            'description' => 'Can view raw biometric attendance logs, sync history, and biometric audit trails',
        ],
        [
            'name' => 'manage-biometric-settings',
            'module' => 'biometric_devices',
            'label' => 'Manage Biometric Settings',
            'description' => 'Can manage biometric device API keys, agent credentials, and biometric processing settings',
        ],
        [
            'name' => 'execute-biometric-commands',
            'module' => 'biometric_devices',
            'label' => 'Execute Device Commands',
            'description' => 'Can execute hardware commands such as restart, clear logs, remove users, and cancel queued commands',
        ],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('biometric_audit_logs')) {
            Schema::create('biometric_audit_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('actor_name', 150)->nullable();
                $table->string('actor_type', 30)->default('user'); // user, agent, system, device
                $table->string('category', 40); // device, enrollment, sync, command, attendance_correction, settings, agent
                $table->string('action', 80);
                $table->string('target_type', 80)->nullable();
                $table->unsignedBigInteger('target_id')->nullable();
                $table->string('target_label', 200)->nullable();
                $table->foreignId('device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
                $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
                $table->string('description', 500);
                $table->json('before_state')->nullable();
                $table->json('after_state')->nullable();
                $table->json('metadata')->nullable();
                $table->string('ip_address', 45)->nullable();
                $table->string('user_agent', 255)->nullable();
                $table->timestamp('occurred_at')->useCurrent();

                $table->index(['company_id', 'occurred_at'], 'bio_audit_company_time_idx');
                $table->index(['company_id', 'category', 'occurred_at'], 'bio_audit_company_cat_time_idx');
                $table->index(['company_id', 'action', 'occurred_at'], 'bio_audit_company_act_time_idx');
                $table->index(['company_id', 'actor_id', 'occurred_at'], 'bio_audit_company_actor_time_idx');
                $table->index(['device_id', 'occurred_at'], 'bio_audit_device_time_idx');
                $table->index(['employee_id', 'occurred_at'], 'bio_audit_employee_time_idx');
            });
        }

        foreach ($this->permissions as $perm) {
            Permission::updateOrCreate(
                ['name' => $perm['name'], 'guard_name' => 'web'],
                [
                    'module' => $perm['module'],
                    'label' => $perm['label'],
                    'description' => $perm['description'],
                ]
            );
        }

        $names = array_column($this->permissions, 'name');
        foreach (['superadmin', 'company'] as $roleName) {
            $role = Role::where('name', $roleName)->where('guard_name', 'web')->first();
            $role?->givePermissionTo($names);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_audit_logs');

        Permission::whereIn('name', array_column($this->permissions, 'name'))->get()->each(function ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
