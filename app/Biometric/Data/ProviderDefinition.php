<?php

namespace App\Biometric\Data;

use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;

/**
 * Static description of a provider: identity, transport, auth options and
 * the support level of every operation. Drives validation and the UI.
 */
final class ProviderDefinition
{
    public const PUSH = 'push';
    public const PULL = 'pull';
    public const SIMULATED = 'simulated';

    /**
     * @param AuthType[]                    $authTypes
     * @param array<int, array>             $credentialFields
     * @param array<string, SupportLevel>   $operations keyed by Operation value
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $description,
        public readonly string $communication,
        public readonly array $manufacturers = [],
        public readonly ?int $defaultPort = null,
        public readonly array $authTypes = [AuthType::None],
        public readonly array $credentialFields = [],
        public readonly array $operations = [],
        public readonly bool $developmentOnly = false,
    ) {
    }

    public function support(Operation $operation): SupportLevel
    {
        return $this->operations[$operation->value] ?? SupportLevel::Unsupported;
    }

    public function toArray(bool $allowUnverified = false): array
    {
        $ops = [];
        foreach (Operation::cases() as $op) {
            $level = $this->support($op);
            $ops[$op->value] = [
                'level' => $level->value,
                'available' => $level === SupportLevel::Supported || ($level === SupportLevel::Unverified && $allowUnverified),
            ];
        }

        $available = fn (Operation $op) => $ops[$op->value]['available'];

        return [
            'value' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'manufacturers' => $this->manufacturers,
            'default_port' => $this->defaultPort,
            'development_only' => $this->developmentOnly,
            'auth_types' => array_map(fn (AuthType $a) => ['value' => $a->value, 'label' => $a->label()], $this->authTypes),
            'credential_fields' => $this->credentialFields,
            'operations' => $ops,
            'allow_unverified' => $allowUnverified,
            // Shorthand flags used by list/badge UI
            'capabilities' => [
                'communication' => $this->communication,
                'time_sync' => $available(Operation::SyncDeviceTime),
                'employee_sync' => $available(Operation::EnrollEmployee),
                'log_fetch' => $available(Operation::GetAttendanceLogs),
                'supports_tcp_probe' => $this->communication !== self::SIMULATED,
            ],
        ];
    }
}
