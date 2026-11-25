<?php

namespace App\Biometric\Enums;

enum PunchType: string
{
    use HasOptions;

    case In = 'in';
    case Out = 'out';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::In => 'IN',
            self::Out => 'OUT',
            self::Unknown => 'UNKNOWN',
        };
    }

    /**
     * Normalize vendor-specific state codes (ZKTeco 0..5, FKWeb, Own Brand, strings)
     * into the canonical IN / OUT / UNKNOWN enum.
     */
    public static function fromRaw(mixed $raw): self
    {
        $val = strtolower(trim((string) $raw));
        if ($val === '') {
            return self::Unknown;
        }

        return match ($val) {
            '0', 'in', 'check_in', 'check in', 'checkin', 'clock_in', 'clock in', '3', 'break_in', 'break in', '4', 'ot_in', 'ot in' => self::In,
            '1', 'out', 'check_out', 'check out', 'checkout', 'clock_out', 'clock out', '2', 'break_out', 'break out', '5', 'ot_out', 'ot out' => self::Out,
            default => self::Unknown,
        };
    }
}
