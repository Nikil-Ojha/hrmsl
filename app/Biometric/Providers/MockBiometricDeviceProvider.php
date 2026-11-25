<?php

namespace App\Biometric\Providers;

use App\Biometric\Data\ConnectionResult;
use App\Biometric\Data\DeviceInformation;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationResult;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Providers\Mock\MockDeviceStore;
use App\Biometric\Providers\Mock\MockPunchFactory;
use App\Biometric\Services\ConnectivityChecker;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\DeviceUserDirectory;
use App\Biometric\Support\ConnectivityResult;
use App\Models\BiometricDevice;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Simulated device for development and automated tests. Every operation
 * completes synchronously against an in-cache device state. No network
 * traffic and no hardware commands are involved.
 *
 * Use `php artisan biometric:mock-device` to inject punches or toggle the
 * simulated device offline.
 */
class MockBiometricDeviceProvider extends AbstractProvider
{
    public function __construct(
        BiometricDevice $device,
        ConnectivityChecker $connectivity,
        DeviceActivityRecorder $activity,
        DeviceUserDirectory $directory,
        protected MockDeviceStore $store,
    ) {
        parent::__construct($device, $connectivity, $activity, $directory);
    }

    public static function definition(): ProviderDefinition
    {
        return new ProviderDefinition(
            key: 'mock',
            label: 'Mock Device (development only)',
            description: 'Simulated device for development and testing. Operations complete instantly against simulated device memory.',
            communication: ProviderDefinition::SIMULATED,
            manufacturers: ['Simulator'],
            authTypes: [AuthType::None],
            operations: array_fill_keys(array_map(fn (Operation $o) => $o->value, Operation::cases()), SupportLevel::Supported),
            developmentOnly: true,
        );
    }

    public static function enabled(): bool
    {
        return (bool) config('biometric.providers.mock.enabled', false);
    }

    public function unavailableReason(Operation $operation): ?string
    {
        if (!static::enabled()) {
            return __('The mock provider is disabled in this environment (BIOMETRIC_MOCK_ENABLED).');
        }

        return parent::unavailableReason($operation);
    }

    /* ------------------------------------------------------------------ */

    public function connect(): ConnectionResult
    {
        if ($this->isOffline()) {
            return new ConnectionResult(false, ProviderDefinition::SIMULATED, __('Simulated device is offline.'));
        }

        $this->activity->heartbeat($this->device, 'mock');

        return new ConnectionResult(true, ProviderDefinition::SIMULATED, __('Connected to simulated device.'));
    }

    public function testConnection(): ConnectivityResult
    {
        $online = !$this->isOffline();
        if ($online) {
            $this->activity->heartbeat($this->device, 'mock');
        }

        return new ConnectivityResult($online, $online ? __('Simulated device is online.') : __('Simulated device is offline.'), [
            ['name' => 'simulator', 'label' => 'Simulator', 'passed' => $online, 'detail' => $online ? 'Responded instantly.' : 'Device toggled offline via biometric:mock-device.'],
        ]);
    }

    public function getDeviceInformation(bool $refresh = false): OperationResult
    {
        if ($r = $this->precheck(Operation::DeviceInformation)) {
            return $r;
        }

        $state = $this->state();
        $info = [
            'serial_number' => $this->device->serial_number,
            'manufacturer' => 'Simulator',
            'model' => $this->device->model ?: 'MOCK-1',
            'firmware_version' => 'mock-1.0.0',
            'user_count' => count($state['users']),
            'fingerprint_count' => 0,
            'face_count' => 0,
            'log_count' => count($state['logs']),
        ];
        $this->storeInformation($info);

        return OperationResult::completed(__('Device information retrieved.'), DeviceInformation::fromArray($info + ['reported_at' => now()->toIso8601String()]));
    }

    public function getUsers(): OperationResult
    {
        if ($r = $this->precheck(Operation::GetUsers)) {
            return $r;
        }

        $users = array_map(fn ($u) => new DeviceUser($u['pin'], $u['name'], $u['card'] ?? null, (int) $u['privilege']), array_values($this->state()['users']));
        $this->directory->replaceAll($this->device, $users, 'device_report');

        return OperationResult::completed(__(':count user(s) on device.', ['count' => count($users)]), $users);
    }

    public function enrollEmployee(DeviceUser $user): OperationResult
    {
        return $this->upsertUser(Operation::EnrollEmployee, $user, __('Employee enrolled on simulated device.'));
    }

    public function updateEmployee(DeviceUser $user): OperationResult
    {
        return $this->upsertUser(Operation::UpdateEmployee, $user, __('Employee updated on simulated device.'));
    }

