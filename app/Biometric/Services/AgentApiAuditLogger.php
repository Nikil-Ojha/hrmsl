<?php

namespace App\Biometric\Services;

use App\Models\BiometricAgent;
use App\Models\BiometricApiAuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Records tamper-evident, secret-scrubbed audit trails for every Biometric
 * Agent API request (including rejected auth, replay, HTTPS, rate-limit, and
 * unauthorized device attempts).
 */
class AgentApiAuditLogger
{
    /** @var list<string> */
    private const REDACTED_KEYS = [
        'access_token',
        'hmac_secret',
        'provisioning_token',
        'template_data',
        'raw_template',
        'credentials',
        'password',
        'secret',
        'api_key',
        'token',
        'authorization',
        'x-agent-signature',
    ];

    public function record(
        Request $request,
        int $statusCode,
        string $outcome,
        int $latencyMs = 0,
        ?string $summary = null,
        array $metadata = [],
        ?BiometricAgent $agent = null,
        ?int $deviceId = null,
    ): ?BiometricApiAuditLog {
        $agent = $agent ?? $request->attributes->get('biometric_agent');
        $deviceId = $deviceId ?? $request->attributes->get('biometric_audit_device_id');
        $claimed = trim((string) ($request->header('X-Agent-Id') ?: $request->input('agent_identifier', '')));

        try {
            return BiometricApiAuditLog::create([
                'company_id' => $agent?->company_id,
                'agent_id' => $agent?->id,
                'device_id' => is_numeric($deviceId) ? (int) $deviceId : null,
                'agent_identifier_claimed' => $claimed !== '' ? Str::limit($claimed, 100, '') : null,
                'method' => strtoupper($request->method()),
                'path' => Str::limit('/' . ltrim($request->path(), '/'), 255, ''),
                'route_name' => $request->route()?->getName(),
                'status_code' => $statusCode,
                'outcome' => Str::limit($outcome, 40, ''),
                'ip_address' => $request->ip(),
                'user_agent' => Str::limit((string) $request->userAgent(), 255, '') ?: null,
                'request_nonce' => Str::limit((string) $request->header('X-Agent-Nonce', ''), 128, '') ?: null,
                'idempotency_key' => Str::limit((string) $request->header('X-Idempotency-Key', ''), 128, '') ?: null,
                'latency_ms' => max(0, $latencyMs),
                'summary' => $summary !== null ? Str::limit($summary, 255, '') : null,
                'metadata' => empty($metadata) ? null : $this->scrub($metadata),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Audit failure must never crash the API or expose secrets; log safely.
            Log::warning('Failed to persist biometric API audit log.', [
                'path' => $request->path(),
                'outcome' => $outcome,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Recursively redact any secrets, tokens, or raw biometric template payloads.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public function scrub(array $data, int $depth = 0): array
    {
        if ($depth > 5) {
            return ['_truncated' => true];
        }

        $clean = [];
        foreach ($data as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if ($this->isSensitiveKey($lowerKey)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->scrub($value, $depth + 1);
            } elseif (is_string($value) && strlen($value) > 512) {
                $clean[$key] = Str::limit($value, 256, '...[truncated]');
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Prune expired replay nonces and old API audit logs according to retention settings.
     *
     * @return array{nonces_deleted: int, logs_deleted: int}
     */
    public function prune(?int $retentionDays = null): array
    {
        $days = max(7, $retentionDays ?? (int) config('biometric.retention.audit_logs_days', 90));

        $noncesDeleted = DB::table('biometric_agent_nonces')
            ->where('expires_at', '<', now())
            ->delete();

        $logsDeleted = BiometricApiAuditLog::where('created_at', '<', now()->subDays($days))
            ->delete();

        return [
            'nonces_deleted' => (int) $noncesDeleted,
            'logs_deleted' => (int) $logsDeleted,
        ];
    }

    private function isSensitiveKey(string $lowerKey): bool
    {
        foreach (self::REDACTED_KEYS as $needle) {
            if ($lowerKey === $needle || str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
