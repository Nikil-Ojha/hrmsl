<?php

namespace App\Models;

use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricSyncRun extends Model
{
    protected $table = 'biometric_sync_runs';

    protected $fillable = [
        'company_id',
        'device_id',
        'agent_id',
        'action',
        'trigger',
        'status',
        'total_items',
        'success_items',
        'failed_items',
        'parameters',
        'result',
        'message',
        'error',
        'started_at',
        'finished_at',
        'initiated_by',
    ];

    protected $casts = [
        'action' => SyncAction::class,
        'status' => SyncStatus::class,
        'parameters' => 'array',
        'result' => 'array',
        'total_items' => 'integer',
        'success_items' => 'integer',
        'failed_items' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(BiometricCommand::class, 'sync_run_id');
    }

    public function getDurationSecondsAttribute(): ?int
    {
        if (!$this->started_at || !$this->finished_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at);
    }
}
