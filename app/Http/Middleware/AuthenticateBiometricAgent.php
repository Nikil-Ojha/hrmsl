<?php

namespace App\Http\Middleware;

use App\Biometric\Services\AgentCredentialManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates requests from Local Device Communication Agents using HTTPS,
 * Bearer access token, timestamp skew check, dual-layer replay nonce, and HMAC-SHA256.
 */
class AuthenticateBiometricAgent
{
    public function __construct(private readonly AgentCredentialManager $credentials)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $result = $this->credentials->verifySignedRequest($request);

        if (!$result['ok']) {
            $request->attributes->set('biometric_audit_outcome', $result['outcome'] ?? 'auth_failed');
            $request->attributes->set('biometric_audit_summary', $result['error'] ?? 'Unauthorized.');
            if (!empty($result['agent'])) {
                $request->attributes->set('biometric_audit_agent', $result['agent']);
            }

            return response()->json([
                'status' => false,
                'code' => $result['outcome'] ?? 'auth_failed',
                'message' => $result['error'] ?? 'Unauthorized.',
            ], $result['status'] ?? 401);
        }

        $request->attributes->set('biometric_agent', $result['agent']);

        return $next($request);
    }
}
