<?php

namespace App\Biometric\Support;

/**
 * Driver output for one command: an optional protocol command code (e.g. the
 * FKWeb `cmd_code` header) plus the body that is written to the wire.
 */
final class EncodedCommand
{
    public function __construct(
        public readonly string $body,
        public readonly ?string $code = null,
    ) {
    }

    public function toStorage(): string
    {
        return $this->code ? "[{$this->code}] {$this->body}" : $this->body;
    }
}
