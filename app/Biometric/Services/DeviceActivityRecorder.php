<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\EventLevel;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\BiometricSyncRun;

/**
 * Single place that mutates a device's runtime state (heartbeat, online /
 * offline transitions, errors) and writes the corresponding event log.
 */
class DeviceActivityRecorder
{
    public function heartbeat(BiometricDevice $device, ?string $ip = null, array $meta = []): void
    {
        $wasOnline = $device->connection_status === ConnectionStatus::Online && $device->isHeartbeatFresh();

        $attributes = [
            'last_seen_at' => now(),
            'last_seen_ip' => $ip ?: $device->last_seen_ip,
            'connection_status' => ConnectionStatus::Online,
        ];

        if (!empty($meta['firmware_version'])) {
            $attributes['firmware_version'] = mb_substr((string) $meta['firmware_version'], 0, 100);
        }

        $device->forceFill($attributes)->saveQuietly();

        if (!$wasOnline) {
            $this->event($device, EventLevel::Info, 'online', 'Device came online.', ['ip' => $ip]);
        }
    }

    public function logsReceived(BiometricDevice $device, int $count): void
    {
        if ($count <= 0) {
            return;
        }

        $device->forceFill(['last_log_received_at' => now()])->saveQuietly();
    }

    public function markOffline(BiometricDevice $device, string $reason = 'No heartbeat received.'): void
    {
        if ($device->connection_status === ConnectionStatus::Offline) {
            return;
        }

        $device->forceFill(['connection_status' => ConnectionStatus::Offline])->saveQuietly();
        $this->event($device, EventLevel::Warning, 'offline', $reason, [
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ]);
    }

    public function error(BiometricDevice $device, string $type, string $message, array $context = [], ?BiometricSyncRun $run = null): void
    {
        $device->forceFill([
            'last_error' => mb_substr($message, 0, 2000),
            'last_error_at' => now(),
        ])->saveQuietly();

        $this->event($device, EventLevel::Error, $type, $message, $context, $run);
    }

    public function event(
        BiometricDevice $device,
        EventLevel $level,
        string $type,
        string $message,
        array $context = [],
        ?BiometricSyncRun $run = null
    ): BiometricDeviceEvent {
        return BiometricDeviceEvent::create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'level' => $level,
            'type' => $type,
            'message' => mb_substr($message, 0, 500),
            'context' => $context ?: null,
            'sync_run_id' => $run?->id,
            'occurred_at' => now(),
        ]);
    }
}
