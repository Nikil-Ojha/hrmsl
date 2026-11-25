<?php

namespace App\Biometric\Data;

use App\Models\BiometricSyncRun;
use App\Models\User;

/**
 * Who/why an operation runs. Commands queued by a provider are linked to the
 * sync run so the run can be finalized when the device acknowledges them.
 */
final class OperationContext
{
    public function __construct(
        public readonly ?BiometricSyncRun $run = null,
        public readonly ?User $user = null,
        public readonly int $priority = 100,
    ) {
    }
}
