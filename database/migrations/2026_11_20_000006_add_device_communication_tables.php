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
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->json('device_info')->nullable()->after('settings');
            $table->timestamp('device_info_updated_at')->nullable()->after('device_info');
        });

        Schema::create('biometric_device_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('device_id')->constrained('biometric_devices')->cascadeOnDelete();
            $table->string('pin', 50);
            $table->string('name', 100)->nullable();
            $table->string('card', 50)->nullable();
            $table->unsignedTinyInteger('privilege')->default(0);
            $table->foreignId('employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('source', 20)->default('device_report'); // device_report, sync
            $table->timestamp('last_reported_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'pin'], 'bio_device_users_device_pin_unique');
            $table->index(['company_id', 'employee_id'], 'bio_device_users_employee_index');
        });

        Permission::updateOrCreate(
            ['name' => 'control-biometric-devices', 'guard_name' => 'web'],
            ['module' => 'biometric_devices', 'label' => 'Control Biometric Devices', 'description' => 'Can restart devices, clear device logs and remove users from devices']
        );
        foreach (['superadmin', 'company'] as $roleName) {
            Role::where('name', $roleName)->where('guard_name', 'web')->first()?->givePermissionTo('control-biometric-devices');
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'control-biometric-devices')->get()->each(function ($p) {
            $p->roles()->detach();
            $p->delete();
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Schema::dropIfExists('biometric_device_users');
        Schema::table('biometric_devices', function (Blueprint $table) {
            $table->dropColumn(['device_info', 'device_info_updated_at']);
        });
    }
};
