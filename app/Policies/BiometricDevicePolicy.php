<?php

namespace App\Policies;

use App\Biometric\Support\Tenant;
use App\Models\BiometricDevice;
use App\Models\User;

/**
 * Granular authorization policy for BiometricDevice enforcing tenant scoping
 * and all device-level Phase 11 permissions.
 */
class BiometricDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-biometric-devices') || $user->can('manage-biometric-devices');
    }

    public function view(User $user, BiometricDevice $device): bool
    {
        return $this->viewAny($user) && $this->sameTenant($user, $device);
    }

    public function create(User $user): bool
    {
        return $user->can('create-biometric-devices');
    }

    public function update(User $user, BiometricDevice $device): bool
    {
        return $user->can('edit-biometric-devices') && $this->sameTenant($user, $device);
    }

    public function delete(User $user, BiometricDevice $device): bool
    {
        return $user->can('delete-biometric-devices') && $this->sameTenant($user, $device);
    }

    public function sync(User $user, BiometricDevice $device): bool
    {
        return $user->can('sync-biometric-devices') && $this->sameTenant($user, $device);
    }

    public function executeCommands(User $user, BiometricDevice $device): bool
    {
        return ($user->can('execute-biometric-commands') || $user->can('control-biometric-devices'))
            && $this->sameTenant($user, $device);
    }

    public function manageSettings(User $user, BiometricDevice $device): bool
    {
        return ($user->can('manage-biometric-settings') || $user->can('edit-biometric-devices'))
            && $this->sameTenant($user, $device);
    }

    private function sameTenant(User $user, BiometricDevice $device): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        if ((int) $device->company_id !== (int) Tenant::companyId($user)) {
            return false;
        }

        if (
            !$user->hasRole('company')
            && $user->can('manage-own-biometric-devices')
            && !$user->can('manage-any-biometric-devices')
            && !$user->can('view-biometric-devices')
        ) {
            return (int) $device->created_by === (int) $user->id;
        }

        return true;
    }
}
