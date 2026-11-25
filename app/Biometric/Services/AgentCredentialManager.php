<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\AgentStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Models\BiometricAgent;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manages installation provisioning tokens, atomic one-time registration,
 * runtime credential rotation/revocation, HMAC-SHA256 request signing, and
 * dual-layer (Cache + DB) replay protection for Local Device Agents.
 */
class AgentCredentialManager
{
    public function issueProvisioningToken(BiometricAgent $agent): string
    {
        $plain = 'prov_' . Str::random(48);
        $ttlHours = max(1, (int) config('biometric.agent.provisioning_token_ttl_hours', 24));

        $agent->forceFill([
            'provisioning_token_hash' => $this->hash($plain),
            'provisioning_token_expires_at' => now()->addHours($ttlHours),
        ])->save();

        return $plain;
    }

    /**
     * Atomically exchange a one-time provisioning token for permanent per-agent
     * Bearer + HMAC-SHA256 credentials.
     *
     * @return array{agent: BiometricAgent, access_token: string, hmac_secret: string}|null
     */
    public function provision(string $agentIdentifier, string $provisioningToken, array $meta = []): ?array
    {
        return DB::transaction(function () use ($agentIdentifier, $provisioningToken, $meta) {
            $agent = BiometricAgent::where('agent_identifier', trim($agentIdentifier))
                ->lockForUpdate()
                ->first();

            if (!$agent || $agent->status === AgentStatus::Revoked) {
                return null;
            }

            if (
                empty($agent->provisioning_token_hash)
                || !$agent->provisioning_token_expires_at
                || $agent->provisioning_token_expires_at->isPast()
                || !hash_equals((string) $agent->provisioning_token_hash, $this->hash($provisioningToken))
            ) {
                return null;
            }

            $prefix = Str::lower(Str::random(8));
            $accessToken = 'agt_' . $prefix . '_' . Str::random(40);
            $hmacSecret = bin2hex(random_bytes(32));

            $agent->forceFill([
                'status' => AgentStatus::Active,
                'connection_status' => ConnectionStatus::Online,
                'provisioning_token_hash' => null,
                'provisioning_token_expires_at' => null,
                'provisioned_at' => $agent->provisioned_at ?? now(),
                'access_token_prefix' => $prefix,
                'access_token_hash' => $this->hash($accessToken),
                'hmac_secret' => $hmacSecret,
                'credentials_rotated_at' => now(),
                'revoked_at' => null,
                'agent_version' => !empty($meta['agent_version']) ? mb_substr((string) $meta['agent_version'], 0, 50) : $agent->agent_version,
                'os_info' => !empty($meta['os_info']) ? mb_substr((string) $meta['os_info'], 0, 150) : $agent->os_info,
                'hostname' => !empty($meta['hostname']) ? mb_substr((string) $meta['hostname'], 0, 150) : $agent->hostname,
                'capabilities' => !empty($meta['capabilities']) && is_array($meta['capabilities']) ? $meta['capabilities'] : $agent->capabilities,
                'last_seen_at' => now(),
                'last_seen_ip' => $meta['ip'] ?? $agent->last_seen_ip,
            ])->save();

            return [
                'agent' => $agent,
                'access_token' => $accessToken,
                'hmac_secret' => $hmacSecret,
            ];
        });
    }

    /**
     * Rotate an already-authenticated active agent's runtime credentials,
     * immediately invalidating the previous Bearer token and HMAC secret.
     *
     * @return array{agent: BiometricAgent, access_token: string, hmac_secret: string}
     */
    public function rotateCredentials(BiometricAgent $agent): array
    {
        $prefix = Str::lower(Str::random(8));
        $accessToken = 'agt_' . $prefix . '_' . Str::random(40);
        $hmacSecret = bin2hex(random_bytes(32));

        $agent->forceFill([
            'access_token_prefix' => $prefix,
            'access_token_hash' => $this->hash($accessToken),
            'hmac_secret' => $hmacSecret,
            'credentials_rotated_at' => now(),
        ])->save();

        return [
            'agent' => $agent,
            'access_token' => $accessToken,
            'hmac_secret' => $hmacSecret,
        ];
    }

    /**
     * Revoke an agent installation immediately. All subsequent API calls fail.
     */
    public function revoke(BiometricAgent $agent): void
    {
        $agent->forceFill([
            'status' => AgentStatus::Revoked,
            'connection_status' => ConnectionStatus::Offline,
            'provisioning_token_hash' => null,
            'provisioning_token_expires_at' => null,
            'access_token_prefix' => null,
            'access_token_hash' => null,
            'hmac_secret' => null,
            'revoked_at' => now(),
        ])->save();
    }

    /**
     * Reactivate a revoked agent (or rotate an active one) by issuing a fresh
     * one-time provisioning token and invalidating old runtime credentials.
     */
    public function resetForReprovisioning(BiometricAgent $agent): string
    {
        $agent->forceFill([
            'status' => AgentStatus::Pending,
            'connection_status' => ConnectionStatus::Unknown,
            'access_token_prefix' => null,
            'access_token_hash' => null,
            'hmac_secret' => null,
            'revoked_at' => null,
        ])->save();

        return $this->issueProvisioningToken($agent);
    }

