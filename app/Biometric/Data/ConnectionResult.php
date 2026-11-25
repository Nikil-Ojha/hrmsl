<?php

namespace App\Biometric\Data;

final class ConnectionResult
{
    public function __construct(
        public readonly bool $connected,
        public readonly string $mode,      // push | pull | simulated
        public readonly string $message,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
