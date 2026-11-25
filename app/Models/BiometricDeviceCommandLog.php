<?php

namespace App\Models;

use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Append-only execution and state-transition audit log for BiometricCommand.
 */
class BiometricDeviceCommandLog extends Model
{
    public $timestamps = false;

    protected $table = 'biometric_device_command_logs';

    protected $fillable = [
        'company_id',
        'command_id',
        'device_id',
        'agent_id',
        'actor_id',
        'event',
        'from_status',
        'to_status',
        'attempt_number',
        'return_code',
        'message',
        'response_payload',
        'latency_ms',
        'occurred_at',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'latency_ms' => 'integer',
        'response_payload' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function command(): BelongsTo
    {
        return $this->belongsTo(BiometricCommand::class, 'command_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
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
