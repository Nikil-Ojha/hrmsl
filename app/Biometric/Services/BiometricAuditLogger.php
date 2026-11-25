<?php

namespace App\Biometric\Services;

use App\Biometric\Support\Tenant;
use App\Models\BiometricAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Centralized audit logger for biometric & manual attendance operations:
 *  - device changes (create, edit, activate/deactivate, delete, API key rotation)
 *  - enrollment lifecycle (start, re-enroll, confirm, remove, multi-device sync)
 *  - synchronization (connectivity, sync time/employees, fetch logs, reprocess, retries)
 *  - command execution (restart, clear logs, remove user, cancel commands)
 *  - manual attendance corrections & regularization approvals
 *  - biometric settings & policy changes
 *
 * Automatically scrubs secrets, tokens, passwords, and biometric template payloads.
 */
class BiometricAuditLogger
{
    public const CAT_DEVICE = 'device';
    public const CAT_AGENT = 'agent';
    public const CAT_ENROLLMENT = 'enrollment';
    public const CAT_SYNC = 'sync';
    public const CAT_COMMAND = 'command';
    public const CAT_ATTENDANCE_CORRECTION = 'attendance_correction';
    public const CAT_SETTINGS = 'settings';

    /** @var list<string> */
    private const REDACTED_KEYS = [
        'credentials',
        'password',
        'secret',
        'api_key',
        'access_token',
        'hmac_secret',
        'provisioning_token',
        'template_data',
        'raw_template',
        'raw_image',
        'fingerprint_image',
        'face_image',
        'authorization',
    ];

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed>      $metadata
     */
    public function log(
        string $category,
        string $action,
        string $description,
        ?int $companyId = null,
        ?string $targetType = null,
        ?int $targetId = null,
        ?string $targetLabel = null,
        ?int $deviceId = null,
        ?int $employeeId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?User $actor = null,
        string $actorType = 'user',
    ): ?BiometricAuditLog {
        $actor = $actor ?? Auth::user();
        $resolvedCompanyId = $companyId ?: ($actor ? (int) Tenant::companyId($actor) : null);

        if (!$resolvedCompanyId) {
            return null;
        }

        $req = app()->runningInConsole() ? null : request();

        try {
            return BiometricAuditLog::create([
                'company_id' => $resolvedCompanyId,
                'actor_id' => $actor?->id,
                'actor_name' => $actor?->name ?? ($actorType === 'system' ? 'System' : ucfirst($actorType)),
                'actor_type' => $actorType,
                'category' => Str::limit($category, 40, ''),
                'action' => Str::limit($action, 80, ''),
                'target_type' => $targetType !== null ? Str::limit($targetType, 80, '') : null,
                'target_id' => $targetId,
                'target_label' => $targetLabel !== null ? Str::limit($targetLabel, 200, '') : null,
                'device_id' => $deviceId,
                'employee_id' => $employeeId,
                'description' => Str::limit($description, 500, ''),
                'before_state' => $before !== null ? $this->scrub($before) : null,
                'after_state' => $after !== null ? $this->scrub($after) : null,
                'metadata' => empty($metadata) ? null : $this->scrub($metadata),
                'ip_address' => $req?->ip(),
                'user_agent' => $req?->userAgent() ? Str::limit((string) $req->userAgent(), 255, '') : null,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Failed to persist biometric audit log entry.', [
                'category' => $category,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
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
            $lower = strtolower((string) $key);
            if ($this->isSensitive($lower)) {
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
     * @return list<array{value: string, label: string}>
     */
    public static function categoryOptions(): array
    {
        return [
            ['value' => self::CAT_DEVICE, 'label' => 'Device Changes'],
            ['value' => self::CAT_ENROLLMENT, 'label' => 'Enrollment'],
            ['value' => self::CAT_SYNC, 'label' => 'Synchronization'],
            ['value' => self::CAT_COMMAND, 'label' => 'Command Execution'],
            ['value' => self::CAT_ATTENDANCE_CORRECTION, 'label' => 'Manual Attendance Corrections'],
            ['value' => self::CAT_SETTINGS, 'label' => 'Biometric Settings'],
            ['value' => self::CAT_AGENT, 'label' => 'Local Agents'],
        ];
    }

    private function isSensitive(string $lowerKey): bool
    {
        foreach (self::REDACTED_KEYS as $needle) {
            if ($lowerKey === $needle || str_contains($lowerKey, $needle)) {
                return true;
            }
        }

        return false;
    }
}
