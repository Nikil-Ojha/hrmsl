<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\BiometricDeviceInterface;
use App\Biometric\Data\ConnectionResult;
use App\Biometric\Data\DeviceInformation;
use App\Biometric\Data\DeviceStatus;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationContext;
use App\Biometric\Data\OperationResult;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\CommandType;
use App\Biometric\Enums\EventLevel;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\Services\ConnectivityChecker;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\DeviceUserDirectory;
use App\Biometric\Support\ConnectivityResult;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\Employee;
use Carbon\CarbonInterface;

/**
 * Behaviour shared by all providers: capability gating, status, cached
 * device information, connectivity testing and acknowledgement handling.
 * Concrete providers only implement what is protocol specific.
 */
abstract class AbstractProvider implements BiometricDeviceInterface
{
    protected ?OperationContext $context = null;

    public function __construct(
        protected BiometricDevice $device,
        protected ConnectivityChecker $connectivity,
        protected DeviceActivityRecorder $activity,
        protected DeviceUserDirectory $directory,
    ) {
    }

    abstract public static function definition(): ProviderDefinition;

    public function device(): BiometricDevice
    {
        return $this->device;
    }

    public function withContext(OperationContext $context): static
    {
        $clone = clone $this;
        $clone->context = $context;

        return $clone;
    }

    /* ------------------------------------------------------------------ */
    /* Capability gating                                                   */
    /* ------------------------------------------------------------------ */

    public static function allowsUnverified(): bool
    {
        return (bool) config('biometric.providers.' . static::definition()->key . '.allow_unverified', false);
    }

    public function supports(Operation $operation): bool
    {
        return $this->unavailableReason($operation) === null;
    }

    public function unavailableReason(Operation $operation): ?string
    {
        $definition = static::definition();

        return match ($definition->support($operation)) {
            SupportLevel::Supported => null,
            SupportLevel::Unverified => static::allowsUnverified()
                ? null
                : __(':operation for :provider has not been verified on hardware and is disabled. An administrator can enable it after testing.', [
                    'operation' => $operation->label(),
                    'provider' => $definition->label,
                ]),
            SupportLevel::Unsupported => __('The :provider provider does not support :operation.', [
                'provider' => $definition->label,
                'operation' => strtolower($operation->label()),
            ]),
        };
    }

    /**
     * Returns a non-attempted result when the operation is not available.
     */
    protected function guard(Operation $operation): ?OperationResult
    {
        $reason = $this->unavailableReason($operation);
        if ($reason === null) {
            return null;
        }

        return static::definition()->support($operation) === SupportLevel::Unsupported
            ? OperationResult::unsupported($reason)
            : OperationResult::disabled($reason);
    }

    /* ------------------------------------------------------------------ */
    /* Operations with protocol-independent defaults                       */
    /* ------------------------------------------------------------------ */

    public function connect(): ConnectionResult
    {
        $fresh = $this->device->isHeartbeatFresh();

        return new ConnectionResult(
            $fresh,
            static::definition()->communication,
            $fresh
                ? __('Device is connected (last contact :time).', ['time' => $this->device->last_seen_at?->diffForHumans()])
                : __('The device opens the connection itself. Waiting for it to contact the server.')
        );
    }

    public function disconnect(): void
    {
        // Device-initiated (push) sessions are stateless HTTP requests: nothing to close.
    }

    public function testConnection(): ConnectivityResult
    {
        $definition = static::definition();

        return $this->connectivity->check(
            $this->device,
            $definition->communication !== ProviderDefinition::SIMULATED,
            $definition->defaultPort,
        );
    }

    public function getDeviceStatus(): DeviceStatus
    {
        $d = $this->device;

        return new DeviceStatus(
            connectionStatus: $d->effective_connection_status,
            isActive: (bool) $d->is_active,
            online: $d->effective_connection_status === 'online',
            lastSeenAt: $d->last_seen_at?->toIso8601String(),
            lastSeenIp: $d->last_seen_ip,
            pendingCommands: BiometricCommand::where('device_id', $d->id)->pending()->count(),
            lastError: $d->last_error,
            lastErrorAt: $d->last_error_at?->toIso8601String(),
        );
    }

    public function getDeviceInformation(bool $refresh = false): OperationResult
    {
        if ($guard = $this->guard(Operation::DeviceInformation)) {
            return $guard;
        }

        return OperationResult::completed(__('Last information reported by the device.'), $this->cachedInformation());
    }

    public function getUsers(): OperationResult
    {
        return $this->guard(Operation::GetUsers) ?? OperationResult::unsupported('Not implemented.');
    }

    public function enrollEmployee(DeviceUser $user): OperationResult
    {
        return $this->guard(Operation::EnrollEmployee) ?? OperationResult::unsupported('Not implemented.');
    }

    public function updateEmployee(DeviceUser $user): OperationResult
    {
        return $this->guard(Operation::UpdateEmployee) ?? OperationResult::unsupported('Not implemented.');
    }

