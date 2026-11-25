<?php

namespace App\Models;

use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Metadata for one biometric credential slot on a device (e.g. Right Index
 * fingerprint, Left Thumb fingerprint, Face). Never stores raw images or
 * biometric template bytes.
 */
class BiometricEnrollmentCredential extends Model
{
    protected $table = 'biometric_enrollment_credentials';

    protected $fillable = [
        'company_id',
        'enrollment_id',
        'employee_id',
        'device_id',
        'modality',
        'slot_index',
        'slot_label',
        'status',
        'device_credential_ref',
        'quality_score',
        'enrolled_at',
        'last_synced_at',
    ];

    protected $casts = [
        'modality' => BiometricModality::class,
        'status' => EnrollmentStatus::class,
        'slot_index' => 'integer',
        'quality_score' => 'integer',
        'enrolled_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(BiometricEnrollment::class, 'enrollment_id');
    }
}
