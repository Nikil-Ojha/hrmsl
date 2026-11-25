<?php

use App\Biometric\Enums\PunchPairingMode;
use App\Biometric\Enums\PunchType;
use App\Biometric\Services\AttendanceProcessor;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Services\ShiftResolver;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\BiometricPunch;
use App\Models\Shift;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

function makeNightShift(): Shift
{
    $shift = new Shift([
        'name' => 'Night Shift',
        'start_time' => '22:00:00',
        'end_time' => '06:00:00',
        'break_start_time' => '01:00:00',
        'break_end_time' => '01:30:00',
        'break_duration' => 30,
        'grace_period' => 10,
        'is_night_shift' => true,
        'status' => 'active',
    ]);

    return $shift;
}

function makeDayShift(): Shift
{
    return new Shift([
        'name' => 'Day Shift',
        'start_time' => '09:00:00',
        'end_time' => '18:00:00',
        'break_start_time' => '13:00:00',
        'break_end_time' => '14:00:00',
        'break_duration' => 60,
        'grace_period' => 10,
        'is_night_shift' => false,
        'status' => 'active',
    ]);
}

function makePolicy(array $overrides = []): AttendancePolicy
{
    return new AttendancePolicy(array_merge([
        'name' => 'Standard Policy',
        'late_arrival_grace' => 15,
        'early_departure_grace' => 15,
        'overtime_rate_per_hour' => 25.00,
        'punch_pairing_mode' => 'first_last',
        'duplicate_punch_window_sec' => 60,
        'missing_clock_out_grace_hours' => 4,
        'auto_detect_shift' => false,
        'status' => 'active',
    ], $overrides));
}

function makePunch(int $id, string $localDateTime, PunchType $type = PunchType::Unknown): BiometricPunch
{
    $dt = Carbon::parse($localDateTime, 'UTC');
    $p = new BiometricPunch([
        'punched_at_local' => $dt,
        'punched_at_utc' => $dt,
        'punch_type' => $type,
    ]);
    $p->id = $id;

    return $p;
}

it('calculates overnight shift hours and post-midnight break overlap accurately', function () {
    $record = new AttendanceRecord([
        'date' => '2026-11-20',
        'clock_in' => '22:00:00',
        'clock_out' => '06:00:00',
    ]);
    $record->setRelation('shift', makeNightShift());
    $record->setRelation('attendancePolicy', makePolicy());

    $hours = $record->calculateTotalHours();
    $record->checkLateArrival();
    $record->checkEarlyDeparture();

    expect($hours)->toBe(7.5)
        ->and((float) $record->break_hours)->toBe(0.5)
        ->and($record->is_late)->toBeFalse()
        ->and($record->late_minutes)->toBe(0)
        ->and($record->is_early_departure)->toBeFalse()
        ->and($record->early_departure_minutes)->toBe(0);
});

it('detects post-midnight late arrival on an overnight shift', function () {
    $record = new AttendanceRecord([
        'date' => '2026-11-20',
        'clock_in' => '00:15:00',
        'clock_out' => '06:00:00',
    ]);
    $record->setRelation('shift', makeNightShift());
    $record->setRelation('attendancePolicy', makePolicy(['late_arrival_grace' => 15]));

    $record->calculateTotalHours();
    $record->checkLateArrival();

    expect($record->is_late)->toBeTrue()
        ->and($record->late_minutes)->toBe(135) // 22:00 -> 00:15 = 2h15m
        ->and((float) $record->total_hours)->toBe(5.25); // 5h45m minus 30m break (01:00-01:30)
});

it('detects pre-midnight early departure on an overnight shift', function () {
    $record = new AttendanceRecord([
        'date' => '2026-11-20',
        'clock_in' => '22:00:00',
        'clock_out' => '23:30:00',
    ]);
    $record->setRelation('shift', makeNightShift());
    $record->setRelation('attendancePolicy', makePolicy(['early_departure_grace' => 15]));

    $record->calculateTotalHours();
    $record->checkEarlyDeparture();

    expect($record->is_early_departure)->toBeTrue()
        ->and($record->early_departure_minutes)->toBe(390) // 23:30 -> 06:00 next day = 6h30m
        ->and((float) $record->total_hours)->toBe(1.5)
        ->and((float) $record->break_hours)->toBe(0.0); // left before 01:00 break
});

it('respects policy grace period and falls back to shift grace when policy is absent', function () {
    // Within 15m policy grace -> not late
    $within = new AttendanceRecord(['date' => '2026-11-20', 'clock_in' => '22:15:00']);
    $within->setRelation('shift', makeNightShift());
    $within->setRelation('attendancePolicy', makePolicy(['late_arrival_grace' => 15]));
    $within->checkLateArrival();
    expect($within->is_late)->toBeFalse()->and($within->late_minutes)->toBe(0);

    // 1 minute past 15m policy grace -> late by 16m
    $past = new AttendanceRecord(['date' => '2026-11-20', 'clock_in' => '22:16:00']);
    $past->setRelation('shift', makeNightShift());
    $past->setRelation('attendancePolicy', makePolicy(['late_arrival_grace' => 15]));
    $past->checkLateArrival();
    expect($past->is_late)->toBeTrue()->and($past->late_minutes)->toBe(16);

    // No policy -> falls back to shift.grace_period (10m)
    $noPolicyOk = new AttendanceRecord(['date' => '2026-11-20', 'clock_in' => '09:10:00']);
    $noPolicyOk->setRelation('shift', makeDayShift());
    $noPolicyOk->setRelation('attendancePolicy', null);
    $noPolicyOk->checkLateArrival();
    expect($noPolicyOk->is_late)->toBeFalse();

    $noPolicyLate = new AttendanceRecord(['date' => '2026-11-20', 'clock_in' => '09:12:00']);
    $noPolicyLate->setRelation('shift', makeDayShift());
    $noPolicyLate->setRelation('attendancePolicy', null);
    $noPolicyLate->checkLateArrival();
    expect($noPolicyLate->is_late)->toBeTrue()->and($noPolicyLate->late_minutes)->toBe(12);
});

