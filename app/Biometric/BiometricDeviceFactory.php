<?php

namespace App\Biometric;

use App\Biometric\Contracts\BiometricDeviceInterface;
use App\Models\BiometricDevice;
use Illuminate\Contracts\Container\Container;

/**
 * Creates the provider implementation bound to a device. Providers are
 * resolved through the container, so their dependencies are injected and
 * can be swapped in tests.
 */
class BiometricDeviceFactory
{
    public function __construct(
        private readonly Container $container,
        private readonly ProviderRegistry $registry,
    ) {
    }

    public function make(BiometricDevice $device): BiometricDeviceInterface
    {
        $class = $this->registry->providerClass((string) $device->protocol);

        return $this->container->make($class, ['device' => $device]);
    }
}
