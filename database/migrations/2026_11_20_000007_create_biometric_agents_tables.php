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
        ['name' => 'manage-biometric-agents', 'label' => 'Manage Biometric Agents', 'description' => 'Can manage local biometric communication agents'],
        ['name' => 'manage-any-biometric-agents', 'label' => 'Manage All Biometric Agents', 'description' => 'Manage any biometric agents in the company'],
        ['name' => 'manage-own-biometric-agents', 'label' => 'Manage Own Biometric Agents', 'description' => 'Manage biometric agents created by self'],
        ['name' => 'view-biometric-agents', 'label' => 'View Biometric Agents', 'description' => 'Can view biometric agent details and health'],
        ['name' => 'create-biometric-agents', 'label' => 'Create Biometric Agents', 'description' => 'Can register and provision biometric agents'],
        ['name' => 'edit-biometric-agents', 'label' => 'Edit Biometric Agents', 'description' => 'Can edit, revoke and rotate biometric agent credentials'],
        ['name' => 'delete-biometric-agents', 'label' => 'Delete Biometric Agents', 'description' => 'Can delete biometric agents'],
    ];

    public function up(): void
    {
        Schema::create('biometric_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();

            // Installation / device-group identity
            $table->string('name');
            $table->string('agent_identifier', 100);
            $table->string('status', 20)->default('pending');            // pending, active, revoked
            $table->string('connection_status', 20)->default('unknown'); // unknown, online, offline, error

            // One-time provisioning token (hashed, short-lived)
            $table->string('provisioning_token_hash')->nullable();
            $table->timestamp('provisioning_token_expires_at')->nullable();
            $table->timestamp('provisioned_at')->nullable();

            // Active installation credentials (token hashed, HMAC secret encrypted)
            $table->string('access_token_prefix', 16)->nullable()->index();
            $table->string('access_token_hash')->nullable();
            $table->text('hmac_secret')->nullable(); // encrypted
            $table->timestamp('credentials_rotated_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            // Runtime telemetry reported by the agent
            $table->string('agent_version', 50)->nullable();
            $table->string('os_info', 150)->nullable();
            $table->string('hostname', 150)->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('last_sync_at')->nullable();
            $table->unsignedInteger('local_queue_depth')->default(0);
            $table->unsignedInteger('local_error_count')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();

            // Agent configuration & reported capabilities
            $table->json('capabilities')->nullable();
            $table->json('settings')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'agent_identifier'], 'bio_agents_company_identifier_unique');
            $table->unique('agent_identifier', 'bio_agents_identifier_unique');
            $table->index(['company_id', 'status', 'connection_status'], 'bio_agents_company_state_index');
        });

        Schema::create('biometric_agent_discoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_id')->constrained('biometric_agents')->cascadeOnDelete();
            $table->string('fingerprint', 120); // stable identity e.g. ip:port or usb:<vid>:<pid>:<serial>
            $table->string('connection_type', 20)->default('lan');
            $table->string('ip_address', 45)->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('usb_path', 255)->nullable();
            $table->string('serial_number', 100)->nullable();
            $table->string('manufacturer_hint', 100)->nullable();
            $table->string('model_hint', 100)->nullable();
            $table->string('protocol_hint', 50)->nullable();
            $table->string('status', 20)->default('discovered'); // discovered, registered, ignored
            $table->foreignId('registered_device_id')->nullable()->constrained('biometric_devices')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique(['agent_id', 'fingerprint'], 'bio_agent_discoveries_unique');
            $table->index(['company_id', 'status'], 'bio_agent_discoveries_status_index');
        });

        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->foreignId('agent_id')->nullable()->after('department_id')->constrained('biometric_agents')->nullOnDelete();
            $table->index(['agent_id', 'is_active'], 'bio_devices_agent_active_index');
        });

        foreach ($this->permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['module' => 'biometric_agents', 'label' => $permission['label'], 'description' => $permission['description']]
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

        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropIndex('bio_devices_agent_active_index');
            $table->dropConstrainedForeignId('agent_id');
        });

        Schema::dropIfExists('biometric_agent_discoveries');
        Schema::dropIfExists('biometric_agents');
    }
};
