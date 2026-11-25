<?php

namespace App\Models;

use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Normalized employee <-> biometric device user ID mapping.
 * Supports both device-specific mappings (`device_id` set) and company-wide
 * default mappings (`device_id` null) without duplicating the `employees` table.
 */
class BiometricEmployeeMapping extends Model
{
    protected $table = 'biometric_employee_mappings';

    protected $fillable = [
        'company_id',
        'employee_id',
        'device_id',
        'device_user_id',
        'card_number',
        'privilege',
        'is_active',
        'source',
        'synced_at',
        'created_by',
    ];

    protected $casts = [
        'privilege' => 'integer',
        'is_active' => 'boolean',
        'synced_at' => 'datetime',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user = $user ?: Auth::user();
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }
        if ($user->hasRole('superadmin')) {
            return $query;
        }

        return $query->where('company_id', Tenant::companyId($user));
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
