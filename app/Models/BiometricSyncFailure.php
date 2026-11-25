<?php

namespace App\Models;

use App\Biometric\Enums\PunchSource;
use App\Biometric\Enums\SyncFailureStatus;
use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class BiometricSyncFailure extends Model
{
    protected $table = 'biometric_sync_failures';

    protected $fillable = [
        'company_id',
        'device_id',
        'agent_id',
        'sync_run_id',
        'source',
        'idempotency_key',
        'device_user_id',
        'original_device_log_id',
        'punched_at_raw',
        'error_code',
        'error_message',
        'payload',
        'status',
        'retry_count',
        'next_retry_at',
        'last_attempted_at',
        'resolved_at',
        'resolved_punch_id',
    ];

    protected $casts = [
        'source' => PunchSource::class,
        'status' => SyncFailureStatus::class,
        'payload' => 'array',
        'retry_count' => 'integer',
        'next_retry_at' => 'datetime',
        'last_attempted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function resolvedPunch(): BelongsTo
    {
        return $this->belongsTo(BiometricPunch::class, 'resolved_punch_id');
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
