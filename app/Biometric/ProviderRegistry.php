<?php

namespace App\Biometric;

use App\Biometric\Contracts\BiometricDeviceInterface;
use App\Biometric\Data\ProviderDefinition;
use InvalidArgumentException;

/**
 * Catalogue of hardware providers configured in config/biometric.php.
 * Adding a manufacturer = adding one provider class and one config entry.
 */
class ProviderRegistry
{
    /** All configured provider keys (including disabled ones, for resolving existing devices). */
    public function keys(): array
    {
        return array_keys(config('biometric.providers', []));
    }

    /** Provider keys that may be chosen when registering/editing a device. */
    public function selectableKeys(): array
    {
        return array_values(array_filter($this->keys(), fn (string $key) => $this->isEnabled($key)));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, config('biometric.providers', []));
    }

    public function isEnabled(string $key): bool
    {
        return $this->has($key) && (bool) config("biometric.providers.{$key}.enabled", true);
    }

    /**
     * @return class-string<BiometricDeviceInterface>
     */
    public function providerClass(string $key): string
    {
        $class = config("biometric.providers.{$key}.class");

        if (!$class || !class_exists($class) || !is_subclass_of($class, BiometricDeviceInterface::class)) {
            throw new InvalidArgumentException("Biometric provider [{$key}] is not configured correctly.");
        }

        return $class;
    }

    public function definition(string $key): ProviderDefinition
    {
        return $this->providerClass($key)::definition();
    }

    public function allowsUnverified(string $key): bool
    {
        return (bool) config("biometric.providers.{$key}.allow_unverified", false);
    }

    public function label(string $key): string
    {
        return $this->has($key) ? $this->definition($key)->label : $key;
    }

    /**
     * Metadata for the UI (device form, capability matrix).
     *
     * @param bool $selectableOnly Only providers that can be chosen for new devices
     */
    public function describeAll(bool $selectableOnly = true): array
    {
        $keys = $selectableOnly ? $this->selectableKeys() : $this->keys();

        return array_map(fn (string $key) => $this->describe($key), $keys);
    }

    public function describe(string $key): array
    {
        return $this->definition($key)->toArray($this->allowsUnverified($key));
    }
}
