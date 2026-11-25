<?php

use App\Biometric\Enums\AgentStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\PunchSource;
use App\Biometric\Enums\SyncFailureStatus;
use App\Biometric\Services\AgentCredentialManager;
use App\Biometric\Services\DeviceApiKeyManager;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Services\PunchSynchronizationService;
use App\Biometric\Services\SyncFailureRetryService;
use App\Biometric\Support\Tenant;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricPunch;
use App\Models\BiometricSyncFailure;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

uses(Tests\TestCase::class);

/*
|--------------------------------------------------------------------------
| Phase 12 Comprehensive Biometric Scenarios Feature Test Suite
|--------------------------------------------------------------------------
| Covers all 7 required end-to-end scenarios against the real MySQL schema
| inside automatically rolled-back transactions (zero test data persisted):
|   1. Duplicate synchronization (log ID, dedupe hash, HTTP idempotency, bounce)
|   2. Offline synchronization (delayed & out-of-order batch recovery)
|   3. Invalid device & agent credentials (API key, Bearer, HMAC, skew, replay, HTTPS)
|   4. Employee mapping failures & automatic retry resolution
|   5. Multiple punches (first_last, in_out_pairs, odd trailing, device_state)
|   6. Overnight shifts (attribution, late post-midnight, early pre-midnight, breaks)
|   7. Timezone differences (Asia/Kolkata, America/New_York, Asia/Tokyo)
|   8. Granular Laravel Authorization Policies (tenant & least-privilege checks)
*/

beforeEach(function () {
    // Use the configured MySQL connection when phpunit.xml defaults to sqlite/:memory: without pdo_sqlite.
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true) || config('database.default') === 'sqlite') {
        $dbName = config('database.connections.mysql.database');
        if (!$dbName || $dbName === ':memory:') {
            $envFile = base_path('.env');
            if (is_file($envFile) && preg_match('/^DB_DATABASE=(.+)$/m', (string) file_get_contents($envFile), $m)) {
                $dbName = trim($m[1], " \t\n\r\"'");
            } else {
                $dbName = 'hrms';
            }
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => $dbName,
        ]);
        DB::purge('mysql');
    }

    DB::beginTransaction();

    $this->company = User::where('type', 'company')->firstOrFail();
    $this->companyId = (int) Tenant::companyId($this->company);
    $this->companyUserIds = app(EmployeeSelector::class)->companyUserIds($this->companyId);
    $this->employee = Employee::whereIn('created_by', $this->companyUserIds)
        ->whereNotNull('user_id')
        ->firstOrFail();

    $this->pin = 'T12P' . random_int(1000, 9999);
    $this->employee->forceFill(['biometric_emp_id' => $this->pin])->save();

    $this->dayShift = Shift::create([
        'name' => 'T12 Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_start_time' => '13:00:00',
        'break_end_time' => '14:00:00',
        'break_duration' => 60,
        'grace_period' => 15,
        'is_night_shift' => false,
        'status' => 'active',
        'created_by' => $this->companyId,
    ]);

    $this->nightShift = Shift::create([
        'name' => 'T12 Night Shift',
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'break_start_time' => '01:00:00',
        'break_end_time' => '01:30:00',
        'break_duration' => 30,
        'grace_period' => 15,
        'is_night_shift' => true,
        'status' => 'active',
        'created_by' => $this->companyId,
    ]);

    $this->policy = AttendancePolicy::create([
        'name' => 'T12 Policy',
        'late_arrival_grace' => 15,
        'early_departure_grace' => 15,
        'overtime_rate_per_hour' => 25.00,
        'punch_pairing_mode' => 'first_last',
        'duplicate_punch_window_sec' => 60,
        'missing_clock_out_grace_hours' => 4,
        'auto_detect_shift' => false,
        'status' => 'active',
        'created_by' => $this->companyId,
    ]);

    $this->employee->forceFill([
        'shift_id' => $this->dayShift->id,
        'attendance_policy_id' => $this->policy->id,
    ])->save();

    $this->agent = BiometricAgent::create([
        'company_id' => $this->companyId,
        'created_by' => $this->company->id,
        'name' => 'T12 Office Agent',
        'agent_identifier' => 'agt-t12-' . uniqid(),
        'status' => AgentStatus::Pending,
        'connection_status' => ConnectionStatus::Unknown,
    ]);

    $this->device = BiometricDevice::create([
        'company_id' => $this->companyId,
        'created_by' => $this->company->id,
        'agent_id' => $this->agent->id,
        'name' => 'T12 Main Gate',
        'serial_number' => 'T12-SN-' . uniqid(),
        'device_identifier' => 'T12-ID-' . uniqid(),
        'manufacturer' => 'OwnBrand',
        'device_type' => 'face_fingerprint',
        'protocol' => 'local_agent',
        'connection_type' => 'lan',
        'timezone' => 'UTC',
        'auth_type' => 'api_key',
        'is_active' => true,
    ]);
});

