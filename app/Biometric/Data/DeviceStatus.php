<?php

namespace App\Biometric\Data;

final class DeviceStatus
{
    public function __construct(
        public readonly string $connectionStatus,
        public readonly bool $isActive,
        public readonly bool $online,
        public readonly ?string $lastSeenAt,
        public readonly ?string $lastSeenIp,
        public readonly int $pendingCommands,
        public readonly ?string $lastError,
        public readonly ?string $lastErrorAt,
        public readonly ?string $deviceTime = null,
    ) {
    }

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
