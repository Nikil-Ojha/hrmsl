<?php

namespace App\Policies;

use App\Biometric\Support\Tenant;
use App\Models\BiometricAgent;
use App\Models\User;

/**
 * Granular authorization policy for Local Device Communication Agents.
 */
class BiometricAgentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('manage-biometric-agents')
            || $user->can('view-biometric-agents')
            || $user->can('manage-biometric-settings');
    }

    public function view(User $user, BiometricAgent $agent): bool
    {
        return $this->viewAny($user) && $this->sameTenant($user, $agent);
    }

    public function create(User $user): bool
    {
        return $user->can('create-biometric-agents') || $user->can('manage-biometric-settings');
    }

    public function update(User $user, BiometricAgent $agent): bool
    {
        return ($user->can('edit-biometric-agents') || $user->can('manage-biometric-settings'))
            && $this->sameTenant($user, $agent);
    }

    public function delete(User $user, BiometricAgent $agent): bool
    {
        return ($user->can('delete-biometric-agents') || $user->can('manage-biometric-settings'))
            && $this->sameTenant($user, $agent);
    }

    public function manageCredentials(User $user, BiometricAgent $agent): bool
    {
        return $this->update($user, $agent);
    }

    private function sameTenant(User $user, BiometricAgent $agent): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        return (int) $agent->company_id === (int) Tenant::companyId($user);
    }
}