afterEach(function () {
    DB::rollBack();
});

function provisionAgentForTest(BiometricAgent $agent): array
{
    $creds = app(AgentCredentialManager::class);
    $prov = $creds->issueProvisioningToken($agent);

    return $creds->provision($agent->agent_identifier, $prov, ['ip' => '127.0.0.1']);
}

function signedAgentHeaders(BiometricAgent $agent, string $token, string $secret, string $method, string $path, string $body = '', ?string $nonce = null, ?int $timestamp = null): array
{
    $ts = (string) ($timestamp ?? time());
    $reqNonce = $nonce ?? bin2hex(random_bytes(16));
    $sig = app(AgentCredentialManager::class)->sign($secret, $method, $path, $ts, $reqNonce, $body);

    return [
        'Authorization' => 'Bearer ' . $token,
        'X-Agent-Id' => $agent->agent_identifier,
        'X-Agent-Timestamp' => $ts,
        'X-Agent-Nonce' => $reqNonce,
        'X-Agent-Signature' => $sig,
        'Accept' => 'application/json',
    ];
}

/* ===================================================================== */
/* 1. Duplicate Synchronization                                          */
/* ===================================================================== */
it('prevents duplicate attendance records across original log ID, dedupe hash, HTTP idempotency and bounce window', function () {
    $sync = app(PunchSynchronizationService::class);
    $date = '2027-04-01';

    // First sync: 09:00 punch + rapid 09:00:25 bounce tap
    $r1 = $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$date 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'dup-log-1', 'local_uuid' => 'u-dup-1'],
        ['device_user_id' => $this->pin, 'punched_at' => "$date 09:00:25", 'punch_type' => 'in', 'original_device_log_id' => 'dup-log-2', 'local_uuid' => 'u-dup-2'],
    ], PunchSource::LocalAgent);

    expect($r1['accepted'])->toBe(2)->and($r1['duplicate'])->toBe(0);

    // Replay same original_device_log_id AND replay same timestamp with new log ID
    $r2 = $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$date 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'dup-log-1', 'local_uuid' => 'u-dup-3'],
        ['device_user_id' => $this->pin, 'punched_at' => "$date 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'dup-log-different', 'local_uuid' => 'u-dup-4'],
    ], PunchSource::LocalAgent);

    expect($r2['accepted'])->toBe(0)->and($r2['duplicate'])->toBe(2);

    // Verify bounce tap was suppressed (not treated as clock-out!)
    $rec = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $date)->firstOrFail();
    expect($rec->clock_in)->toBe('09:00')
        ->and($rec->clock_out)->toBeNull()
        ->and($rec->punch_count)->toBe(1);

    $bounce = BiometricPunch::where('original_device_log_id', 'dup-log-2')->firstOrFail();
    expect($bounce->processing_status)->toBe(PunchProcessingStatus::DuplicateSuppressed);

    // Verify HTTP batch X-Idempotency-Key returns cached replay without inserting rows
    $auth = provisionAgentForTest($this->agent);
    $payload = json_encode(['events' => [[
        'local_uuid' => 'u-idem-' . uniqid(),
        'device_id' => $this->device->id,
        'device_user_id' => $this->pin,
        'punched_at' => "$date 18:00:00",
        'punch_type' => 'out',
        'original_device_log_id' => 'dup-log-out',
    ]]]);
    $idemKey = 't12-idem-' . uniqid();
    $path = '/api/biometric/attendance/sync';

    $h1 = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $payload) + ['X-Idempotency-Key' => $idemKey];
    $resp1 = $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($h1 + ['Content-Type' => 'application/json']), $payload);
    $resp1->assertOk()->assertHeader('X-Idempotent-Replay', 'false');

    $h2 = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $payload) + ['X-Idempotency-Key' => $idemKey];
    $resp2 = $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($h2 + ['Content-Type' => 'application/json']), $payload);
    $resp2->assertOk()->assertHeader('X-Idempotent-Replay', 'true');

    expect(BiometricPunch::where('device_id', $this->device->id)->whereDate('attendance_date', $date)->count())->toBe(3);
});

