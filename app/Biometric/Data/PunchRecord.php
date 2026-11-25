<?php

namespace App\Biometric\Data;

use Carbon\CarbonInterface;

/**
 * Vendor-neutral attendance punch as reported by a device.
 */
final class PunchRecord
{
    public function __construct(
        public readonly string $pin,
        public readonly CarbonInterface $punchedAt,
        public readonly ?string $rawState = null,
        public readonly ?string $verifyMode = null,
        public readonly array $extra = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'pin' => $this->pin,
            'punched_at' => $this->punchedAt->toIso8601String(),
            'raw_state' => $this->rawState,
            'verify_mode' => $this->verifyMode,
            'extra' => $this->extra,
        ];
    }
}
