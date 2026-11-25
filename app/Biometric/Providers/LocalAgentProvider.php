<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\ProtocolAdapter;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Protocols\NativeAdapter;

/**
 * Provider for biometric devices operating on a local LAN, Wi-Fi or USB
 * interface and managed through an installed Local Device Communication Agent.
 *
 * The cloud HRMS queues vendor-neutral commands; the local agent fetches them
 * over HTTPS and executes them using its hardware-specific SDK plugin without
 * exposing the biometric device to the public internet.
 */
class LocalAgentProvider extends AbstractPushProvider
{
    public static function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'local_agent',
            label: 'Local Agent Bridge (LAN / Wi-Fi / USB)',
            description: 'Device communicates only with the on-site Local Device Communication Agent. Hardware SDK plugins run locally on the office machine.',
            communication: ProviderDefinition::PUSH,
            manufacturers: ['Own brand', 'ZKTeco', 'eSSL', 'Suprema', 'Hikvision', 'USB / Custom SDK'],
            defaultPort: 4370,
            authTypes: [AuthType::None, AuthType::CommKey, AuthType::Basic, AuthType::Token],
            credentialFields: [
                ['name' => 'driver', 'label' => 'Agent Driver / SDK Plugin', 'type' => 'text', 'secret' => false, 'required' => false, 'help' => 'Driver name on the local agent (e.g. http_bridge, usb_file, sdk_subprocess, mock). Leave blank for default.'],
                ['name' => 'usb_path', 'label' => 'USB / Export Folder Path', 'type' => 'text', 'secret' => false, 'required' => false, 'help' => 'Used for USB mass-storage / export-file or serial devices on the agent host.'],
                ['name' => 'sdk_command', 'label' => 'SDK Bridge Executable (optional)', 'type' => 'text', 'secret' => false, 'required' => false, 'help' => 'Path to external vendor SDK adapter executable when using sdk_subprocess.'],
                ['name' => 'comm_key', 'label' => 'Device Comm Key / Password', 'type' => 'password', 'secret' => true, 'required' => false],
                ['name' => 'username', 'label' => 'Device Username (optional)', 'type' => 'text', 'secret' => false, 'required' => false],
                ['name' => 'password', 'label' => 'Device Password (optional)', 'type' => 'password', 'secret' => true, 'required' => false],
                ['name' => 'token', 'label' => 'Device Local API Token (optional)', 'type' => 'password', 'secret' => true, 'required' => false],
            ],
            operations: array_fill_keys(array_map(fn (Operation $o) => $o->value, Operation::cases()), SupportLevel::Supported),
        );
    }

    public function adapter(): ProtocolAdapter
    {
        // Local agents consume vendor-neutral JSON envelopes directly.
        return app(NativeAdapter::class);
    }

    public function setupHints(): array
    {
        $agent = $this->device->agent;

        return [
            ['label' => 'Assigned Local Agent', 'value' => $agent ? "{$agent->name} ({$agent->agent_identifier})" : 'Not assigned — edit device to select a Local Agent'],
            ['label' => 'Local Network Address', 'value' => $this->device->ip_address ? ($this->device->ip_address . ($this->device->port ? ':' . $this->device->port : '')) : 'USB / Non-IP'],
            ['label' => 'Serial Number', 'value' => $this->device->serial_number],
        ];
    }
}
