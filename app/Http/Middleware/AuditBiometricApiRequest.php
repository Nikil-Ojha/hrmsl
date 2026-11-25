<?php

namespace App\Http\Middleware;

use App\Biometric\Services\AgentApiAuditLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Wraps every Biometric Agent API endpoint to record a complete, secret-scrubbed
 * audit trail (latency, agent, device, outcome, IP, nonce, idempotency key).
 */
class AuditBiometricApiRequest
{
    public function __construct(private readonly AgentApiAuditLogger $audit)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
        $status = $response->getStatusCode();

        $outcome = (string) ($request->attributes->get('biometric_audit_outcome') ?: $this->inferOutcome($status));
        $summary = $request->attributes->get('biometric_audit_summary');
        $meta = $request->attributes->get('biometric_audit_meta', []);

        if ($summary === null && $status >= 400) {
            $decoded = json_decode((string) $response->getContent(), true);
            if (is_array($decoded) && !empty($decoded['message'])) {
                $summary = (string) $decoded['message'];
            }
        }

        $this->audit->record(
            $request,
            $status,
            $outcome,
            $latencyMs,
            $summary,
            is_array($meta) ? $meta : [],
            $request->attributes->get('biometric_agent') ?? $request->attributes->get('biometric_audit_agent'),
            $request->attributes->get('biometric_audit_device_id'),
        );

        return $response;
    }

    private function inferOutcome(int $status): string
    {
        return match (true) {
            $status >= 200 && $status < 300 => 'success',
            $status === 401 => 'auth_failed',
            $status === 403 => 'forbidden_device',
            $status === 422 => 'validation_failed',
            $status === 426 => 'https_rejected',
            $status === 429 => 'rate_limited',
            default => 'error',
        };
    }
}
