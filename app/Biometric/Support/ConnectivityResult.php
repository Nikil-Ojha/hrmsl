<?php

namespace App\Biometric\Support;

final class ConnectivityResult
{
    public function __construct(
        public readonly bool $online,
        public readonly string $message,
        public readonly array $checks = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'online' => $this->online,
            'message' => $this->message,
            'checks' => $this->checks,
        ];
    }
}