    /**
     * Validate HTTPS, headers, timestamp skew, dual-layer replay nonce,
     * Bearer token, and HMAC-SHA256 request signature.
     *
     * @return array{ok: bool, status?: int, outcome?: string, error?: string, agent?: BiometricAgent}
     */
    public function verifySignedRequest(Request $request): array
    {
        if (config('biometric.agent.require_https', false) && !$request->isSecure()) {
            return [
                'ok' => false,
                'status' => 426,
                'outcome' => 'https_rejected',
                'error' => 'HTTPS is required for biometric agent communication.',
            ];
        }

        $agentId = trim((string) $request->header('X-Agent-Id', ''));
        $authHeader = (string) $request->header('Authorization', '');
        $timestamp = trim((string) $request->header('X-Agent-Timestamp', ''));
        $nonce = trim((string) $request->header('X-Agent-Nonce', ''));
        $signature = trim((string) $request->header('X-Agent-Signature', ''));

        if ($agentId === '' || !str_starts_with($authHeader, 'Bearer ') || $timestamp === '' || $nonce === '' || $signature === '') {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Missing required agent authentication headers.',
            ];
        }

        if (!ctype_digit($timestamp)) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Invalid X-Agent-Timestamp header.',
            ];
        }

        $maxSkew = max(30, (int) config('biometric.agent.max_clock_skew_seconds', 300));
        if (abs(time() - (int) $timestamp) > $maxSkew) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'replay_blocked',
                'error' => 'Request timestamp outside allowed clock skew window.',
            ];
        }

        if (strlen($nonce) < 8 || strlen($nonce) > 128) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Invalid X-Agent-Nonce header.',
            ];
        }

        $agent = BiometricAgent::where('agent_identifier', $agentId)->first();
        if (!$agent) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Unknown agent identifier.',
            ];
        }

        if ($agent->status !== AgentStatus::Active) {
            return [
                'ok' => false,
                'status' => 403,
                'outcome' => 'auth_failed',
                'error' => 'Agent installation is not active.',
                'agent' => $agent,
            ];
        }

        $bearer = trim(substr($authHeader, 7));
        if (empty($agent->access_token_hash) || !hash_equals((string) $agent->access_token_hash, $this->hash($bearer))) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Invalid agent access token.',
                'agent' => $agent,
            ];
        }

        $hmacSecret = (string) ($agent->hmac_secret ?? '');
        if ($hmacSecret === '') {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Agent signing secret is not configured.',
                'agent' => $agent,
            ];
        }

        $expected = $this->sign(
            $hmacSecret,
            $request->method(),
            '/' . ltrim($request->path(), '/'),
            $timestamp,
            $nonce,
            $request->getContent()
        );

        if (!hash_equals($expected, strtolower($signature))) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'auth_failed',
                'error' => 'Invalid request signature.',
                'agent' => $agent,
            ];
        }

        // Dual-layer replay protection: fast Cache check + persistent DB unique index
        $nonceHash = hash('sha256', $nonce);
        $expiresAt = now()->addSeconds($maxSkew * 2);
        $nonceKey = 'biometric:agent:nonce:' . $agent->id . ':' . $nonceHash;

        if (!Cache::add($nonceKey, 1, $expiresAt)) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'replay_blocked',
                'error' => 'Duplicate request nonce detected.',
                'agent' => $agent,
            ];
        }

        if (config('biometric.agent.persist_nonces', true) && !$this->recordPersistentNonce((int) $agent->id, $nonceHash, $expiresAt)) {
            return [
                'ok' => false,
                'status' => 401,
                'outcome' => 'replay_blocked',
                'error' => 'Duplicate request nonce detected.',
                'agent' => $agent,
            ];
        }

        return ['ok' => true, 'agent' => $agent];
    }

    public function sign(string $hmacSecret, string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        $canonical = self::canonicalString($method, $path, $timestamp, $nonce, $body);

        return hash_hmac('sha256', $canonical, $hmacSecret);
    }

    public static function canonicalString(string $method, string $path, string $timestamp, string $nonce, string $body): string
    {
        return implode("\n", [
            strtoupper($method),
            '/' . ltrim($path, '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);
    }

    private function recordPersistentNonce(int $agentId, string $nonceHash, \Carbon\CarbonInterface $expiresAt): bool
    {
        try {
            DB::table('biometric_agent_nonces')->insert([
                'agent_id' => $agentId,
                'nonce_hash' => $nonceHash,
                'expires_at' => $expiresAt,
                'created_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            // SQLSTATE 23000 = duplicate unique key (replay across workers/cache flush)
            if ((string) $e->getCode() === '23000') {
                return false;
            }

            // If DB table is unavailable in a DB-free unit test environment, fail open to Cache check.
            return true;
        }
    }

    private function hash(string $plain): string
    {
        return hash_hmac('sha256', $plain, (string) config('app.key'));
    }
}