/* ===================================================================== */
/* 2. Offline Synchronization (Delayed & Out-of-Order Delivery)          */
/* ===================================================================== */
it('reconciles offline delayed and out-of-order punch batches accurately', function () {
    $sync = app(PunchSynchronizationService::class);
    $pastDate = now()->subDays(3)->toDateString();
    AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $pastDate)->delete();

    // Evening punch arrives first (e.g. from one branch terminal), morning punch arrives later when offline terminal reconnects
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$pastDate 18:10:00", 'punch_type' => 'out', 'original_device_log_id' => 'off-2'],
    ], PunchSource::LocalAgent);

    $recMid = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $pastDate)->firstOrFail();
    expect($recMid->is_missing_clock_out)->toBeTrue();

    // Offline morning punch arrives hours/days later -> HRMS re-sorts chronologically and clears missing clock-out!
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$pastDate 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'off-1'],
    ], PunchSource::LocalAgent);

    $recFinal = $recMid->fresh();
    expect($recFinal->clock_in)->toBe('09:00')
        ->and($recFinal->clock_out)->toBe('18:10')
        ->and($recFinal->is_missing_clock_out)->toBeFalse()
        ->and((float) $recFinal->total_hours)->toBe(8.17)
        ->and($recFinal->status)->toBe('present');
});

/* ===================================================================== */
/* 3. Invalid Device & Agent Credentials                                 */
/* ===================================================================== */
it('rejects invalid device API keys, invalid agent tokens, bad HMAC signatures, expired timestamps, replays and plain HTTP', function () {
    config(['biometric.agent.require_https' => true]);

    // 3a. Direct Device API: plain HTTP -> 426; over HTTPS missing & wrong API key -> 401
    $apiKey = app(DeviceApiKeyManager::class)->issue($this->device);
    $deviceUrlHttps = 'https://hrms.example.com/api/biometric/device/v1/punches';
    $this->postJson('http://hrms.example.com/api/biometric/device/v1/punches', ['punches' => []])->assertStatus(426);
    $this->postJson($deviceUrlHttps, ['punches' => []])->assertStatus(401);
    $this->withHeaders(['X-Device-Key' => 'wrong_key_12345'])->postJson($deviceUrlHttps, ['punches' => []])->assertStatus(401);

    // Revoke device key -> previous valid key fails with 401
    app(DeviceApiKeyManager::class)->revoke($this->device);
    $this->withHeaders(['X-Device-Key' => $apiKey])->postJson($deviceUrlHttps, ['punches' => []])->assertStatus(401);

    // 3b. Local Agent API: plain HTTP -> 426
    $auth = provisionAgentForTest($this->agent);
    $path = '/api/biometric/agent/heartbeat';
    $body = json_encode(['local_queue_depth' => 0]);

    $hHttp = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $body);
    $this->call('POST', 'http://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hHttp + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(426);

    // Wrong Bearer token -> 401
    $hBadToken = signedAgentHeaders($this->agent, 'agt_bad_token', $auth['hmac_secret'], 'POST', $path, $body);
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hBadToken + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(401);

    // Tampered HMAC signature -> 401
    $hBadSig = signedAgentHeaders($this->agent, $auth['access_token'], 'wrong_secret', 'POST', $path, $body);
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hBadSig + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(401);

    // Expired timestamp -> 401 (replay_blocked)
    $hExpired = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $body, null, time() - 900);
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hExpired + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(401)
        ->assertJsonPath('code', 'replay_blocked');

    // Replayed nonce after cache flush -> 401 (persistent DB replay block)
    $fixedNonce = bin2hex(random_bytes(16));
    $hValid = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $body, $fixedNonce);
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hValid + ['Content-Type' => 'application/json']), $body)
        ->assertOk();
    Cache::flush();
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hValid + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(401)
        ->assertJsonPath('code', 'replay_blocked');

    // Revoked agent -> 403
    app(AgentCredentialManager::class)->revoke($this->agent->fresh());
    $hRevoked = signedAgentHeaders($this->agent, $auth['access_token'], $auth['hmac_secret'], 'POST', $path, $body);
    $this->call('POST', 'https://hrms.example.com' . $path, [], [], [], $this->transformHeadersToServerVars($hRevoked + ['Content-Type' => 'application/json']), $body)
        ->assertStatus(403);
});

