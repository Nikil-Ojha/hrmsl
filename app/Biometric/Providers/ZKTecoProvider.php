<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\ProtocolAdapter;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Protocols\ZkAdmsAdapter;

/**
 * ZKTeco and compatible OEM terminals (eSSL, Realtime, Identix, ...) using
 * the ADMS / iClock PUSH protocol.
 *
 * Only the PUSH transport is implemented. The ZK TCP/UDP SDK (port 4370)
 * requires the vendor SDK and a server on the device's LAN; it can be added
 * later as a separate pull provider without touching this class.
 */
class ZKTecoProvider extends AbstractPushProvider
{
    public static function definition(): ProviderDefinition
    {
        $s = SupportLevel::Supported;

        return new ProviderDefinition(
            key: 'zk_adms',
            label: 'ZKTeco ADMS / iClock Push',
            description: 'HTTP push protocol used by ZKTeco and compatible devices. Point the device "Cloud Server / ADMS" setting at this server.',
            communication: ProviderDefinition::PUSH,
            manufacturers: ['ZKTeco', 'eSSL', 'Realtime', 'Identix'],
            defaultPort: 4370,
            authTypes: [AuthType::None, AuthType::CommKey],
            credentialFields: [
                ['name' => 'comm_key', 'label' => 'Comm Key', 'type' => 'password', 'secret' => true, 'required' => false, 'help' => 'Communication password configured on the device (if any).'],
            ],
            operations: [
                Operation::Connect->value => $s,
                Operation::TestConnection->value => $s,
                Operation::DeviceInformation->value => $s,     // INFO
                Operation::DeviceStatus->value => $s,
                Operation::GetUsers->value => $s,              // DATA QUERY USERINFO
                Operation::EnrollEmployee->value => $s,        // DATA UPDATE USERINFO
                Operation::UpdateEmployee->value => $s,        // DATA UPDATE USERINFO
                Operation::DeleteEmployee->value => $s,        // DATA DELETE USERINFO
                Operation::GetAttendanceLogs->value => $s,     // DATA QUERY ATTLOG
                Operation::ClearAttendanceLogs->value => $s,   // CLEAR LOG
                Operation::SyncDeviceTime->value => SupportLevel::Unverified, // SET OPTIONS DateTime (model dependent)
                Operation::RestartDevice->value => $s,         // REBOOT
            ],
        );
    }

    public function adapter(): ProtocolAdapter
    {
        return app(ZkAdmsAdapter::class);
    }

    protected function interpretInformation(array $result): array
    {
        return isset($result['raw']) ? ZkAdmsAdapter::parseInfo((string) $result['raw']) : parent::interpretInformation($result);
    }

    public function setupHints(): array
    {
        $url = (string) config('app.url');
        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'https') ? 443 : 80);

        return [
            ['label' => 'Server Address', 'value' => (string) parse_url($url, PHP_URL_HOST)],
            ['label' => 'Server Port', 'value' => (string) $port],
            ['label' => 'Device Serial Number (must match)', 'value' => $this->device->serial_number],
        ];
    }
}
