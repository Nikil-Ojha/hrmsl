<?php

namespace App\Biometric\Services;

use App\Models\BiometricDevice;
use Illuminate\Support\Str;

/**
 * Issues per-device API keys. Only a prefix (for lookup) and a keyed hash are
 * stored; the plain key is shown to the admin exactly once. For HMAC
 * authentication the key is additionally kept encrypted because the server
 * needs it to verify request signatures.
 */
class DeviceApiKeyManager
{
    public function issue(BiometricDevice $device): string
    {
        $prefix = Str::lower(Str::random(8));
        $plain = 'bd_' . $prefix . '_' . Str::random(40);

        $credentials = $device->credentials ?? [];
        if ($device->auth_type?->value === 'hmac') {
            $credentials['hmac_secret'] = $plain;
        } else {
            unset($credentials['hmac_secret']);
        }

        $device->forceFill([
            'api_key_prefix' => $prefix,
            'api_key_hash' => $this->hash($plain),
            'credentials' => $credentials ?: null,
        ])->save();

        return $plain;
    }

    public function revoke(BiometricDevice $device): void
    {
        $credentials = $device->credentials ?? [];
        unset($credentials['hmac_secret']);

        $device->forceFill([
            'api_key_prefix' => null,
            'api_key_hash' => null,
            'credentials' => $credentials ?: null,
        ])->save();
    }

    /**
     * Resolve a device from a presented key (used by the protocol gateway).
     */
    public function findByKey(string $plain): ?BiometricDevice
    {
        if (!preg_match('/^bd_([a-z0-9]{8})_[A-Za-z0-9]{40}$/', $plain, $m)) {
            return null;
        }

        $device = BiometricDevice::where('api_key_prefix', $m[1])->first();

        return $device && hash_equals((string) $device->api_key_hash, $this->hash($plain)) ? $device : null;
    }

    private function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }
}
