<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\ProtocolAdapter;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\CommandType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Protocols\FkWebAdapter;

/**
 * FK / Realsoft terminals using the FKWeb realtime push protocol.
 *
 * Punch intake and heartbeats are supported. No official command
 * specification is available, so every command operation is Unverified and
 * disabled by default (BIOMETRIC_FKWEB_ALLOW_UNVERIFIED).
 */
class FkWebProvider extends AbstractPushProvider
{
    public static function definition(): ProviderDefinition
    {
        $s = SupportLevel::Supported;
        $u = SupportLevel::Unverified;
        $x = SupportLevel::Unsupported;

        return new ProviderDefinition(
            key: 'fkweb',
            label: 'FKWeb Realtime Push',
            description: 'Header-based realtime push protocol used by FK / Realsoft terminals. Command support requires hardware verification.',
            communication: ProviderDefinition::PUSH,
            manufacturers: ['Realsoft', 'FK', 'Realtime'],
            defaultPort: 5005,
            authTypes: [AuthType::None, AuthType::CommKey],
            credentialFields: [
                ['name' => 'comm_key', 'label' => 'Comm Key', 'type' => 'password', 'secret' => true, 'required' => false],
            ],
            operations: [
                Operation::Connect->value => $s,
                Operation::TestConnection->value => $s,
                Operation::DeviceInformation->value => $s,
                Operation::DeviceStatus->value => $s,
                Operation::GetUsers->value => $x,
                Operation::EnrollEmployee->value => $u,
                Operation::UpdateEmployee->value => $u,
                Operation::DeleteEmployee->value => $u,
                Operation::GetAttendanceLogs->value => $u,
                Operation::ClearAttendanceLogs->value => $x,
                Operation::SyncDeviceTime->value => $u,
                Operation::RestartDevice->value => $x,
            ],
        );
    }

    public function adapter(): ProtocolAdapter
    {
        return app(FkWebAdapter::class);
    }

    protected function infoRefreshOperation(): ?CommandType
    {
        // GET_DEVICE_STATUS is unverified; only refresh when unverified commands are allowed.
        return static::allowsUnverified() ? CommandType::GetInfo : null;
    }

    public function setupHints(): array
    {
        return [
            ['label' => 'Server URL', 'value' => rtrim((string) config('app.url'), '/') . '/hdata.aspx'],
            ['label' => 'Device ID (must match)', 'value' => $this->device->serial_number],
        ];
    }
}
