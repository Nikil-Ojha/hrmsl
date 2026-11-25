<?php

namespace App\Biometric\Support;

use RuntimeException;

/**
 * Raised when a device action cannot be performed. The message is safe to
 * show to the end user.
 */
class DeviceActionException extends RuntimeException
{
}