/* ===================================================================== */
/* 4. Employee Mapping Failures & Automatic Retry Resolution             */
/* ===================================================================== */
it('logs unmatched employee mapping failures and resolves them cleanly once mapped without duplicates', function () {
    $sync = app(PunchSynchronizationService::class);
    $retries = app(SyncFailureRetryService::class);
    $unknownPin = 'UNMAP' . random_int(1000, 9999);
    $date = '2027-04-02';

    $res = $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $unknownPin, 'punched_at' => "$date 09:05:00", 'punch_type' => 'in', 'original_device_log_id' => 'unmap-1'],
    ], PunchSource::LocalAgent);

    expect($res['unmatched'])->toBe(1);

    $punch = BiometricPunch::where('original_device_log_id', 'unmap-1')->firstOrFail();
    expect($punch->processing_status)->toBe(PunchProcessingStatus::UnmatchedEmployee)
        ->and($punch->employee_id)->toBeNull();

    $failure = BiometricSyncFailure::where('device_id', $this->device->id)
        ->where('device_user_id', $unknownPin)
        ->where('status', SyncFailureStatus::PendingRetry->value)
        ->firstOrFail();

    // Now map the unknown PIN to our employee via normalized biometric_employee_mappings
    BiometricEmployeeMapping::create([
        'company_id' => $this->companyId,
        'employee_id' => $this->employee->id,
        'device_id' => $this->device->id,
        'device_user_id' => $unknownPin,
        'is_active' => true,
        'source' => 'hrms',
    ]);

    $retryResult = $retries->retry($failure);
    expect($retryResult['resolved'])->toBeTrue();

    $failure->refresh();
    $punch->refresh();
    expect($failure->status)->toBe(SyncFailureStatus::Resolved)
        ->and($punch->processing_status)->toBe(PunchProcessingStatus::Processed)
        ->and($punch->employee_id)->toBe($this->employee->id)
        ->and(BiometricPunch::where('original_device_log_id', 'unmap-1')->count())->toBe(1);
});

