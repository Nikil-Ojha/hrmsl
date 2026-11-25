<?php

namespace App\Models;

use App\Biometric\Enums\EventLevel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricDeviceEvent extends Model
{
    protected $table = 'biometric_device_events';

    protected $fillable = [
        'company_id',
        'device_id',
        'level',
        'type',
        'message',
        'context',
        'sync_run_id',
        'occurred_at',
    ];

    protected $casts = [
        'level' => EventLevel::class,
        'context' => 'array',
        'occurred_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(BiometricSyncRun::class, 'sync_run_id');
    }
}
