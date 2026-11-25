<?php

namespace App\Biometric\Enums;

enum PunchPairingMode: string
{
    use HasOptions;

    case FirstLast = 'first_last';
    case InOutPairs = 'in_out_pairs';
    case DeviceState = 'device_state';

    public function label(): string
    {
        return match ($this) {
            self::FirstLast => 'First Punch IN / Last Punch OUT',
            self::InOutPairs => 'Consecutive IN-OUT Pairs (Multiple Breaks)',
            self::DeviceState => 'Respect Device IN / OUT State',
        };
    }
}
