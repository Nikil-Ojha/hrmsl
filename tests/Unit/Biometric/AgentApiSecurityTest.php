<?php

use App\Biometric\Services\AgentApiAuditLogger;
use App\Http\Middleware\EnforceBiometricHttps;
use App\Http\Requests\Api\Biometric\AgentAttendanceSyncRequest;
use App\Http\Requests\Api\Biometric\AgentDeviceStatusRequest;
use App\Http\Requests\Api\Biometric\AgentEnrollmentSyncRequest;
use Illuminate\Http\Request;

uses(Tests\TestCase::class);

it('recursively scrubs secrets, tokens and biometric templates from API audit metadata', function () {
    $logger = new AgentApiAuditLogger();

    $scrubbed = $logger->scrub([
        'batch_size' => 12,
        'access_token' => 'agt_secret_12345',
        'hmac_secret' => 'deadbeef',
        'provisioning_token' => 'prov_xyz',
        'nested' => [
            'template_data' => 'BASE64_TEMPLATE_BYTES',
            'credentials' => ['password' => 'hunter2'],
            'safe_flag' => true,
        ],
    ]);

    expect($scrubbed['batch_size'])->toBe(12)
        ->and($scrubbed['access_token'])->toBe('[REDACTED]')
        ->and($scrubbed['hmac_secret'])->toBe('[REDACTED]')
        ->and($scrubbed['provisioning_token'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['template_data'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['credentials'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['safe_flag'])->toBeTrue();
});

it('rejects plain HTTP requests with 426 when require_https is enabled and allows HTTPS', function () {
    config(['biometric.agent.require_https' => true]);
    $mw = new EnforceBiometricHttps();

    $httpReq = Request::create('http://hrms.example.com/api/biometric/agent/register', 'POST');
    $httpResp = $mw->handle($httpReq, fn () => response()->json(['ok' => true]));
    expect($httpResp->getStatusCode())->toBe(426)
        ->and($httpReq->attributes->get('biometric_audit_outcome'))->toBe('https_rejected');

    $httpsReq = Request::create('https://hrms.example.com/api/biometric/agent/register', 'POST');
    $httpsResp = $mw->handle($httpsReq, fn () => response()->json(['ok' => true]));
    expect($httpsResp->getStatusCode())->toBe(200);
});

it('strips spoofed tenant and employee identifiers in attendance and enrollment sync requests', function () {
    $attReq = AgentAttendanceSyncRequest::create('/api/biometric/attendance/sync', 'POST', [
        'events' => [[
            'local_uuid' => 'u-1',
            'device_id' => 5,
            'device_user_id' => '1001',
            'employee_id' => 999999,
            'company_id' => 777,
            'user_id' => 888,
            'punched_at' => '2026-11-20T09:00:00Z',
        ]],
    ]);
    $ref = new ReflectionMethod($attReq, 'prepareForValidation');
    $ref->setAccessible(true);
    $ref->invoke($attReq);

    $logs = $attReq->input('logs');
    expect($logs)->toHaveCount(1)
        ->and($logs[0])->not->toHaveKeys(['employee_id', 'company_id', 'user_id'])
        ->and($logs[0]['device_user_id'])->toBe('1001');

    $enrReq = AgentEnrollmentSyncRequest::create('/api/biometric/enrollment/sync', 'POST', [
        'device_id' => 5,
        'enrollments' => [[
            'device_user_id' => '1001',
            'employee_id' => 999999,
            'company_id' => 777,
            'modality' => 'fingerprint',
        ]],
    ]);
    $ref2 = new ReflectionMethod($enrReq, 'prepareForValidation');
    $ref2->setAccessible(true);
    $ref2->invoke($enrReq);

    expect($enrReq->input('enrollments.0'))->not->toHaveKeys(['employee_id', 'company_id'])
        ->and($enrReq->input('enrollments.0.device_user_id'))->toBe('1001');

    $statusReq = AgentDeviceStatusRequest::create('/api/biometric/devices/status', 'POST', [
        'device_id' => 5,
        'online' => true,
    ]);
    $ref3 = new ReflectionMethod($statusReq, 'prepareForValidation');
    $ref3->setAccessible(true);
    $ref3->invoke($statusReq);

    expect($statusReq->input('devices'))->toHaveCount(1)
        ->and($statusReq->input('devices.0.device_id'))->toBe(5);
});
