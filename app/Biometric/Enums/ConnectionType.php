<?php

namespace App\Biometric\Enums;

enum ConnectionType: string
{
    use HasOptions;

    case Lan = 'lan';
    case Wifi = 'wifi';
    case Usb = 'usb';
    case Cloud = 'cloud';

    public function label(): string
    {
        return match ($this) {
            self::Lan => 'LAN',
            self::Wifi => 'Wi-Fi',
            self::Usb => 'USB',
            self::Cloud => 'Cloud',
        };
    }

    /**
     * USB devices are offline: they cannot receive commands and are synced
     * through file import only.
     */
    public function isNetworked(): bool
    {
        return $this !== self::Usb;
    }
}
