<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\ProtocolAdapter;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Protocols\NativeAdapter;

/**
 * Our own branded devices, speaking the HRMS Native protocol (HTTPS + JSON).
 * We own the protocol specification, so every operation is supported.
 */
class OwnBiometricDeviceProvider extends AbstractPushProvider
{
    public static function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'native',
            label: 'HRMS Native Protocol (Own Brand)',
            description: 'Our branded biometric devices. Devices push punches over HTTPS and poll for commands using a per-device API key.',
            communication: ProviderDefinition::PUSH,
            manufacturers: ['Own brand'],
            defaultPort: 443,
            authTypes: [AuthType::ApiKey, AuthType::Hmac],
            operations: array_fill_keys(array_map(fn (Operation $o) => $o->value, Operation::cases()), SupportLevel::Supported),
        );
    }

    public function adapter(): ProtocolAdapter
    {
        return app(NativeAdapter::class);
    }

    public function setupHints(): array
    {
        $base = rtrim((string) config('app.url'), '/') . '/api/biometric/device/v1';

        return [
            ['label' => 'Base Push API URL', 'value' => $base],
            ['label' => 'Heartbeat Endpoint', 'value' => 'POST ' . $base . '/heartbeat'],
            ['label' => 'Punch Push Endpoint', 'value' => 'POST ' . $base . '/punches'],
            ['label' => 'Command Poll Endpoint', 'value' => 'GET ' . $base . '/commands'],
            ['label' => 'Device Identifier', 'value' => $this->device->device_identifier],
            ['label' => 'Serial Number', 'value' => $this->device->serial_number],
        ];
    }
}