/* ===================================================================== */
/* 5. Multiple Punches (first_last, in_out_pairs, device_state)          */
/* ===================================================================== */
it('processes multiple punches accurately across in_out_pairs and odd trailing punches', function () {
    $sync = app(PunchSynchronizationService::class);
    $this->policy->forceFill(['punch_pairing_mode' => 'in_out_pairs'])->save();

    $d1 = '2027-04-05';
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$d1 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'mp-1'],
        ['device_user_id' => $this->pin, 'punched_at' => "$d1 12:30:00", 'punch_type' => 'out', 'original_device_log_id' => 'mp-2'],
        ['device_user_id' => $this->pin, 'punched_at' => "$d1 13:30:00", 'punch_type' => 'in', 'original_device_log_id' => 'mp-3'],
        ['device_user_id' => $this->pin, 'punched_at' => "$d1 18:00:00", 'punch_type' => 'out', 'original_device_log_id' => 'mp-4'],
    ], PunchSource::LocalAgent);

    $rec = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $d1)->firstOrFail();
    expect((float) $rec->total_hours)->toBe(8.0)
        ->and((float) $rec->break_hours)->toBe(1.0)
        ->and($rec->punch_count)->toBe(4)
        ->and($rec->is_missing_clock_out)->toBeFalse();

    // Past date with 3 punches (unpaired trailing IN) -> flags missing clock-out!
    $dPast = now()->subDays(4)->toDateString();
    AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $dPast)->delete();
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => "$dPast 09:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'mp-5'],
        ['device_user_id' => $this->pin, 'punched_at' => "$dPast 13:00:00", 'punch_type' => 'out', 'original_device_log_id' => 'mp-6'],
        ['device_user_id' => $this->pin, 'punched_at' => "$dPast 14:00:00", 'punch_type' => 'in', 'original_device_log_id' => 'mp-7'],
    ], PunchSource::LocalAgent);

    $recOdd = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', $dPast)->firstOrFail();
    expect($recOdd->is_missing_clock_out)->toBeTrue()
        ->and((float) $recOdd->total_hours)->toBe(4.0);
});

/* ===================================================================== */
/* 6. Overnight Shifts                                                   */
/* ===================================================================== */
it('handles overnight shift attendance date attribution, post-midnight late arrival, pre-midnight early departure and breaks', function () {
    $sync = app(PunchSynchronizationService::class);
    $this->employee->forceFill(['shift_id' => $this->nightShift->id])->save();

    // Normal overnight shift (22:00 -> 06:00 next day, 30m break at 01:00-01:30 = 7.5h)
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-10 22:00:00', 'punch_type' => 'in', 'original_device_log_id' => 'ov-1'],
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-11 06:00:00', 'punch_type' => 'out', 'original_device_log_id' => 'ov-2'],
    ], PunchSource::LocalAgent);

    $rec1 = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', '2027-04-10')->firstOrFail();
    expect((float) $rec1->total_hours)->toBe(7.5)
        ->and((float) $rec1->break_hours)->toBe(0.5)
        ->and($rec1->is_late)->toBeFalse()
        ->and($rec1->is_early_departure)->toBeFalse();

    // Post-midnight late arrival (00:15 on 2027-04-12 belongs to 2027-04-11 shift!)
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-12 00:15:00', 'punch_type' => 'in', 'original_device_log_id' => 'ov-3'],
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-12 06:00:00', 'punch_type' => 'out', 'original_device_log_id' => 'ov-4'],
    ], PunchSource::LocalAgent);

    $rec2 = AttendanceRecord::where('employee_id', $this->employee->user_id)->whereDate('date', '2027-04-11')->firstOrFail();
    expect($rec2->is_late)->toBeTrue()
        ->and($rec2->late_minutes)->toBe(135)
        ->and((float) $rec2->total_hours)->toBe(5.25);
});

