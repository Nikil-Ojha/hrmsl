<?php

use App\Biometric\Services\BiometricAuditLogger;

uses(Tests\TestCase::class);

it('recursively redacts secrets, tokens and biometric templates from audit diffs', function () {
    $logger = new BiometricAuditLogger();

    $scrubbed = $logger->scrub([
        'name' => 'Gate 1',
        'ip_address' => '192.168.1.50',
        'credentials' => ['password' => 'topsecret', 'api_key' => 'k_123'],
        'hmac_secret' => 'deadbeef',
        'access_token' => 'agt_xxx',
        'nested' => [
            'template_data' => 'RAW_TEMPLATE_PAYLOAD',
            'fingerprint_image' => 'PNG_BYTES',
            'is_active' => true,
        ],
    ]);

    expect($scrubbed['name'])->toBe('Gate 1')
        ->and($scrubbed['ip_address'])->toBe('192.168.1.50')
        ->and($scrubbed['credentials'])->toBe('[REDACTED]')
        ->and($scrubbed['hmac_secret'])->toBe('[REDACTED]')
        ->and($scrubbed['access_token'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['template_data'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['fingerprint_image'])->toBe('[REDACTED]')
        ->and($scrubbed['nested']['is_active'])->toBeTrue();
});

it('exposes all biometric audit categories for UI filtering', function () {
    $values = array_column(BiometricAuditLogger::categoryOptions(), 'value');

    expect($values)->toContain(
        BiometricAuditLogger::CAT_DEVICE,
        BiometricAuditLogger::CAT_ENROLLMENT,
        BiometricAuditLogger::CAT_SYNC,
        BiometricAuditLogger::CAT_COMMAND,
        BiometricAuditLogger::CAT_ATTENDANCE_CORRECTION,
        BiometricAuditLogger::CAT_SETTINGS,
        BiometricAuditLogger::CAT_AGENT,
    );
});
