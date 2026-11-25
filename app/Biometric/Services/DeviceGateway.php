<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\EventLevel;
use App\Models\BiometricDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Entry point used by device-facing protocol endpoints to identify a
 * registered device and record its activity.
 */
class DeviceGateway
{
    public function __construct(
        private readonly DeviceActivityRecorder $activity,
        private readonly CommandQueue $commands,
    ) {
    }

    /**
     * Find the registered device for a serial number. Serial numbers are only
     * unique per company, so an ambiguous match is refused rather than guessed.
     */
    public function resolve(?string $serial): ?BiometricDevice
    {
        $serial = trim((string) $serial);
        if ($serial === '') {
            return null;
        }

        $devices = BiometricDevice::where('serial_number', $serial)->limit(2)->get();

        if ($devices->count() > 1) {
            Log::warning('[Biometric] Ambiguous device serial number across tenants; ignoring.', ['serial' => $serial]);

            return null;
        }

        return $devices->first();
    }

    /**
     * Record a device contact. Returns the device only if it may receive
     * commands through the given protocol.
     */
    public function touch(string $protocol, ?string $serial, ?string $ip, array $meta = []): ?BiometricDevice
    {
        $device = $this->resolve($serial);
        if (!$device) {
            return null;
        }

        $this->activity->heartbeat($device, $ip, $meta);

        if ($device->protocol !== $protocol) {
            $this->warnOnce($device, 'protocol_mismatch', sprintf(
                'Device connected using the "%s" protocol but is configured as "%s". Commands are paused until the configuration is corrected.',
                $protocol,
                $device->protocol
            ));

            return null;
        }

        return $device->is_active ? $device : null;
    }

    public function commands(): CommandQueue
    {
        return $this->commands;
    }

    public function activity(): DeviceActivityRecorder
    {
        return $this->activity;
    }

    private function warnOnce(BiometricDevice $device, string $type, string $message): void
    {
        if (Cache::add("biometric:warn:{$device->id}:{$type}", 1, now()->addHour())) {
            $this->activity->event($device, EventLevel::Warning, $type, $message);
        }
    }
}
