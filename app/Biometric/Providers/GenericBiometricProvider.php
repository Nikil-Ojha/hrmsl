<?php

namespace App\Biometric\Providers;

use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;

/**
 * Any device or middleware that pushes JSON punches to the REST API.
 * One-way: the server cannot send anything back, so only status,
 * connectivity and cached information are available.
 */
class GenericBiometricProvider extends AbstractProvider
{
    public static function definition(): ProviderDefinition
    {
        $s = SupportLevel::Supported;

        return new ProviderDefinition(
            key: 'generic_http',
            label: 'Generic HTTP/JSON Push (one-way)',
            description: 'Any device or middleware that pushes JSON attendance logs to the REST API. Device commands are not possible.',
            communication: ProviderDefinition::PUSH,
            authTypes: [AuthType::ApiKey, AuthType::Token, AuthType::Basic, AuthType::None],
            credentialFields: [
                ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'secret' => false, 'required' => false],
                ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'secret' => true, 'required' => false],
                ['name' => 'token', 'label' => 'Bearer Token', 'type' => 'password', 'secret' => true, 'required' => false],
            ],
            operations: [
                Operation::Connect->value => $s,
                Operation::TestConnection->value => $s,
                Operation::DeviceInformation->value => $s,
                Operation::DeviceStatus->value => $s,
            ],
        );
    }

    public function setupHints(): array
    {
        return [
            ['label' => 'Push URL', 'value' => rtrim((string) config('app.url'), '/') . '/api/attendance-log'],
            ['label' => 'device_sn value', 'value' => $this->device->serial_number],
        ];
    }
}
