<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricDeviceUser extends Model
{
    protected $table = 'biometric_device_users';

    protected $fillable = [
        'company_id',
        'device_id',
        'pin',
        'name',
        'card',
        'privilege',
        'employee_id',
        'source',
        'last_reported_at',
    ];

    protected $casts = [
        'privilege' => 'integer',
        'last_reported_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
