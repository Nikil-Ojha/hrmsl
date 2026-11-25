<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\SyncFailureStatus;
use App\Models\BiometricDevice;
use App\Models\BiometricIdempotencyKey;
use App\Models\BiometricPunch;
use App\Models\BiometricSyncFailure;

/**
 * Retries failed or previously unmatched biometric sync entries (both on
 * schedule and on demand from the HRMS UI).
 */
class SyncFailureRetryService
{
    public function __construct(
        private readonly PunchSynchronizationService $sync,
        private readonly AttendanceProcessor $processor,
    ) {
    }

    /**
     * Retry one failed synchronization log entry.
     *
     * @return array{resolved: bool, message: string}
     */
    public function retry(BiometricSyncFailure $failure): array
    {
        if ($failure->status === SyncFailureStatus::Resolved) {
            return ['resolved' => true, 'message' => 'Already resolved.'];
        }

        // Case 1: Punch was already saved (e.g. unmatched_employee) — reprocess it!
        if ($failure->resolved_punch_id) {
            $punch = BiometricPunch::with(['device', 'employee'])->find($failure->resolved_punch_id);
            if ($punch) {
                $updated = $this->processor->reprocessPunch($punch);
                if (in_array($updated->processing_status, [
                    PunchProcessingStatus::Processed,
                    PunchProcessingStatus::DuplicateSuppressed,
                    PunchProcessingStatus::ConflictSkipped,
                ], true)) {
                    $failure->forceFill([
                        'status' => SyncFailureStatus::Resolved,
                        'resolved_at' => now(),
                        'last_attempted_at' => now(),
                        'retry_count' => $failure->retry_count + 1,
                    ])->save();

                    return ['resolved' => true, 'message' => 'Punch matched and attendance recalculated.'];
                }

                return $this->scheduleNext($failure, $updated->processing_note ?: 'Employee still unmatched.');
            }
        }

        // Case 2: Re-run ingestion for a device failure (e.g. device was reactivated / assigned)
        $device = $failure->device_id ? BiometricDevice::find($failure->device_id) : null;
        if (!$device || !$device->is_active) {
            return $this->scheduleNext($failure, 'Target biometric device is missing or inactive.');
        }

        $res = $this->sync->ingestBatchForDevice(
            $device,
            [$failure->payload],
            $failure->source,
            $failure->agent,
            trigger: 'retry',
            recordSyncRun: false
        );

        $item = $res['results'][0] ?? null;
        if ($item && in_array($item['status'], ['accepted', 'duplicate'], true)) {
            $punchId = $item['punch_id'] ?? null;
            $stillUnmatched = ($res['unmatched'] ?? 0) > 0;
            if ($stillUnmatched) {
                $failure->forceFill(['resolved_punch_id' => $punchId])->saveQuietly();

                return $this->scheduleNext($failure, 'Punch stored, waiting for employee mapping.');
            }

            $failure->forceFill([
                'status' => SyncFailureStatus::Resolved,
                'resolved_at' => now(),
                'resolved_punch_id' => $punchId,
                'last_attempted_at' => now(),
                'retry_count' => $failure->retry_count + 1,
            ])->save();

            return ['resolved' => true, 'message' => 'Synchronization succeeded on retry.'];
        }

        return $this->scheduleNext($failure, (string) ($item['reason'] ?? 'Retry failed.'));
    }

    /**
     * Automatically retry all pending failures whose next_retry_at has elapsed,
     * and prune expired API idempotency keys.
     *
     * @return array{attempted: int, resolved: int, remaining: int, pruned_keys: int}
     */
    public function retryDue(int $limit = 100): array
    {
        $failures = BiometricSyncFailure::where('status', SyncFailureStatus::PendingRetry->value)
            ->where(fn ($q) => $q->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', now()))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $resolved = 0;
        foreach ($failures as $failure) {
            if ($this->retry($failure)['resolved']) {
                $resolved++;
            }
        }

        $pruned = BiometricIdempotencyKey::where('expires_at', '<', now())->delete();

        return [
            'attempted' => $failures->count(),
            'resolved' => $resolved,
            'remaining' => $failures->count() - $resolved,
            'pruned_keys' => $pruned,
        ];
    }

    private function scheduleNext(BiometricSyncFailure $failure, string $reason): array
    {
        $attempts = $failure->retry_count + 1;
        $max = max(1, (int) config('biometric.sync.max_retry_attempts', 8));
        $base = max(30, (int) config('biometric.sync.retry_base_delay_seconds', 60));
        $delay = min($base * (2 ** min($attempts - 1, 8)), 14400);

        $abandoned = $attempts >= $max;
        $failure->forceFill([
            'retry_count' => $attempts,
            'error_message' => mb_substr($reason, 0, 2000),
            'last_attempted_at' => now(),
            'next_retry_at' => $abandoned ? null : now()->addSeconds($delay),
            'status' => $abandoned ? SyncFailureStatus::Abandoned : SyncFailureStatus::PendingRetry,
        ])->save();

        return ['resolved' => false, 'message' => $reason];
    }
}
