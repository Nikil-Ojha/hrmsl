<?php

namespace App\Policies;

use App\Biometric\Support\Tenant;
use App\Models\BiometricEnrollment;
use App\Models\User;

/**
 * Granular authorization policy for employee biometric enrollments.
 */
class BiometricEnrollmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view-biometric-enrollments')
            || $user->can('enroll-biometric-employees')
            || $user->can('manage-biometric-enrollments');
    }

    public function view(User $user, BiometricEnrollment $enrollment): bool
    {
        return $this->viewAny($user) && $this->sameTenant($user, $enrollment);
    }

    public function enroll(User $user): bool
    {
        return $user->can('enroll-biometric-employees');
    }

    public function sync(User $user): bool
    {
        return $user->can('sync-biometric-enrollments') || $user->can('sync-biometric-devices');
    }

    public function remove(User $user, BiometricEnrollment $enrollment): bool
    {
        return $user->can('remove-biometric-enrollments') && $this->sameTenant($user, $enrollment);
    }

    private function sameTenant(User $user, BiometricEnrollment $enrollment): bool
    {
        if ($user->hasRole('superadmin')) {
            return true;
        }

        return (int) $enrollment->company_id === (int) Tenant::companyId($user);
    }
}
