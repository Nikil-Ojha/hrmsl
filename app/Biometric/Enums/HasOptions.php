<?php

namespace App\Biometric\Enums;

/**
 * Shared helpers for backed enums exposed to the UI.
 */
trait HasOptions
{
    public static function values(): array
    {
        return array_map(fn (self $case) => $case->value, self::cases());
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