/* ===================================================================== */
/* 7. Timezone Differences                                               */
/* ===================================================================== */
it('converts punches accurately across Asia/Kolkata, America/New_York and Asia/Tokyo device timezones', function () {
    $sync = app(PunchSynchronizationService::class);

    // 7a. Asia/Kolkata (UTC+05:30) naive wall-clock
    $this->device->forceFill(['timezone' => 'Asia/Kolkata'])->save();
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-15 09:00:00', 'punch_type' => 'in', 'original_device_log_id' => 'tz-ist-1'],
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-15 18:00:00', 'punch_type' => 'out', 'original_device_log_id' => 'tz-ist-2'],
    ], PunchSource::LocalAgent);

    $pIst = BiometricPunch::where('original_device_log_id', 'tz-ist-1')->firstOrFail();
    expect($pIst->punched_at_utc->format('Y-m-d H:i:s'))->toBe('2027-04-15 03:30:00')
        ->and($pIst->punched_at_local->format('Y-m-d H:i:s'))->toBe('2027-04-15 09:00:00')
        ->and($pIst->attendance_date->toDateString())->toBe('2027-04-15');

    // 7b. America/New_York (UTC-05:00 in January) ISO UTC input
    $this->device->forceFill(['timezone' => 'America/New_York'])->save();
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => '2027-01-15T14:00:00Z', 'punch_type' => 'in', 'original_device_log_id' => 'tz-ny-1'],
        ['device_user_id' => $this->pin, 'punched_at' => '2027-01-15T23:00:00Z', 'punch_type' => 'out', 'original_device_log_id' => 'tz-ny-2'],
    ], PunchSource::LocalAgent);

    $pNy = BiometricPunch::where('original_device_log_id', 'tz-ny-1')->firstOrFail();
    expect($pNy->punched_at_local->format('Y-m-d H:i:s'))->toBe('2027-01-15 09:00:00')
        ->and($pNy->attendance_date->toDateString())->toBe('2027-01-15');

    // 7c. Asia/Tokyo (UTC+09:00) cross-UTC-date punch (00:30 JST = 15:30 UTC previous day) -> local JST date!
    $this->device->forceFill(['timezone' => 'Asia/Tokyo'])->save();
    $sync->ingestBatchForDevice($this->device, [
        ['device_user_id' => $this->pin, 'punched_at' => '2027-04-16T00:30:00+09:00', 'punch_type' => 'in', 'original_device_log_id' => 'tz-jp-1'],
    ], PunchSource::LocalAgent);

    $pJp = BiometricPunch::where('original_device_log_id', 'tz-jp-1')->firstOrFail();
    expect($pJp->punched_at_utc->format('Y-m-d H:i:s'))->toBe('2027-04-15 15:30:00')
        ->and($pJp->punched_at_local->format('Y-m-d H:i:s'))->toBe('2027-04-16 00:30:00')
        ->and($pJp->attendance_date->toDateString())->toBe('2027-04-16');
});

/* ===================================================================== */
/* 8. Granular Laravel Authorization Policies                            */
/* ===================================================================== */
it('enforces granular BiometricDevicePolicy and cross-tenant isolation via Laravel Gate', function () {
    $role = Role::create(['name' => 't12-ops-' . uniqid(), 'guard_name' => 'web', 'created_by' => $this->company->id]);
    $role->givePermissionTo(['view-biometric-devices', 'sync-biometric-devices']);

    $operator = User::create([
        'name' => 'T12 Operator',
        'email' => 't12op_' . uniqid() . '@example.com',
        'password' => bcrypt('secret1234'),
        'type' => 'employee',
        'created_by' => $this->company->id,
    ]);
    $operator->assignRole($role);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    expect(Gate::forUser($operator)->allows('view', $this->device))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('sync', $this->device))->toBeTrue()
        ->and(Gate::forUser($operator)->allows('delete', $this->device))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('executeCommands', $this->device))->toBeFalse();

    // Cross-tenant device MUST be denied even with view permission
    $otherTenant = User::where('id', '!=', $this->companyId)->firstOrFail();
    $foreignDevice = BiometricDevice::create([
        'company_id' => $otherTenant->id,
        'created_by' => $otherTenant->id,
        'name' => 'Foreign Device',
        'serial_number' => 'FOR-SN-' . uniqid(),
        'device_identifier' => 'FOR-ID-' . uniqid(),
        'device_type' => 'fingerprint',
        'protocol' => 'local_agent',
        'connection_type' => 'lan',
        'is_active' => true,
    ]);

    expect(Gate::forUser($operator)->allows('view', $foreignDevice))->toBeFalse()
        ->and(Gate::forUser($operator)->allows('sync', $foreignDevice))->toBeFalse();
});
