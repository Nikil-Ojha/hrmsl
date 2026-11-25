<?php

namespace App\Biometric\Enums;

/**
 * Standard ISO/IEC 19794-2 finger index numbering (0-9) used by biometric hardware.
 */
enum FingerSlot: int
{
    case LeftPinky = 0;
    case LeftRing = 1;
    case LeftMiddle = 2;
    case LeftIndex = 3;
    case LeftThumb = 4;
    case RightThumb = 5;
    case RightIndex = 6;
    case RightMiddle = 7;
    case RightRing = 8;
    case RightPinky = 9;

    public function label(): string
    {
        return match ($this) {
            self::LeftPinky => 'Left Pinky',
            self::LeftRing => 'Left Ring',
            self::LeftMiddle => 'Left Middle',
            self::LeftIndex => 'Left Index',
            self::LeftThumb => 'Left Thumb',
            self::RightThumb => 'Right Thumb',
            self::RightIndex => 'Right Index',
            self::RightMiddle => 'Right Middle',
            self::RightRing => 'Right Ring',
            self::RightPinky => 'Right Pinky',
        };
    }

    /** @return array<int, array{value: int, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