    public function deleteEmployee(string $pin): OperationResult
    {
        return $this->guard(Operation::DeleteEmployee) ?? OperationResult::unsupported('Not implemented.');
    }

    public function getAttendanceLogs(?CarbonInterface $from = null, ?CarbonInterface $to = null): OperationResult
    {
        return $this->guard(Operation::GetAttendanceLogs) ?? OperationResult::unsupported('Not implemented.');
    }

    public function clearAttendanceLogs(): OperationResult
    {
        return $this->guard(Operation::ClearAttendanceLogs) ?? OperationResult::unsupported('Not implemented.');
    }

    public function syncDeviceTime(?CarbonInterface $time = null): OperationResult
    {
        return $this->guard(Operation::SyncDeviceTime) ?? OperationResult::unsupported('Not implemented.');
    }

    public function restartDevice(): OperationResult
    {
        return $this->guard(Operation::RestartDevice) ?? OperationResult::unsupported('Not implemented.');
    }

    public function setupHints(): array
    {
        return [];
    }

    /* ------------------------------------------------------------------ */
    /* Acknowledgements & shared side effects                              */
    /* ------------------------------------------------------------------ */

    public function onCommandAcknowledged(BiometricCommand $command, bool $success, array $result = []): void
    {
        if (!$success) {
            return;
        }

        $payload = $command->payload ?? [];

        switch ($command->type) {
            case CommandType::UserUpsert:
                $this->recordUserOnDevice(new DeviceUser(
                    pin: (string) ($payload['pin'] ?? ''),
                    name: (string) ($payload['name'] ?? ''),
                    card: $payload['card'] ?? null,
                    privilege: (int) ($payload['privilege'] ?? 0),
                    employeeId: $command->employee_id,
                ), 'sync');
                break;

            case CommandType::UserDelete:
                $this->recordUserRemoved((string) ($payload['pin'] ?? ''));
                break;

            case CommandType::GetInfo:
            case CommandType::Ping:
                $info = $this->interpretInformation($result);
                if ($info) {
                    $this->storeInformation($info);
                }
                break;

            case CommandType::ClearLogs:
                $this->activity->event($this->device, EventLevel::Warning, 'logs_cleared', 'Attendance logs were cleared on the device.');
                break;

            case CommandType::Reboot:
                $this->activity->event($this->device, EventLevel::Info, 'restarted', 'Device accepted the restart command.');
                break;

            default:
                break;
        }
    }

    /**
     * Map a GetInfo acknowledgement payload into neutral information.
     * Providers override this to parse their protocol's reply format.
     */
    protected function interpretInformation(array $result): array
    {
        return array_intersect_key($result, array_flip([
            'serial_number', 'manufacturer', 'model', 'firmware_version', 'user_count', 'fingerprint_count', 'face_count', 'log_count',
        ]));
    }

    protected function cachedInformation(): DeviceInformation
    {
        $info = $this->device->device_info ?? [];

        return DeviceInformation::fromArray($info + [
            'serial_number' => $this->device->serial_number,
            'manufacturer' => $this->device->manufacturer,
            'model' => $this->device->model,
            'firmware_version' => $this->device->firmware_version,
            'reported_at' => $this->device->device_info_updated_at?->toIso8601String(),
        ]);
    }

    protected function storeInformation(array $info): void
    {
        $merged = array_filter($info + ($this->device->device_info ?? []), fn ($v) => $v !== null && $v !== '');
        $attributes = ['device_info' => $merged, 'device_info_updated_at' => now()];
        if (!empty($info['firmware_version'])) {
            $attributes['firmware_version'] = mb_substr((string) $info['firmware_version'], 0, 100);
        }
        $this->device->forceFill($attributes)->saveQuietly();
    }

    protected function recordUserOnDevice(DeviceUser $user, string $source): void
    {
        if ($user->pin === '') {
            return;
        }

        $this->directory->upsert($this->device, $user, $source);

        // Keep the existing employee enrollment flags consistent.
        $employeeId = $user->employeeId ?? $this->directory->employeeIdForPin($this->device, $user->pin);
        if ($employeeId) {
            Employee::where('id', $employeeId)->update([
                'is_biometric_enrolled' => true,
                'biometric_enrollment_status' => 'enrolled',
                'biometric_device_sn' => $this->device->serial_number,
                'biometric_enrolled_at' => now(),
            ]);
        }
    }

    protected function recordUserRemoved(string $pin): void
    {
        if ($pin === '') {
            return;
        }

        $employeeId = $this->directory->employeeIdForPin($this->device, $pin);
        $this->directory->remove($this->device, $pin);

        if ($employeeId) {
            Employee::where('id', $employeeId)
                ->where('biometric_device_sn', $this->device->serial_number)
                ->update([
                    'is_biometric_enrolled' => false,
                    'biometric_enrollment_status' => 'not_enrolled',
                    'biometric_device_sn' => null,
                ]);
        }
    }
}
