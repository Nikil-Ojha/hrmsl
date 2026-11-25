<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BiometricIdempotencyKey extends Model
{
    protected $table = 'biometric_idempotency_keys';

    protected $fillable = [
        'scope',
        'idempotency_key',
        'request_hash',
        'response_status',
        'response_body',
        'expires_at',
    ];

    protected $casts = [
        'response_status' => 'integer',
        'response_body' => 'array',
        'expires_at' => 'datetime',
    ];
}
