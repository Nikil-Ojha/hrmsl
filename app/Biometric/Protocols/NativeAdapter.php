<?php

namespace App\Biometric\Protocols;

use App\Biometric\Support\EncodedCommand;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;

/**
 * Codec for our own device protocol (HRMS Native, HTTPS + JSON). Because we
 * own the specification, commands are the neutral command envelope itself.
 * The device-facing endpoints are delivered with the protocol gateway.
 */
class NativeAdapter extends AbstractAdapter
{
    public const VERSION = '1.0';

    public function encode(BiometricCommand $command, BiometricDevice $device): EncodedCommand
    {
        $payload = $command->payload ?? [];

        if ($command->type->value === 'sync_time') {
            $now = $this->deviceNow($device, $payload['time'] ?? null);
            $payload = ['datetime' => $now->format('Y-m-d\TH:i:sP'), 'timezone' => $device->timezoneName()];
        }

        return new EncodedCommand(json_encode([
            'version' => self::VERSION,
            'id' => $command->wireId(),
            'type' => $command->type->value,
            'payload' => (object) $payload,
            'issued_at' => now()->toIso8601String(),
            'expires_at' => $command->expires_at?->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
