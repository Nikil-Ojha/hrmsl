<?php

namespace App\Biometric\Contracts;

/**
 * Implemented by providers whose transport delivers queued commands when the
 * device polls the server (push devices).
 */
interface QueuesDeviceCommands
{
    public function adapter(): ProtocolAdapter;
}
