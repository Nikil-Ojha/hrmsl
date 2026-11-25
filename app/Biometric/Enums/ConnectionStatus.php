<?php

namespace App\Biometric\Enums;

enum ConnectionStatus: string
{
    use HasOptions;

    case Unknown = 'unknown';
    case Online = 'online';
    case Offline = 'offline';
    case Error = 'error';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Unknown',
            self::Online => 'Online',
            self::Offline => 'Offline',
            self::Error => 'Error',
        };
    }
}
