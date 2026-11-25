<?php

namespace App\Http\Middleware;

use App\Biometric\Services\DeviceApiKeyManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates direct cloud-push biometric devices (e.g. Own Brand Native
 * terminals) via per-device API key and optional HMAC-SHA256 signature.
 */
class AuthenticateBiometricDevice
{
    public function __construct(private readonly DeviceApiKeyManager $keys)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (config('biometric.agent.require_https', false) && !$request->isSecure()) {
            return response()->json(['status' => false, 'message' => 'HTTPS is required.'], 426);
        }

        $apiKey = trim((string) ($request->header('X-Device-Key') ?? ''));
        if ($apiKey === '') {
            $auth = (string) $request->header('Authorization', '');
            if (str_starts_with($auth, 'Bearer ')) {
                $apiKey = trim(substr($auth, 7));
            }
        }

        if ($apiKey === '') {
            return response()->json(['status' => false, 'message' => 'Missing device API key.'], 401);
        }

        $device = $this->keys->findByKey($apiKey);
        if (!$device) {
            return response()->json(['status' => false, 'message' => 'Invalid device API key.'], 401);
        }

        if (!$device->is_active) {
            return response()->json(['status' => false, 'message' => 'Device is inactive.'], 403);
        }

        // If configured for HMAC auth, enforce timestamp skew and HMAC-SHA256 signature.
        if ($device->auth_type?->value === 'hmac') {
            $ts = trim((string) $request->header('X-Device-Timestamp', ''));
            $sig = trim((string) $request->header('X-Device-Signature', ''));
            $secret = (string) ($device->credential('hmac_secret') ?? '');
            $maxSkew = max(30, (int) config('biometric.agent.max_clock_skew_seconds', 300));

            if ($ts === '' || !ctype_digit($ts) || abs(time() - (int) $ts) > $maxSkew || $sig === '' || $secret === '') {
                return response()->json(['status' => false, 'message' => 'Missing or invalid HMAC authentication headers.'], 401);
            }

            $canonical = implode("\n", [
                strtoupper($request->method()),
                '/' . ltrim($request->path(), '/'),
                $ts,
                hash('sha256', $request->getContent()),
            ]);

            if (!hash_equals(hash_hmac('sha256', $canonical, $secret), strtolower($sig))) {
                return response()->json(['status' => false, 'message' => 'Invalid device HMAC signature.'], 401);
            }
        }

        $request->attributes->set('biometric_device', $device);

        return $next($request);
    }
}
