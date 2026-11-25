<?php

use App\Biometric\Enums\CommandType;
use App\Biometric\Protocols\FkWebAdapter;
use App\Biometric\Protocols\NativeAdapter;
use App\Biometric\Protocols\ZkAdmsAdapter;
use App\Biometric\Support\UnsupportedCommandException;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use Illuminate\Support\Carbon;

uses(Tests\TestCase::class);

function adapterDevice(): BiometricDevice
{
    $device = new BiometricDevice();
    $device->forceFill(['id' => 1, 'company_id' => 1, 'timezone' => 'Asia/Kolkata', 'serial_number' => 'SN1', 'device_identifier' => 'DEV-1']);

    return $device;
}

function adapterCommand(CommandType $type, array $payload = [], int $id = 42): BiometricCommand
{
    $command = new BiometricCommand();
    $command->forceFill(['id' => $id, 'type' => $type, 'payload' => $payload]);

    return $command;
}

afterEach(fn () => Carbon::setTestNow());

it('packs ZKTeco time like the device firmware', function () {
    $expected = ((26 * 12 * 31 + 10 * 31 + 19) * 86400) + (11 * 60 + 30) * 60 + 15;

    expect(ZkAdmsAdapter::encodeTime(Carbon::create(2026, 11, 20, 11, 30, 15)))->toBe($expected);
});

it('encodes ZK ADMS commands in the device timezone', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-20 06:00:00', 'UTC'));
    $a = new ZkAdmsAdapter();
    $d = adapterDevice();

    expect($a->encode(adapterCommand(CommandType::SyncTime), $d)->body)
        ->toBe('SET OPTIONS DateTime=' . ZkAdmsAdapter::encodeTime(Carbon::parse('2026-11-20 11:30:00')))
        ->and($a->encode(adapterCommand(CommandType::UserUpsert, ['pin' => '1001', 'name' => "Jane\tDoe"]), $d)->body)
        ->toStartWith("DATA UPDATE USERINFO PIN=1001\tName=Jane Doe\tPri=0")
        ->and($a->encode(adapterCommand(CommandType::FetchLogs, ['from' => '2026-11-19T00:00:00Z', 'to' => '2026-11-20T00:00:00Z']), $d)->body)
        ->toBe("DATA QUERY ATTLOG StartTime=2026-11-19 05:30:00\tEndTime=2026-11-20 05:30:00")
        ->and($a->encode(adapterCommand(CommandType::UserDelete, ['pin' => '7']), $d)->body)->toBe('DATA DELETE USERINFO PIN=7')
        ->and($a->encode(adapterCommand(CommandType::QueryUsers), $d)->body)->toBe('DATA QUERY USERINFO')
        ->and($a->encode(adapterCommand(CommandType::ClearLogs), $d)->body)->toBe('CLEAR LOG')
        ->and($a->encode(adapterCommand(CommandType::Reboot), $d)->body)->toBe('REBOOT');
});

it('parses ZK INFO replies and user uploads', function () {
    $info = ZkAdmsAdapter::parseInfo("ID=900000001&Return=0&CMD=INFO\n~DeviceName=iFace302\nFWVersion=Ver 6.60\nUserCount=12\nFPCount=20\nTransactionCount=345");
    expect($info)->toMatchArray(['model' => 'iFace302', 'firmware_version' => 'Ver 6.60', 'user_count' => '12', 'fingerprint_count' => '20', 'log_count' => '345']);

    $users = ZkAdmsAdapter::parseUsers("USER PIN=1001\tName=Jane\tPri=14\tPasswd=\tCard=0\tGrp=1\nOPLOG 4\t0\t2026-11-20 10:00:00\nUSER PIN=1002\tName=John\tCard=12345");
    expect($users)->toHaveCount(2)
        ->and($users[0]->pin)->toBe('1001')->and($users[0]->privilege)->toBe(14)->and($users[0]->card)->toBeNull()
        ->and($users[1]->card)->toBe('12345');

    expect(ZkAdmsAdapter::isSuccessCode('0'))->toBeTrue()
        ->and(ZkAdmsAdapter::isSuccessCode('25'))->toBeTrue()
        ->and(ZkAdmsAdapter::isSuccessCode('-1002'))->toBeFalse()
        ->and(ZkAdmsAdapter::isSuccessCode(''))->toBeFalse();
});

it('encodes native commands as a versioned JSON envelope with an offset wire id', function () {
    $json = json_decode((new NativeAdapter())->encode(adapterCommand(CommandType::Reboot, [], 7), adapterDevice())->body, true);

    expect($json)->toMatchArray(['version' => '1.0', 'id' => 900000007, 'type' => 'reboot'])
        ->and(BiometricCommand::idFromWireId(900000007))->toBe(7)
        ->and(BiometricCommand::idFromWireId(15))->toBeNull();
});

it('does not encode FKWeb commands that have no known code', function () {
    (new FkWebAdapter())->encode(adapterCommand(CommandType::ClearLogs), adapterDevice());
})->throws(UnsupportedCommandException::class);
