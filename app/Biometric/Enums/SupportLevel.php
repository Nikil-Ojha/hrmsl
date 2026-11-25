<?php

namespace App\Biometric\Enums;

/**
 * How well a provider supports an operation.
 *
 *  - Supported:   implemented against documented protocol behaviour.
 *  - Unverified:  wire format is known from vendor SDK material but has not
 *                 been validated on hardware; disabled unless explicitly
 *                 enabled per provider (config biometric.providers.*.allow_unverified).
 *  - Unsupported: the protocol has no such capability.
 */
enum SupportLevel: string
{
    use HasOptions;

    case Supported = 'supported';
    case Unverified = 'unverified';
    case Unsupported = 'unsupported';

    public function label(): string
    {
        return match ($this) {
            self::Supported => 'Supported',
            self::Unverified => 'Needs hardware verification',
            self::Unsupported => 'Not supported',
        };
    }
}
