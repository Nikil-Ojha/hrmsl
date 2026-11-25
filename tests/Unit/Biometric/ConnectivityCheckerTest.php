<?php

use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\ConnectionType;
use App\Biometric\Enums\DeviceType;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\Services\ConnectivityChecker;
use App\Biometric\Services\DeviceApiKeyManager;
use App\Biometric\Services\DeviceGateway;
use App\Biometric\Services\DeviceManager;
use App\Biometric\Support\ConnectivityResult;
use App\Biometric\Support\NetworkGuard;
use App\Models\BiometricAttendanceLog;
use App\Models\BiometricDevice;
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

function makeConnectivityTestDevice(int $companyId, array $overrides = []): BiometricDevice
{
    $uid = Str::upper(Str::random(6));

    return BiometricDevice::create(array_merge([
        'company_id' => $companyId,
        'created_by' => $companyId,
        'name' => "Test Device {$uid}",
        'serial_number' => "SN-{$uid}",
        'device_identifier' => "DEV-{$uid}",
        'device_type' => DeviceType::cases()[0],
        'protocol' => 'zk_adms',
        'connection_type' => ConnectionType::Lan,
        'ip_address' => '192.168.1.224',
        'port' => 4370,
        'auth_type' => AuthType::None,
        'is_active' => true,
        'connection_status' => ConnectionStatus::Unknown,
    ], $overrides));
}

it('recognizes an already-online device that recently pushed attendance logs for its company', function () {
    $company = User::where('type', 'company')->first() ?? User::first();
    $device = makeConnectivityTestDevice($company->id, [
        'protocol' => 'fkweb',
        'last_seen_at' => null,
    ]);

    // Simulate a punch already received via legacy intake before/during device registration
    BiometricAttendanceLog::create([
        'company_id' => null,
        'employee_code' => 'EMP9001',
        'log_datetime' => now(),
        'log_date' => now()->toDateString(),
        'log_time' => now()->format('H:i:s'),
        'device_sn' => $device->serial_number,
        'created_by' => $company->id,
    ]);

    config(['biometric.connectivity.allow_private_probe' => false]);

    $run = app(DeviceManager::class)->checkConnectivity($device);
    $device->refresh();

    expect($run->status)->toBe(SyncStatus::Success)
        ->and($device->effective_connection_status)->toBe('online')
        ->and($device->last_seen_at)->not->toBeNull();
});

it('does not leak recent attendance logs across companies during connectivity checks', function () {
    $company = User::where('type', 'company')->first() ?? User::first();
    $otherCompanyId = $company->id + 99999;

    $device = makeConnectivityTestDevice($company->id, [
        'protocol' => 'fkweb',
        'last_seen_at' => null,
    ]);

    // Log belongs to a different company
    BiometricAttendanceLog::create([
        'company_id' => null,
        'employee_code' => 'EMP9002',
        'log_datetime' => now(),
        'log_date' => now()->toDateString(),
        'log_time' => now()->format('H:i:s'),
        'device_sn' => $device->serial_number,
        'created_by' => $otherCompanyId,
    ]);

    config(['biometric.connectivity.allow_private_probe' => false]);

    $run = app(DeviceManager::class)->checkConnectivity($device);

    expect($run->status)->toBe(SyncStatus::Failed)
        ->and($run->message)->toContain('private LAN IP')
        ->and($run->message)->toContain('Setup tab');
});

it('persists last_seen_at when a live TCP probe succeeds so effective status stays online', function () {
    $company = User::where('type', 'company')->first() ?? User::first();
    $device = makeConnectivityTestDevice($company->id, [
        'ip_address' => '203.0.113.50',
        'port' => null, // Blank port should also fall back to provider defaultPort
        'last_seen_at' => null,
    ]);

    $stubChecker = new class(new NetworkGuard()) extends ConnectivityChecker {
        public ?int $seenDefaultPort = null;

        public function check(BiometricDevice $device, bool $probeAllowedByDriver = true, ?int $defaultPort = null): ConnectivityResult
        {
            $this->seenDefaultPort = $defaultPort;

            return new ConnectivityResult(true, 'Device TCP port is reachable (online).', [
                ['name' => 'heartbeat', 'label' => 'Device heartbeat', 'passed' => false, 'detail' => 'No heartbeat yet.'],
                ['name' => 'tcp', 'label' => 'TCP probe', 'passed' => true, 'detail' => 'Connected in 5 ms.'],
            ]);
        }
    };

    app()->instance(ConnectivityChecker::class, $stubChecker);

    $run = app(DeviceManager::class)->checkConnectivity($device);
    $device->refresh();

    expect($stubChecker->seenDefaultPort)->toBe(4370)
        ->and($run->status)->toBe(SyncStatus::Success)
        ->and($device->last_seen_at)->not->toBeNull()
        ->and($device->effective_connection_status)->toBe('online');
});

it('resolves devices by serial_number or device_identifier in DeviceGateway', function () {
    $company = User::where('type', 'company')->first() ?? User::first();
    $device = makeConnectivityTestDevice($company->id, [
        'protocol' => 'generic_http',
    ]);

    $gateway = app(DeviceGateway::class);

    expect($gateway->resolve($device->serial_number)?->id)->toBe($device->id)
        ->and($gateway->resolve($device->device_identifier)?->id)->toBe($device->id);
});

it('marks a native device online via POST /api/biometric/device/v1/heartbeat', function () {
    config(['biometric.agent.require_https' => false]);

    $company = User::where('type', 'company')->first() ?? User::first();
    $device = makeConnectivityTestDevice($company->id, [
        'protocol' => 'native',
        'auth_type' => AuthType::ApiKey,
        'last_seen_at' => null,
    ]);

    $plainKey = app(DeviceApiKeyManager::class)->issue($device);

    $res = $this->withHeaders(['X-Device-Key' => $plainKey])
        ->postJson('/api/biometric/device/v1/heartbeat', [
            'firmware_version' => 'v2.4.1',
        ]);

    $res->assertOk()->assertJsonPath('data.connection_status', 'online');

    $device->refresh();
    expect($device->effective_connection_status)->toBe('online')
        ->and($device->firmware_version)->toBe('v2.4.1');

    $run = app(DeviceManager::class)->checkConnectivity($device);
    expect($run->status)->toBe(SyncStatus::Success);
});
