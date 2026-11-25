<?php

namespace App\Biometric\Enums;

enum AgentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending Provisioning',
            self::Active => 'Active',
            self::Revoked => 'Revoked',
        };
    }
}
