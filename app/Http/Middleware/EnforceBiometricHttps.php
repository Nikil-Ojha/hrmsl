<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces HTTPS-only communication for all Biometric Agent API endpoints
 * (including unauthenticated /register and /provision) when require_https is enabled.
 */
class EnforceBiometricHttps
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('biometric.agent.require_https', false) && !$request->isSecure()) {
            $request->attributes->set('biometric_audit_outcome', 'https_rejected');
            $request->attributes->set('biometric_audit_summary', 'Plain HTTP request rejected; HTTPS required.');

            return response()->json([
                'status' => false,
                'code' => 'https_required',
                'message' => 'HTTPS is required for biometric agent communication.',
            ], 426);
        }

        return $next($request);
    }
}
