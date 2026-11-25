<?php

namespace App\Models;

use App\Biometric\Enums\AgentStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Support\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;

class BiometricAgent extends BaseModel
{
    protected $table = 'biometric_agents';

    protected $fillable = [
        'company_id',
        'created_by',
        'branch_id',
        'name',
        'agent_identifier',
        'status',
        'connection_status',
        'agent_version',
        'os_info',
        'hostname',
        'last_seen_ip',
        'last_seen_at',
        'last_sync_at',
        'local_queue_depth',
        'local_error_count',
        'last_error',
        'last_error_at',
        'capabilities',
        'settings',
        'notes',
    ];

    protected $hidden = [
        'provisioning_token_hash',
        'access_token_hash',
        'hmac_secret',
    ];

    protected $casts = [
        'status' => AgentStatus::class,
        'connection_status' => ConnectionStatus::class,
        'hmac_secret' => 'encrypted',
        'capabilities' => 'array',
        'settings' => 'array',
        'local_queue_depth' => 'integer',
        'local_error_count' => 'integer',
        'provisioning_token_expires_at' => 'datetime',
        'provisioned_at' => 'datetime',
        'credentials_rotated_at' => 'datetime',
        'revoked_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $appends = ['effective_connection_status', 'has_active_provisioning_token'];

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

    public function devices(): HasMany
    {
        return $this->hasMany(BiometricDevice::class, 'agent_id');
    }

    public function discoveries(): HasMany
    {
        return $this->hasMany(BiometricAgentDiscovery::class, 'agent_id');
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

        $query->where('company_id', Tenant::companyId($user));

        if ($user->hasRole('company') || $user->can('manage-any-biometric-agents')) {
            return $query;
        }

        if ($user->can('manage-own-biometric-agents')) {
            return $query->where('created_by', $user->id);
        }

        return $query->whereRaw('1 = 0');
    }

    public function isHeartbeatFresh(): bool
    {
        if (!$this->last_seen_at) {
            return false;
        }

        $threshold = (int) config('biometric.agent.online_threshold_seconds', 180);

        return $this->last_seen_at->gt(now()->subSeconds($threshold));
    }

    public function getEffectiveConnectionStatusAttribute(): string
    {
        if ($this->status === AgentStatus::Revoked) {
            return ConnectionStatus::Offline->value;
        }

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

    public function getHasActiveProvisioningTokenAttribute(): bool
    {
        return !empty($this->provisioning_token_hash)
            && $this->provisioning_token_expires_at !== null
            && $this->provisioning_token_expires_at->isFuture();
    }

    public function mergedSettings(): array
    {
        return array_merge([
            'heartbeat_interval_sec' => (int) config('biometric.agent.defaults.heartbeat_interval_sec', 60),
            'command_poll_interval_sec' => (int) config('biometric.agent.defaults.command_poll_interval_sec', 15),
            'log_poll_interval_sec' => (int) config('biometric.agent.defaults.log_poll_interval_sec', 60),
            'config_refresh_interval_sec' => (int) config('biometric.agent.defaults.config_refresh_interval_sec', 300),
            'log_batch_size' => (int) config('biometric.agent.defaults.log_batch_size', 200),
            'discovery_enabled' => false,
            'discovery_cidrs' => [],
            'discovery_ports' => [4370, 5005, 80, 443, 8080],
        ], $this->settings ?? []);
    }
}
