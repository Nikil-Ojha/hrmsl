<?php

namespace App\Biometric\Providers;

use App\Biometric\Contracts\QueuesDeviceCommands;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationResult;
use App\Biometric\Enums\CommandType;
use App\Biometric\Enums\Operation;
use App\Biometric\Services\CommandQueue;
use App\Biometric\Services\ConnectivityChecker;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\DeviceUserDirectory;
use App\Models\BiometricDevice;
use Carbon\CarbonInterface;

/**
 * Base for push devices: the device polls the server, so every device-side
 * operation becomes a vendor-neutral command in the CommandQueue. The
 * provider's ProtocolAdapter encodes it when the device collects it.
 */
abstract class AbstractPushProvider extends AbstractProvider implements QueuesDeviceCommands
{
    public function __construct(
        BiometricDevice $device,
        ConnectivityChecker $connectivity,
        DeviceActivityRecorder $activity,
        DeviceUserDirectory $directory,
        protected CommandQueue $commands,
    ) {
        parent::__construct($device, $connectivity, $activity, $directory);
    }

    /** Whether the provider can ask the device to report fresh information. */
    protected function infoRefreshOperation(): ?CommandType
    {
        return CommandType::GetInfo;
    }

    public function getDeviceInformation(bool $refresh = false): OperationResult
    {
        if (!$refresh) {
            return parent::getDeviceInformation(false);
        }

        if ($guard = $this->guard(Operation::DeviceInformation)) {
            return $guard;
        }

        if (!$this->infoRefreshOperation()) {
            return OperationResult::completed(__('This device cannot be queried; showing last reported information.'), $this->cachedInformation());
        }

        return $this->queue($this->infoRefreshOperation(), [], null, __('Information request queued.'), $this->cachedInformation());
    }

    public function getUsers(): OperationResult
    {
        return $this->guard(Operation::GetUsers)
            ?? $this->queue(CommandType::QueryUsers, [], null, __('User list requested. The device uploads its users on the next connection.'), $this->directory->list($this->device));
    }

    public function enrollEmployee(DeviceUser $user): OperationResult
    {
        return $this->guard(Operation::EnrollEmployee)
            ?? $this->queue(CommandType::UserUpsert, $user->toPayload(), $user->employeeId, __('Enrollment queued.'));
    }

    public function updateEmployee(DeviceUser $user): OperationResult
    {
        return $this->guard(Operation::UpdateEmployee)
            ?? $this->queue(CommandType::UserUpsert, $user->toPayload(), $user->employeeId, __('Update queued.'));
    }

    public function deleteEmployee(string $pin): OperationResult
    {
        return $this->guard(Operation::DeleteEmployee)
            ?? $this->queue(CommandType::UserDelete, ['pin' => $pin], $this->directory->employeeIdForPin($this->device, $pin), __('Removal queued.'));
    }

    public function getAttendanceLogs(?CarbonInterface $from = null, ?CarbonInterface $to = null): OperationResult
    {
        return $this->guard(Operation::GetAttendanceLogs)
            ?? $this->queue(CommandType::FetchLogs, array_filter([
                'from' => $from?->toIso8601String(),
                'to' => $to?->toIso8601String(),
            ]), null, __('Log upload requested. Logs arrive through the attendance pipeline.'));
    }

    public function clearAttendanceLogs(): OperationResult
    {
        return $this->guard(Operation::ClearAttendanceLogs)
            ?? $this->queue(CommandType::ClearLogs, [], null, __('Clear-logs command queued.'));
    }

    public function syncDeviceTime(?CarbonInterface $time = null): OperationResult
    {
        return $this->guard(Operation::SyncDeviceTime)
            ?? $this->queue(CommandType::SyncTime, array_filter(['time' => $time?->toIso8601String()]), null, __('Time synchronization queued.'));
    }

    public function restartDevice(): OperationResult
    {
        return $this->guard(Operation::RestartDevice)
            ?? $this->queue(CommandType::Reboot, [], null, __('Restart command queued.'));
    }

    protected function queue(CommandType $type, array $payload, ?int $employeeId, string $message, mixed $data = null): OperationResult
    {
        $command = $this->commands->queue(
            $this->device,
            $type,
            $payload,
            $this->context?->run,
            $employeeId,
            $this->context?->priority ?? 100,
            $this->context?->user,
        );

        return OperationResult::queued([$command->id], $message, $data);
    }
}
