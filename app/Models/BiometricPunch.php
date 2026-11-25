<?php

namespace App\Models;

use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\PunchSource;
use App\Biometric\Enums\PunchType;
use App\Biometric\Enums\VerificationMethod;
use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

class BiometricPunch extends BaseModel
{
    protected $table = 'biometric_punches';

    protected $fillable = [
        'company_id',
        'device_id',
        'employee_id',
        'user_id',
        'device_user_id',
        'punched_at_utc',
        'punched_at_local',
        'device_timezone',
        'punch_type',
        'verification_method',
        'original_device_log_id',
        'idempotency_key',
        'dedupe_hash',
        'source',
        'raw_metadata',
        'synced_at',
        'sync_run_id',
        'attendance_date',
        'attendance_record_id',
        'processing_status',
        'processing_note',
        'processed_at',
    ];

    protected $casts = [
        'punched_at_utc' => 'datetime',
        'punched_at_local' => 'datetime',
        'synced_at' => 'datetime',
        'attendance_date' => 'date',
        'processed_at' => 'datetime',
        'punch_type' => PunchType::class,
        'verification_method' => VerificationMethod::class,
        'source' => PunchSource::class,
        'processing_status' => PunchProcessingStatus::class,
        'raw_metadata' => 'array',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class, 'attendance_record_id');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(BiometricSyncRun::class, 'sync_run_id');
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

    public static function computeDedupeHash(int $companyId, int $deviceId, string $deviceUserId, string $punchedAtUtc): string
    {
        return hash('sha256', implode('|', [$companyId, $deviceId, trim($deviceUserId), trim($punchedAtUtc)]));
    }
}
