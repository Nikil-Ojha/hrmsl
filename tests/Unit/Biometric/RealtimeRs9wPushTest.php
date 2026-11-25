<?php

use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\ConnectionType;
use App\Biometric\Enums\DeviceType;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

beforeEach(function () {
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
});

afterEach(function () {
    DB::rollBack();
});

function makeRs9wTestDevice(string $protocol = 'fkweb'): BiometricDevice
{
    $company = User::where('type', 'company')->first() ?? User::first();
    $uid = Str::upper(Str::random(6));

    return BiometricDevice::create([
        'company_id' => $company->id,
        'created_by' => $company->id,
        'name' => "RS9W {$uid}",
        'serial_number' => "RSS{$uid}",
        'device_identifier' => "DEV-{$uid}",
        'manufacturer' => 'Realtime',
        'model' => 'RS 9W',
        'device_type' => DeviceType::cases()[0],
        'protocol' => $protocol,
        'connection_type' => ConnectionType::Wifi,
        'timezone' => 'Asia/Kolkata',
        'auth_type' => AuthType::None,
        'is_active' => true,
        'connection_status' => ConnectionStatus::Unknown,
    ]);
}

it('accepts FKWeb heartbeats and realtime_glog punches across push endpoints without CSRF errors', function () {
    $device = makeRs9wTestDevice('fkweb');

    // Heartbeat on /hdata.aspx
    $hb = $this->call('POST', '/hdata.aspx', [], [], [], [
        'HTTP_REQUEST_CODE' => 'receive_cmd',
        'HTTP_DEV_ID' => $device->serial_number,
        'HTTP_TRANS_ID' => '101',
    ]);

    $hb->assertOk();
    expect($hb->headers->get('response_code'))->toBe('OK');

    $device->refresh();
    expect($device->connection_status)->toBe(ConnectionStatus::Online)
        ->and($device->last_seen_at)->not->toBeNull();

    // Punch log on /index.php (previously lacked CSRF exemption)
    $payload = json_encode([
        'user_id' => '9001',
        'io_time' => '20261125093000',
        'io_mode' => 0,
        'verify_mode' => 1,
    ]);

    $punchResp = $this->call('POST', '/index.php', [], [], [], [
        'HTTP_REQUEST_CODE' => 'realtime_glog',
        'HTTP_DEV_ID' => $device->serial_number,
        'HTTP_TRANS_ID' => '102',
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $payload);

    $punchResp->assertOk();

    $punch = BiometricPunch::where('device_id', $device->id)->where('device_user_id', '9001')->first();
    expect($punch)->not->toBeNull()
        ->and($punch->device_timezone)->toBe('Asia/Kolkata')
        ->and($punch->punched_at_local->format('Y-m-d H:i:s'))->toBe('2026-11-25 09:30:00');
});

it('handles ADMS/iClock GET handshake at root and /iclock/cdata with device timezone offset', function () {
    $device = makeRs9wTestDevice('zk_adms');

    $rootHandshake = $this->get('/?SN=' . $device->serial_number . '&options=all');
    $rootHandshake->assertOk();
    expect($rootHandshake->getContent())
        ->toContain('GET OPTION FROM: ' . $device->serial_number)
        ->toContain('TimeZone=330');

    $punchResp = $this->call(
        'POST',
        '/iclock/cdata.aspx?SN=' . $device->serial_number . '&table=ATTLOG',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'text/plain'],
        "9002\t2026-11-25 10:15:00\t0\t1\t0\n"
    );

    $punchResp->assertOk();
    expect($punchResp->getContent())->toBe('OK: 1');

    $punch = BiometricPunch::where('device_id', $device->id)->where('device_user_id', '9002')->first();
    expect($punch)->not->toBeNull()
        ->and($punch->punched_at_local->format('Y-m-d H:i:s'))->toBe('2026-11-25 10:15:00');
});

it('ingests punches into biometric_punches when pushed via OnlineRealsoft /api/attendance-log', function () {
    $device = makeRs9wTestDevice('generic_http');
    $token = env('BIOMETRIC_API_TOKEN');

    $headers = $token ? ['Authorization' => 'Bearer ' . $token] : [];
    $resp = $this->postJson('/api/attendance-log', [
        'employee_code' => '9003',
        'log_datetime' => '2026-11-25 11:00:00',
        'log_time' => '11:00:00',
        'device_sn' => $device->serial_number,
    ], $headers);

    $resp->assertOk()->assertJson(['status' => true]);

    $punch = BiometricPunch::where('device_id', $device->id)->where('device_user_id', '9003')->first();
    expect($punch)->not->toBeNull()
        ->and($punch->punched_at_local->format('Y-m-d H:i:s'))->toBe('2026-11-25 11:00:00');
});
