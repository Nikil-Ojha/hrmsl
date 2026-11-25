<?php

namespace App\Models;

use App\Biometric\Enums\CommandStatus;
use App\Biometric\Enums\CommandType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BiometricCommand extends Model
{
    protected $table = 'biometric_commands';

    protected $fillable = [
        'company_id',
        'device_id',
        'agent_id',
        'sync_run_id',
        'employee_id',
        'type',
        'payload',
        'wire_payload',
        'status',
        'priority',
        'attempts',
        'sent_at',
        'acknowledged_at',
        'expires_at',
        'return_code',
        'result',
        'error',
        'created_by',
    ];

    protected $casts = [
        'type' => CommandType::class,
        'status' => CommandStatus::class,
        'payload' => 'array',
        'result' => 'array',
        'priority' => 'integer',
        'attempts' => 'integer',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'device_id');
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(BiometricAgent::class, 'agent_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BiometricDeviceCommandLog::class, 'command_id')->orderBy('occurred_at');
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(BiometricSyncRun::class, 'sync_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', CommandStatus::pendingValues());
    }

    /**
     * ID used on the wire. Offset so it never collides with legacy command IDs
     * that are still served through the same device endpoints.
     */
    public function wireId(): int
    {
        return (int) config('biometric.commands.wire_id_offset', 900000000) + (int) $this->id;
    }

    public static function idFromWireId(int|string $wireId): ?int
    {
        $wireId = (int) $wireId;
        $offset = (int) config('biometric.commands.wire_id_offset', 900000000);

        return $wireId > $offset ? $wireId - $offset : null;
    }
}
