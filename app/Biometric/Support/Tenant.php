<?php

namespace App\Biometric\Support;

use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the tenant (company user id) that owns biometric data.
 *
 * The HRMS stores ownership in `created_by`, which may be any user inside the
 * company hierarchy. Biometric tables additionally store `company_id` so that
 * uniqueness (serial number, identifier) and device-to-tenant resolution are
 * unambiguous.
 */
class Tenant
{
    public static function companyId(?User $user = null): ?int
    {
        $user = $user ?: Auth::user();
        if (!$user) {
            return null;
        }

        if ($user->type === 'company' || $user->type === 'superadmin') {
            return (int) $user->id;
        }

        $companyId = getCompanyId($user->id);

        return $companyId ? (int) $companyId : (int) ($user->created_by ?: $user->id);
    }
}
