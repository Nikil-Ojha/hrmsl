<?php

namespace App\Biometric\Contracts;

use App\Biometric\Support\EncodedCommand;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;

/**
 * Translates vendor-neutral commands into one wire protocol. Adapters contain
 * no business logic and no I/O; they are pure encoders/decoders that a
 * provider composes with a transport.
 */
interface ProtocolAdapter
{
    /**
     * @throws \App\Biometric\Support\UnsupportedCommandException
     */
    public function encode(BiometricCommand $command, BiometricDevice $device): EncodedCommand;
}
