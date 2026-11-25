<?php

namespace App\Models;

use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Immutable, tenant-scoped audit trail for administrative and operational
 * biometric & manual attendance actions.
 */
class BiometricAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'biometric_audit_logs';

    protected $fillable = [
        'company_id',
        'actor_id',
        'actor_name',
        'actor_type',
        'category',
        'action',
        'target_type',
        'target_id',
        'target_label',
        'device_id',
        'employee_id',
        'description',
        'before_state',
        'after_state',
        'metadata',
        'ip_address',
        'user_agent',
        'occurred_at',
    ];

    protected $casts = [
        'before_state' => 'array',
        'after_state' => 'array',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
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
}