    public function deleteEmployee(string $pin): OperationResult
    {
        if ($r = $this->precheck(Operation::DeleteEmployee)) {
            return $r;
        }

        $existed = false;
        $this->store->update($this->device->id, function ($s) use ($pin, &$existed) {
            $existed = isset($s['users'][$pin]);
            unset($s['users'][$pin]);

            return $s;
        });
        $this->recordUserRemoved($pin);

        return $existed
            ? OperationResult::completed(__('User removed from simulated device.'))
            : OperationResult::failed(__('User :pin does not exist on the device.', ['pin' => $pin]));
    }

    public function getAttendanceLogs(?CarbonInterface $from = null, ?CarbonInterface $to = null): OperationResult
    {
        if ($r = $this->precheck(Operation::GetAttendanceLogs)) {
            return $r;
        }

        $punches = MockPunchFactory::toRecords($this->state()['logs'], $from, $to);
        if ($punches) {
            $this->activity->logsReceived($this->device, count($punches));
        }

        return OperationResult::completed(__(':count punch(es) in range.', ['count' => count($punches)]), $punches);
    }

    public function clearAttendanceLogs(): OperationResult
    {
        if ($r = $this->precheck(Operation::ClearAttendanceLogs)) {
            return $r;
        }

        $count = count($this->state()['logs']);
        $this->store->update($this->device->id, fn ($s) => ['logs' => []] + $s);

        return OperationResult::completed(__(':count punch(es) cleared from simulated device.', ['count' => $count]));
    }

    public function syncDeviceTime(?CarbonInterface $time = null): OperationResult
    {
        if ($r = $this->precheck(Operation::SyncDeviceTime)) {
            return $r;
        }

        $target = $time ?? Carbon::now();
        $offset = $target->getTimestamp() - Carbon::now()->getTimestamp();
        $this->store->update($this->device->id, fn ($s) => ['clock_offset' => $offset] + $s);

        return OperationResult::completed(__('Simulated device clock set to :time.', [
            'time' => Carbon::now()->addSeconds($offset)->setTimezone($this->device->timezoneName())->format('Y-m-d H:i:s T'),
        ]));
    }

    public function restartDevice(): OperationResult
    {
        if ($r = $this->precheck(Operation::RestartDevice)) {
            return $r;
        }

        $this->store->update($this->device->id, fn ($s) => ['booted_at' => now()->toIso8601String()] + $s);

        return OperationResult::completed(__('Simulated device restarted.'));
    }

    public function setupHints(): array
    {
        return [
            ['label' => 'Inject a punch', 'value' => "php artisan biometric:mock-device {$this->device->id} punch --pin=<code>"],
            ['label' => 'Simulate outage', 'value' => "php artisan biometric:mock-device {$this->device->id} offline"],
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Development helpers (not part of the interface)                     */
    /* ------------------------------------------------------------------ */

    public function simulatePunch(string $pin, ?CarbonInterface $at = null, string $state = '0', string $verify = 'fingerprint'): array
    {
        $entry = ['pin' => $pin, 'punched_at' => ($at ?? now())->toIso8601String(), 'state' => $state, 'verify' => $verify];
        $this->store->update($this->device->id, function ($s) use ($entry) {
            $s['logs'][] = $entry;

            return $s;
        });
        $this->activity->heartbeat($this->device, 'mock');

        return $entry;
    }

    public function setOffline(bool $offline): void
    {
        $this->store->update($this->device->id, fn ($s) => ['offline' => $offline] + $s);
        if ($offline) {
            $this->activity->markOffline($this->device, 'Simulated device switched offline.');
        } else {
            $this->activity->heartbeat($this->device, 'mock');
        }
    }

    public function reset(): void
    {
        $this->store->forget($this->device->id);
    }

    public function state(): array
    {
        return $this->store->get($this->device->id);
    }

    /* ------------------------------------------------------------------ */

    private function upsertUser(Operation $op, DeviceUser $user, string $message): OperationResult
    {
        if ($r = $this->precheck($op)) {
            return $r;
        }

        $this->store->update($this->device->id, function ($s) use ($user) {
            $s['users'][$user->pin] = ['pin' => $user->pin, 'name' => $user->name, 'card' => $user->card, 'privilege' => $user->privilege];

            return $s;
        });
        $this->recordUserOnDevice($user, 'sync');

        return OperationResult::completed($message, $user);
    }

    private function precheck(Operation $op): ?OperationResult
    {
        if ($guard = $this->guard($op)) {
            return $guard;
        }

        return $this->isOffline() ? OperationResult::failed(__('Simulated device is offline.')) : null;
    }

    private function isOffline(): bool
    {
        return (bool) ($this->state()['offline'] ?? false);
    }
}
