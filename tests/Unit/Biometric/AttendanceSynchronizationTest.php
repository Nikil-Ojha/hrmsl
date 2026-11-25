<?php

use App\Biometric\Enums\PunchType;
use App\Biometric\Enums\VerificationMethod;
use App\Biometric\Services\AttendanceProcessor;
use App\Biometric\Services\PunchSynchronizationService;
use App\Models\BiometricPunch;
use App\Models\Shift;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

it('normalizes vendor punch states and verification methods', function () {
    expect(PunchType::fromRaw('0'))->toBe(PunchType::In)
        ->and(PunchType::fromRaw('Check Out'))->toBe(PunchType::Out)
        ->and(PunchType::fromRaw('1'))->toBe(PunchType::Out)
        ->and(PunchType::fromRaw(''))->toBe(PunchType::Unknown)
        ->and(VerificationMethod::fromRaw('1'))->toBe(VerificationMethod::Fingerprint)
        ->and(VerificationMethod::fromRaw('15'))->toBe(VerificationMethod::Face)
        ->and(VerificationMethod::fromRaw('4'))->toBe(VerificationMethod::Card)
        ->and(VerificationMethod::fromRaw('0'))->toBe(VerificationMethod::Pin)
        ->and(VerificationMethod::fromRaw('face_recognition'))->toBe(VerificationMethod::Face);
});

it('converts naive device wall-clock and ISO timestamps accurately across timezones', function () {
    $sync = app(PunchSynchronizationService::class);

    // Naive wall-clock in IST (+05:30) -> UTC should be -5h30m
    [$utc1, $local1] = $sync->parsePunchTimestamp('2026-11-20 09:05:00', 'Asia/Kolkata');
    expect($local1->format('Y-m-d H:i:s'))->toBe('2026-11-20 09:05:00')
        ->and($utc1->format('Y-m-d H:i:s'))->toBe('2026-11-20 03:35:00');

    // 14-digit hardware format in IST
    [$utc2, $local2] = $sync->parsePunchTimestamp('20261120090500', 'Asia/Kolkata');
    expect($utc2->format('Y-m-d H:i:s'))->toBe('2026-11-20 03:35:00')
        ->and($local2->format('Y-m-d H:i:s'))->toBe('2026-11-20 09:05:00');

    // Explicit UTC timestamp converted to IST local time
    [$utc3, $local3] = $sync->parsePunchTimestamp('2026-11-20T03:35:00Z', 'Asia/Kolkata');
    expect($utc3->format('Y-m-d H:i:s'))->toBe('2026-11-20 03:35:00')
        ->and($local3->format('Y-m-d H:i:s'))->toBe('2026-11-20 09:05:00');

    expect($sync->parsePunchTimestamp('not-a-date', 'UTC'))->toBeNull();
});

it('attributes early-morning punches to the previous shift day for night shifts', function () {
    $processor = app(AttendanceProcessor::class);

    $dayShift = new Shift();
    $dayShift->forceFill(['start_time' => '09:00:00', 'end_time' => '18:00:00', 'is_night_shift' => false]);

    $nightShift = new Shift();
    $nightShift->forceFill(['start_time' => '22:00:00', 'end_time' => '06:00:00', 'is_night_shift' => true]);

    expect($processor->resolveAttendanceDate(Carbon::parse('2026-11-21 06:15:00'), $dayShift))->toBe('2026-11-21')
        ->and($processor->resolveAttendanceDate(Carbon::parse('2026-11-20 22:05:00'), $nightShift))->toBe('2026-11-20')
        ->and($processor->resolveAttendanceDate(Carbon::parse('2026-11-21 06:15:00'), $nightShift))->toBe('2026-11-20')
        ->and($processor->resolveAttendanceDate(Carbon::parse('2026-11-21 21:50:00'), $nightShift))->toBe('2026-11-21');
});

it('computes stable deduplication hashes per company, device, user and UTC instant', function () {
    $h1 = BiometricPunch::computeDedupeHash(2, 10, '1001', '2026-11-20 03:35:00');
    $h2 = BiometricPunch::computeDedupeHash(2, 10, '1001', '2026-11-20 03:35:00');
    $h3 = BiometricPunch::computeDedupeHash(2, 11, '1001', '2026-11-20 03:35:00');

    expect($h1)->toBe($h2)->not->toBe($h3);
});
