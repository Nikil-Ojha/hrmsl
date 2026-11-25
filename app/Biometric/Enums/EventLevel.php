<?php

namespace App\Biometric\Enums;

enum EventLevel: string
{
    use HasOptions;

    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
