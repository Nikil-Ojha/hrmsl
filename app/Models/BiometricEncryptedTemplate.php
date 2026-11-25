<?php

namespace App\Models;

use App\Biometric\Enums\BiometricModality;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Isolated encrypted biometric template vault used ONLY when cross-device
 * template transfer is explicitly enabled for supported hardware.
 *
 * SECURITY:
 *  - `template_data` is encrypted at rest and NEVER serialized (`$hidden` + `$visible`).
 *  - Never attach this model to ordinary Employee APIs, Inertia props, or logs.
 *  - Raw fingerprint/facial images are strictly rejected by BiometricTemplateVault.
 */
class BiometricEncryptedTemplate extends Model
{
    protected $table = 'biometric_encrypted_templates';

    protected $fillable = [
        'company_id',
        'employee_id',
        'source_device_id',
        'modality',
        'slot_index',
        'template_format',
        'template_hash',
        'template_data',
        'version',
        'created_by',
        'last_transferred_at',
    ];

    protected $hidden = [
        'template_data',
    ];

    protected $visible = [
        'id',
        'modality',
        'slot_index',
        'template_format',
        'template_hash',
        'version',
        'last_transferred_at',
        'created_at',
    ];

    protected $casts = [
        'modality' => BiometricModality::class,
        'slot_index' => 'integer',
        'version' => 'integer',
        'template_data' => 'encrypted',
        'last_transferred_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'source_device_id');
    }
}
