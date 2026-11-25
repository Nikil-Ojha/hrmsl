<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricAgentDiscovery extends Model
{
    protected $table = 'biometric_agent_discoveries';

    protected $fillable = [
        'company_id',
        'agent_id',
        'fingerprint',
        'connection_type',
        'ip_address',
        'port',
        'usb_path',
        'serial_number',
        'manufacturer_hint',
        'model_hint',
        'protocol_hint',
        'status',
        'registered_device_id',
        'metadata',
        'first_seen_at',
        'last_seen_at',
    ];

    protected $casts = [
        'port' => 'integer',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function registeredDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'registered_device_id');
    }
}