it('pairs multiple punches accurately across FirstLast, InOutPairs, and DeviceState modes', function () {
    $processor = new AttendanceProcessor(new EmployeeSelector(), new ShiftResolver());

    $four = collect([
        makePunch(1, '2026-11-20 09:00:00', PunchType::In),
        makePunch(2, '2026-11-20 13:00:00', PunchType::Out),
        makePunch(3, '2026-11-20 14:00:00', PunchType::In),
        makePunch(4, '2026-11-20 18:00:00', PunchType::Out),
    ]);

    // FirstLast
    $fl = $processor->pairPunches($four, PunchPairingMode::FirstLast);
    expect($fl['in_punch']->id)->toBe(1)
        ->and($fl['out_punch']->id)->toBe(4)
        ->and($fl['paired_work_hours'])->toBeNull()
        ->and($fl['has_unpaired_trailing'])->toBeFalse();

    // InOutPairs (4 punches: 4h + 4h work = 8.0h, 1.0h break)
    $io = $processor->pairPunches($four, PunchPairingMode::InOutPairs);
    expect($io['in_punch']->id)->toBe(1)
        ->and($io['out_punch']->id)->toBe(4)
        ->and($io['paired_work_hours'])->toBe(8.0)
        ->and($io['paired_break_hours'])->toBe(1.0)
        ->and($io['has_unpaired_trailing'])->toBeFalse();

    // InOutPairs with odd count (3 punches -> trailing unpaired punch flagged)
    $three = $four->take(3);
    $ioOdd = $processor->pairPunches($three, PunchPairingMode::InOutPairs);
    expect($ioOdd['out_punch']->id)->toBe(2)
        ->and($ioOdd['paired_work_hours'])->toBe(4.0)
        ->and($ioOdd['has_unpaired_trailing'])->toBeTrue();

    // DeviceState picks explicit IN & OUT, and falls back to last when all UNKNOWN
    $mixed = collect([
        makePunch(10, '2026-11-20 08:55:00', PunchType::Unknown),
        makePunch(11, '2026-11-20 09:00:00', PunchType::In),
        makePunch(12, '2026-11-20 17:30:00', PunchType::Out),
        makePunch(13, '2026-11-20 17:45:00', PunchType::Unknown),
    ]);
    $ds = $processor->pairPunches($mixed, PunchPairingMode::DeviceState);
    expect($ds['in_punch']->id)->toBe(11)
        ->and($ds['out_punch']->id)->toBe(12)
        ->and($ds['has_unpaired_trailing'])->toBeFalse();

    // Single punch -> null out_punch + unpaired flag
    $single = $processor->pairPunches($four->take(1), PunchPairingMode::FirstLast);
    expect($single['in_punch']->id)->toBe(1)
        ->and($single['out_punch'])->toBeNull()
        ->and($single['has_unpaired_trailing'])->toBeTrue();
});

it('resolves overnight attendance dates and missing clock-out overdue windows', function () {
    $resolver = new ShiftResolver();
    $night = makeNightShift();
    $day = makeDayShift();

    // Overnight shift: 21:45 belongs to 2026-11-20; 00:30 & 06:15 on 2026-11-21 belong to 2026-11-20!
    expect($resolver->attendanceDateForShift(Carbon::parse('2026-11-20 21:45:00'), $night))->toBe('2026-11-20')
        ->and($resolver->attendanceDateForShift(Carbon::parse('2026-11-21 00:30:00'), $night))->toBe('2026-11-20')
        ->and($resolver->attendanceDateForShift(Carbon::parse('2026-11-21 06:15:00'), $night))->toBe('2026-11-20');

    // Missing clock-out overdue check (day shift 09:00-18:00 + 4h grace = 22:00)
    expect($resolver->isMissingClockOutOverdue('2026-11-20', $day, 4, Carbon::parse('2026-11-20 21:00:00')))->toBeFalse()
        ->and($resolver->isMissingClockOutOverdue('2026-11-20', $day, 4, Carbon::parse('2026-11-20 22:05:00')))->toBeTrue();

    // Missing clock-out overdue check (night shift 22:00-06:00 next day + 4h grace = 10:00 next day)
    expect($resolver->isMissingClockOutOverdue('2026-11-20', $night, 4, Carbon::parse('2026-11-21 09:30:00')))->toBeFalse()
        ->and($resolver->isMissingClockOutOverdue('2026-11-20', $night, 4, Carbon::parse('2026-11-21 10:05:00')))->toBeTrue();
});
