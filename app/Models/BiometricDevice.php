<?php

namespace App\Models;

use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\ConnectionType;
use App\Biometric\Enums\DeviceType;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class BiometricDevice extends BaseModel
{
    protected $table = 'biometric_devices';

    protected $fillable = [
        'company_id',
        'created_by',
        'name',
        'serial_number',
        'device_identifier',
        'manufacturer',
        'model',
        'device_type',
        'firmware_version',
        'branch_id',
        'department_id',
        'agent_id',
        'protocol',
        'connection_type',
        'ip_address',
        'port',
        'timezone',
        'auth_type',
        'credentials',
        'is_active',
        'connection_status',
        'last_seen_at',
        'last_seen_ip',
        'last_sync_at',
        'last_successful_sync_at',
        'last_log_received_at',
        'last_connectivity_check_at',
        'last_error',
        'last_error_at',
        'settings',
        'device_info',
        'device_info_updated_at',
        'notes',
    ];

    /**
     * Secrets never leave the server in serialized form.
     */
    protected $hidden = [
        'credentials',
        'api_key_hash',
    ];

    protected $casts = [
        'device_type' => DeviceType::class,
        'connection_type' => ConnectionType::class,
        'connection_status' => ConnectionStatus::class,
        'auth_type' => AuthType::class,
        'credentials' => 'encrypted:array',
        'settings' => 'array',
        'device_info' => 'array',
        'device_info_updated_at' => 'datetime',
        'is_active' => 'boolean',
        'port' => 'integer',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_successful_sync_at' => 'datetime',
        'last_log_received_at' => 'datetime',
        'last_connectivity_check_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $appends = ['effective_connection_status', 'has_api_key'];

    /* ----------------------------------------------------------------- */
    /* Relations                                                          */
    /* ----------------------------------------------------------------- */

    public function company(): BelongsTo
    {
        return $this->belongsTo(User::class, 'company_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(BiometricSyncRun::class, 'device_id');
    }

    public function commands(): HasMany
    {
        return $this->hasMany(BiometricCommand::class, 'device_id');
    }

    public function deviceUsers(): HasMany
    {
        return $this->hasMany(BiometricDeviceUser::class, 'device_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class, 'device_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(BiometricDeviceEvent::class, 'device_id');
    }

    /* ----------------------------------------------------------------- */
    /* Scopes                                                             */
    /* ----------------------------------------------------------------- */

    /**
     * Restrict to devices the current user may see, following the HRMS
     * manage-any / manage-own permission convention.
     */
    public function scopeVisibleTo(Builder $query, ?User $user = null): Builder
    {
        $user = $user ?: Auth::user();
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasRole('superadmin')) {
            return $query;
        }

        $companyId = Tenant::companyId($user);
        $query->where('company_id', $companyId);

        if (
            $user->hasRole('company')
            || $user->can('manage-any-biometric-devices')
            || $user->can('view-biometric-devices')
            || $user->can('edit-biometric-devices')
            || $user->can('delete-biometric-devices')
            || $user->can('sync-biometric-devices')
            || $user->can('execute-biometric-commands')
            || $user->can('control-biometric-devices')
            || $user->can('manage-biometric-settings')
            || $user->can('enroll-biometric-employees')
            || $user->can('view-biometric-logs')
        ) {
            return $query;
        }

        if ($user->can('manage-own-biometric-devices')) {
            return $query->where('created_by', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /* ----------------------------------------------------------------- */
    /* Accessors / helpers                                               */
    /* ----------------------------------------------------------------- */

    /**
     * Connection status that is correct even if the monitor job has not run:
     * a stored "online" becomes "offline" once the heartbeat goes stale.
     */
    public function getEffectiveConnectionStatusAttribute(): string
    {
        $stored = $this->connection_status instanceof ConnectionStatus
            ? $this->connection_status
            : ConnectionStatus::tryFrom((string) $this->connection_status) ?? ConnectionStatus::Unknown;

        if ($stored === ConnectionStatus::Online && !$this->isHeartbeatFresh()) {
            return ConnectionStatus::Offline->value;
        }

        if ($stored === ConnectionStatus::Unknown && $this->isHeartbeatFresh()) {
            return ConnectionStatus::Online->value;
        }

        return $stored->value;
    }

    public function getHasApiKeyAttribute(): bool
    {
        return !empty($this->api_key_hash);
    }

    public function isHeartbeatFresh(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        $threshold = (int) config('biometric.connectivity.online_threshold_seconds', 300);

        return $this->last_seen_at->gt(now()->subSeconds($threshold));
    }

    public function isNetworked(): bool
    {
        return $this->connection_type instanceof ConnectionType
            ? $this->connection_type->isNetworked()
            : $this->connection_type !== ConnectionType::Usb->value;
    }

    public function isManagedByAgent(): bool
    {
        return !empty($this->agent_id);
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: (settings($this->company_id)['defaultTimezone'] ?? config('app.timezone', 'UTC'));
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials ?? [], $key, $default);
    }

    public function lastSuccessfulRun(SyncAction $action): ?BiometricSyncRun
    {
        return $this->syncRuns()
            ->where('action', $action->value)
            ->whereIn('status', ['success', 'partial'])
            ->latest('finished_at')
            ->first();
    }
}
