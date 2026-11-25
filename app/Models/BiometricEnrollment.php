<?php

namespace App\Models;

use App\Biometric\Enums\EnrollmentStatus;
use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class BiometricEnrollment extends BaseModel
{
    protected $table = 'biometric_enrollments';

    protected $fillable = [
        'company_id',
        'employee_id',
        'device_id',
        'device_user_id',
        'enrollment_reference',
        'enrollment_status',
        'enrolled_modalities',
        'fingerprint_count',
        'face_enrolled',
        're_enrollment_count',
        'source_device_id',
        'last_command_id',
        'enrolled_by',
        'enrolled_at',
        'last_synced_at',
        'last_error',
    ];

    protected $casts = [
        'enrollment_status' => EnrollmentStatus::class,
        'enrolled_modalities' => 'array',
        'fingerprint_count' => 'integer',
        'face_enrolled' => 'boolean',
        're_enrollment_count' => 'integer',
        'enrolled_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'source_device_id');
    }

    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    public function lastCommand(): BelongsTo
    {
        return $this->belongsTo(BiometricCommand::class, 'last_command_id');
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(BiometricEnrollmentCredential::class, 'enrollment_id')
            ->orderBy('modality')
            ->orderBy('slot_index');
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

    /**
     * Recompute modality summary counters from child credential slots.
     */
    public function recomputeSummary(): void
    {
        $active = $this->credentials()
            ->whereIn('status', [EnrollmentStatus::Enrolled->value])
            ->get();

        $modalities = $active->pluck('modality')->map(fn ($m) => is_object($m) ? $m->value : (string) $m)->unique()->values()->all();
        $fpCount = $active->filter(fn ($c) => (is_object($c->modality) ? $c->modality->value : $c->modality) === 'fingerprint')->count();
        $hasFace = in_array('face', $modalities, true);

        $this->forceFill([
            'enrolled_modalities' => $modalities,
            'fingerprint_count' => $fpCount,
            'face_enrolled' => $hasFace,
        ])->saveQuietly();
    }
}
