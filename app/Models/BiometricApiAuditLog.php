<?php

namespace App\Models;

use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class BiometricApiAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'biometric_api_audit_logs';

    protected $fillable = [
        'company_id',
        'agent_id',
        'device_id',
        'agent_identifier_claimed',
        'method',
        'path',
        'route_name',
        'status_code',
        'outcome',
        'ip_address',
        'user_agent',
        'request_nonce',
        'idempotency_key',
        'latency_ms',
        'summary',
        'metadata',
        'created_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'latency_ms' => 'integer',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
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
