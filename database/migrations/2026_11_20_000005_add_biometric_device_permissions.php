<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Registers the Biometric Devices permissions on existing installations
 * (fresh installs get them from PermissionSeeder / RoleSeeder too).
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'manage-biometric-devices', 'label' => 'Manage Biometric Devices', 'description' => 'Can manage biometric devices'],
        ['name' => 'manage-any-biometric-devices', 'label' => 'Manage All Biometric Devices', 'description' => 'Manage Any Biometric Devices'],
        ['name' => 'manage-own-biometric-devices', 'label' => 'Manage Own Biometric Devices', 'description' => 'Manage Limited Biometric Devices that is created by own'],
        ['name' => 'view-biometric-devices', 'label' => 'View Biometric Devices', 'description' => 'View Biometric Devices'],
        ['name' => 'create-biometric-devices', 'label' => 'Create Biometric Devices', 'description' => 'Can create biometric devices'],
        ['name' => 'edit-biometric-devices', 'label' => 'Edit Biometric Devices', 'description' => 'Can edit biometric devices'],
        ['name' => 'delete-biometric-devices', 'label' => 'Delete Biometric Devices', 'description' => 'Can delete biometric devices'],
        ['name' => 'sync-biometric-devices', 'label' => 'Sync Biometric Devices', 'description' => 'Can check connectivity, sync time, sync employees and fetch logs'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::updateOrCreate(
                ['name' => $permission['name'], 'guard_name' => 'web'],
                ['module' => 'biometric_devices', 'label' => $permission['label'], 'description' => $permission['description']]
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
        Permission::whereIn('name', array_column($this->permissions, 'name'))->get()->each(function ($permission) {
            $permission->roles()->detach();
            $permission->delete();
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
