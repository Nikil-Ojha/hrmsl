<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\PunchSource;
use App\Biometric\Enums\PunchType;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncFailureStatus;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\Enums\VerificationMethod;
use App\Models\BiometricAgent;
use App\Models\BiometricAttendanceLog;
use App\Models\BiometricDevice;
use App\Models\BiometricIdempotencyKey;
use App\Models\BiometricPunch;
use App\Models\BiometricSyncFailure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Canonical attendance synchronization pipeline for all sources (Local Agent,
 * Direct Device API, ADMS/iClock, FKWeb, USB import).
 */
class PunchSynchronizationService
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly EmployeeSelector $employees,
        private readonly DeviceActivityRecorder $activity,
        private readonly SyncRunService $runs,
    ) {
    }

    /**
     * Execute a batch ingestion with optional HTTP batch-level idempotency key.
     *
     * @param callable(): array<string, mixed> $executor
     * @return array{replayed: bool, body: array<string, mixed>, status: int}
     */
    public function withIdempotency(string $scope, ?string $idempotencyKey, string $rawPayload, callable $executor): array
    {
        $key = trim((string) $idempotencyKey);
        if ($key === '') {
            return ['replayed' => false, 'body' => $executor(), 'status' => 200];
        }

        $requestHash = hash('sha256', $rawPayload);
        $existing = BiometricIdempotencyKey::where('scope', $scope)
            ->where('idempotency_key', $key)
            ->where('expires_at', '>', now())
            ->first();

        if ($existing) {
            if (!hash_equals($existing->request_hash, $requestHash)) {
                return [
                    'replayed' => false,
                    'status' => 409,
                    'body' => [
                        'status' => false,
                        'message' => 'Idempotency key was already used with a different request payload.',
                    ],
                ];
            }

            return [
                'replayed' => true,
                'status' => $existing->response_status,
                'body' => $existing->response_body,
            ];
        }

        $body = $executor();
        $ttlHours = max(1, (int) config('biometric.sync.idempotency_ttl_hours', 48));

        try {
            BiometricIdempotencyKey::create([
                'scope' => $scope,
                'idempotency_key' => $key,
                'request_hash' => $requestHash,
                'response_status' => 200,
                'response_body' => $body,
                'expires_at' => now()->addHours($ttlHours),
            ]);
        } catch (QueryException) {
            // Concurrent identical batch raced — safe to ignore and return computed result.
        }

        return ['replayed' => false, 'body' => $body, 'status' => 200];
    }

    /**
     * Ingest a multi-device batch uploaded by an authenticated Local Agent.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public function ingestAgentBatch(BiometricAgent $agent, array $events): array
    {
        $devices = BiometricDevice::where('agent_id', $agent->id)
            ->where('company_id', $agent->company_id)
            ->get();
        $byId = $devices->keyBy('id');
        $bySerial = $devices->keyBy('serial_number');

        $grouped = [];
        $unassignedResults = [];
        $rejected = 0;

        foreach ($events as $index => $item) {
            $localUuid = (string) ($item['local_uuid'] ?? $index);
            $device = !empty($item['device_id'])
                ? $byId->get((int) $item['device_id'])
                : (!empty($item['device_serial']) ? $bySerial->get(trim((string) $item['device_serial'])) : null);

            if (!$device) {
                $rejected++;
                $this->recordFailure((int) $agent->company_id, null, $agent->id, null, PunchSource::LocalAgent, $item, 'unassigned_device', 'Device is not assigned to this agent.');
                $unassignedResults[] = ['local_uuid' => $localUuid, 'status' => 'rejected', 'reason' => 'Device is not assigned to this agent.'];
                continue;
            }

            if (!$device->is_active) {
                $rejected++;
                $this->recordFailure((int) $agent->company_id, $device->id, $agent->id, null, PunchSource::LocalAgent, $item, 'inactive_device', 'Device is inactive.');
                $unassignedResults[] = ['local_uuid' => $localUuid, 'status' => 'rejected', 'reason' => 'Device is inactive.'];
                continue;
            }

            $grouped[$device->id][] = $item;
        }

        $accepted = 0;
        $duplicate = 0;
        $unmatched = 0;
        $attendanceUpdated = 0;
        $allResults = $unassignedResults;

        foreach ($grouped as $deviceId => $deviceEvents) {
            /** @var BiometricDevice $device */
            $device = $byId->get($deviceId);
            $res = $this->ingestBatchForDevice($device, $deviceEvents, PunchSource::LocalAgent, $agent, trigger: 'agent');
            $accepted += $res['accepted'];
            $duplicate += $res['duplicate'];
            $unmatched += $res['unmatched'];
            $rejected += $res['rejected'];
            $attendanceUpdated += $res['attendance_updated'];
            $allResults = array_merge($allResults, $res['results']);
        }

        $agent->forceFill(['last_sync_at' => now()])->saveQuietly();

        return [
            'accepted' => $accepted,
            'duplicate' => $duplicate,
            'unmatched' => $unmatched,
            'rejected' => $rejected,
            'attendance_updated' => $attendanceUpdated,
            'results' => $allResults,
        ];
    }

    /**
     * Ingest a batch of attendance events for a single authenticated device and
     * record a device-wise BiometricSyncRun history entry.
     *
     * @param array<int, array<string, mixed>> $events
     */
    public function ingestBatchForDevice(
        BiometricDevice $device,
        array $events,
        PunchSource $source,
        ?BiometricAgent $agent = null,
        string $trigger = 'system',
        bool $recordSyncRun = true,
    ): array {
        $run = $recordSyncRun
            ? $this->runs->start($device, SyncAction::LogSync, ['source' => $source->value, 'batch_size' => count($events)], null, $trigger)
            : null;

        $accepted = 0;
        $duplicate = 0;
        $rejected = 0;
        $unmatched = 0;
        $results = [];
        $recalcTargets = [];
        $companyUserIds = $this->employees->companyUserIds((int) $device->company_id);
        $deviceTz = $device->timezoneName();

        foreach ($events as $index => $item) {
            $localUuid = (string) ($item['local_uuid'] ?? $item['idempotency_key'] ?? $index);
            $idemKey = !empty($item['local_uuid']) ? (string) $item['local_uuid'] : (!empty($item['idempotency_key']) ? (string) $item['idempotency_key'] : null);
            $origLogId = isset($item['original_device_log_id']) && trim((string) $item['original_device_log_id']) !== ''
                ? mb_substr(trim((string) $item['original_device_log_id']), 0, 100)
                : (isset($item['device_log_id']) && trim((string) $item['device_log_id']) !== '' ? mb_substr(trim((string) $item['device_log_id']), 0, 100) : null);

            $deviceUserId = trim((string) ($item['device_user_id'] ?? $item['employee_code'] ?? $item['pin'] ?? ''));
            $punchedAtRaw = trim((string) ($item['punched_at'] ?? $item['log_datetime'] ?? ''));

            if ($deviceUserId === '' || $punchedAtRaw === '') {
                $rejected++;
                $this->recordFailure((int) $device->company_id, $device->id, $agent?->id, $run?->id, $source, $item, 'invalid_payload', 'Missing device_user_id/employee_code or punched_at.');
                $results[] = ['local_uuid' => $localUuid, 'status' => 'rejected', 'reason' => 'Missing device_user_id or punched_at.'];
                continue;
            }

            $parsed = $this->parsePunchTimestamp($punchedAtRaw, $deviceTz);
            if ($parsed === null) {
                $rejected++;
                $this->recordFailure((int) $device->company_id, $device->id, $agent?->id, $run?->id, $source, $item, 'invalid_timestamp', "Invalid punched_at timestamp '{$punchedAtRaw}'.");
                $results[] = ['local_uuid' => $localUuid, 'status' => 'rejected', 'reason' => 'Invalid punched_at timestamp.'];
                continue;
            }

            [$utcTime, $localTime] = $parsed;
            $dedupeHash = BiometricPunch::computeDedupeHash((int) $device->company_id, $device->id, $deviceUserId, $utcTime->format('Y-m-d H:i:s'));

            // Multi-layer duplicate detection before insert
            $existing = $this->findDuplicate($device->id, $dedupeHash, $origLogId, $idemKey);
            if ($existing) {
                $duplicate++;
                $results[] = [
                    'local_uuid' => $localUuid,
                    'status' => 'duplicate',
                    'punch_id' => $existing->id,
                    'attendance_record_id' => $existing->attendance_record_id,
                ];
                continue;
            }

            $employee = $this->processor->resolveEmployee($device, $deviceUserId);
            $resolvedShift = $employee
                ? $this->processor->resolveShiftAndDateForPunch($employee, $localTime, $companyUserIds)
                : ['shift' => null, 'attendance_date' => $localTime->toDateString()];
            $attendanceDate = $resolvedShift['attendance_date'];

            $punchType = PunchType::fromRaw($item['punch_type'] ?? $item['punch_state'] ?? null);
            $verifyMethod = VerificationMethod::fromRaw($item['verification_method'] ?? $item['verify_mode'] ?? null);

            $rawMeta = array_filter([
                'local_uuid' => $idemKey,
                'raw_state' => $item['punch_state'] ?? $item['punch_type'] ?? null,
                'raw_verify' => $item['verify_mode'] ?? $item['verification_method'] ?? null,
                'raw_timestamp' => $punchedAtRaw,
                'work_code' => $item['work_code'] ?? null,
                'agent_id' => $agent?->id,
                'extra' => is_array($item['raw_metadata'] ?? null) ? $item['raw_metadata'] : null,
            ], fn ($v) => $v !== null && $v !== '');

            try {
                $punch = BiometricPunch::create([
                    'company_id' => $device->company_id,
                    'device_id' => $device->id,
                    'employee_id' => $employee?->id,
                    'user_id' => $employee?->user_id,
                    'device_user_id' => $deviceUserId,
                    'punched_at_utc' => $utcTime->format('Y-m-d H:i:s'),
                    'punched_at_local' => $localTime->format('Y-m-d H:i:s'),
                    'device_timezone' => $deviceTz,
                    'punch_type' => $punchType,
                    'verification_method' => $verifyMethod,
                    'original_device_log_id' => $origLogId,
                    'idempotency_key' => $idemKey,
                    'dedupe_hash' => $dedupeHash,
                    'source' => $source,
                    'raw_metadata' => $rawMeta ?: null,
                    'synced_at' => now(),
                    'sync_run_id' => $run?->id,
                    'attendance_date' => $attendanceDate,
                    'processing_status' => $employee ? PunchProcessingStatus::Pending : PunchProcessingStatus::UnmatchedEmployee,
                    'processing_note' => $employee ? null : "No employee mapped for device user ID '{$deviceUserId}'.",
                    'processed_at' => $employee ? null : now(),
                ]);
            } catch (QueryException $e) {
                if ($this->isUniqueViolation($e)) {
                    $duplicate++;
                    $dup = $this->findDuplicate($device->id, $dedupeHash, $origLogId, $idemKey);
                    $results[] = ['local_uuid' => $localUuid, 'status' => 'duplicate', 'punch_id' => $dup?->id];
                    continue;
                }

                $rejected++;
                $this->recordFailure((int) $device->company_id, $device->id, $agent?->id, $run?->id, $source, $item, 'db_error', $e->getMessage());
                $results[] = ['local_uuid' => $localUuid, 'status' => 'rejected', 'reason' => 'Database error while storing punch.'];
                continue;
            }

            $this->mirrorLegacyLog($device, $punch, $employee);

            if ($employee) {
                $recalcTargets["{$employee->id}|{$attendanceDate}"] = [$employee, $attendanceDate];
            } else {
                $unmatched++;
                $this->recordFailure(
                    (int) $device->company_id,
                    $device->id,
                    $agent?->id,
                    $run?->id,
                    $source,
                    $item,
                    'unmatched_employee',
                    "No employee mapped for device user ID '{$deviceUserId}'. Map the employee and retry.",
                    $punch->id
                );
            }

            $accepted++;
            $results[] = [
                'local_uuid' => $localUuid,
                'status' => 'accepted',
                'punch_id' => $punch->id,
                'matched_employee_id' => $employee?->id,
                'attendance_date' => $attendanceDate,
            ];
        }

        // Recalculate daily attendance once per affected (employee, attendance_date)
        $attendanceUpdated = 0;
        foreach ($recalcTargets as [$employee, $attendanceDate]) {
            try {
                if ($this->processor->processEmployeeDate($employee, $attendanceDate, (int) $device->company_id)) {
                    $attendanceUpdated++;
                }
            } catch (\Throwable $e) {
                $this->activity->error($device, 'attendance_processing_error', $e->getMessage(), [
                    'employee_id' => $employee->id,
                    'attendance_date' => $attendanceDate,
                ], $run);
            }
        }

        if ($accepted > 0) {
            $this->activity->logsReceived($device, $accepted);
        }

        if ($run) {
            $run->forceFill([
                'total_items' => count($events),
                'success_items' => $accepted + $duplicate,
                'failed_items' => $rejected + $unmatched,
            ])->save();

            $runStatus = match (true) {
                $rejected === 0 && $unmatched === 0 => SyncStatus::Success,
                ($accepted + $duplicate) === 0 => SyncStatus::Failed,
                default => SyncStatus::Partial,
            };

            $message = sprintf(
                '%d accepted, %d duplicate, %d unmatched, %d rejected (%d attendance record(s) updated).',
                $accepted,
                $duplicate,
                $unmatched,
                $rejected,
                $attendanceUpdated
            );

            $this->runs->finish($run, $runStatus, $message, [
                'accepted' => $accepted,
                'duplicate' => $duplicate,
                'unmatched' => $unmatched,
                'rejected' => $rejected,
                'attendance_updated' => $attendanceUpdated,
            ], $runStatus === SyncStatus::Failed ? $message : null);
        }

        return [
            'sync_run_id' => $run?->id,
            'accepted' => $accepted,
            'duplicate' => $duplicate,
            'unmatched' => $unmatched,
            'rejected' => $rejected,
            'attendance_updated' => $attendanceUpdated,
            'results' => $results,
        ];
    }

    /**
     * Parse either a timezone-aware ISO-8601 timestamp or a naive device wall-clock timestamp.
     *
     * @return array{0: Carbon, 1: Carbon}|null [utc, local]
     */
    public function parsePunchTimestamp(string $raw, string $deviceTimezone): ?array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        try {
            if (preg_match('/^\d{14}$/', $raw)) {
                $local = Carbon::createFromFormat('YmdHis', $raw, $deviceTimezone);

                return [$local->copy()->setTimezone('UTC'), $local];
            }

            if (preg_match('/(Z|[+\-]\d{2}:?\d{2})$/i', $raw)) {
                $parsed = Carbon::parse($raw);

                return [$parsed->copy()->setTimezone('UTC'), $parsed->copy()->setTimezone($deviceTimezone)];
            }

            $local = Carbon::parse($raw, $deviceTimezone);

            return [$local->copy()->setTimezone('UTC'), $local];
        } catch (\Throwable) {
            return null;
        }
    }

    private function findDuplicate(int $deviceId, string $dedupeHash, ?string $origLogId, ?string $idemKey): ?BiometricPunch
    {
        if ($origLogId !== null) {
            $byOrig = BiometricPunch::where('device_id', $deviceId)->where('original_device_log_id', $origLogId)->first();
            if ($byOrig) {
                return $byOrig;
            }
        }

        if ($idemKey !== null) {
            $byIdem = BiometricPunch::where('device_id', $deviceId)->where('idempotency_key', $idemKey)->first();
            if ($byIdem) {
                return $byIdem;
            }
        }

        return BiometricPunch::where('dedupe_hash', $dedupeHash)->first();
    }

    private function recordFailure(
        int $companyId,
        ?int $deviceId,
        ?int $agentId,
        ?int $syncRunId,
        PunchSource $source,
        array $payload,
        string $errorCode,
        string $errorMessage,
        ?int $punchId = null,
    ): BiometricSyncFailure {
        $baseDelay = max(30, (int) config('biometric.sync.retry_base_delay_seconds', 60));

        return BiometricSyncFailure::create([
            'company_id' => $companyId,
            'device_id' => $deviceId,
            'agent_id' => $agentId,
            'sync_run_id' => $syncRunId,
            'source' => $source,
            'idempotency_key' => !empty($payload['local_uuid']) ? mb_substr((string) $payload['local_uuid'], 0, 100) : null,
            'device_user_id' => !empty($payload['device_user_id'] ?? $payload['employee_code'] ?? $payload['pin'] ?? null)
                ? mb_substr((string) ($payload['device_user_id'] ?? $payload['employee_code'] ?? $payload['pin']), 0, 50)
                : null,
            'original_device_log_id' => !empty($payload['original_device_log_id'] ?? $payload['device_log_id'] ?? null)
                ? mb_substr((string) ($payload['original_device_log_id'] ?? $payload['device_log_id']), 0, 100)
                : null,
            'punched_at_raw' => !empty($payload['punched_at'] ?? $payload['log_datetime'] ?? null)
                ? mb_substr((string) ($payload['punched_at'] ?? $payload['log_datetime']), 0, 64)
                : null,
            'error_code' => $errorCode,
            'error_message' => mb_substr($errorMessage, 0, 2000),
            'payload' => $payload,
            'status' => SyncFailureStatus::PendingRetry,
            'retry_count' => 0,
            'next_retry_at' => now()->addSeconds($baseDelay),
            'last_attempted_at' => now(),
            'resolved_punch_id' => $punchId,
        ]);
    }

    private function mirrorLegacyLog(BiometricDevice $device, BiometricPunch $punch, ?\App\Models\Employee $employee): void
    {
        try {
            $canonicalCode = ($employee && !empty($employee->biometric_emp_id))
                ? (string) $employee->biometric_emp_id
                : $punch->device_user_id;

            BiometricAttendanceLog::firstOrCreate(
                [
                    'employee_code' => $canonicalCode,
                    'log_datetime' => $punch->punched_at_local->format('Y-m-d H:i:s'),
                    'device_sn' => $device->serial_number,
                ],
                [
                    'employee_id' => $employee?->user_id,
                    'log_date' => $punch->punched_at_local->format('Y-m-d'),
                    'log_time' => $punch->punched_at_local->format('H:i:s'),
                    'downloaded_at' => $punch->synced_at,
                    'punch_state' => $punch->punch_type === PunchType::Out ? 'Check Out' : 'Check In',
                    'is_synced' => $employee !== null,
                    'created_by' => $employee?->created_by ?? $device->company_id,
                ]
            );
        } catch (\Throwable) {
            // Legacy mirror must never block canonical punch ingestion.
        }
    }

    private function isUniqueViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());
        $driverCode = (int) ($e->errorInfo[1] ?? 0);

        return $sqlState === '23000' || $driverCode === 1062;
    }
}
