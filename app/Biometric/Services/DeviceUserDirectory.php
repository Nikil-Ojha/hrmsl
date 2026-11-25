<?php

namespace App\Biometric\Services;

use App\Biometric\Data\DeviceUser;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceUser;
use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Server-side mirror of the users stored on each device, built from device
 * reports and acknowledged enrollments. PINs are linked to employees of the
 * device's company by their biometric code.
 */
class DeviceUserDirectory
{
    public function __construct(private readonly EmployeeSelector $employees)
    {
    }

    public function upsert(BiometricDevice $device, DeviceUser $user, string $source): BiometricDeviceUser
    {
        return BiometricDeviceUser::updateOrCreate(
            ['device_id' => $device->id, 'pin' => $user->pin],
            [
                'company_id' => $device->company_id,
                'name' => mb_substr($user->name, 0, 100),
                'card' => $user->card,
                'privilege' => $user->privilege,
                'employee_id' => $user->employeeId ?? $this->resolveEmployeeId($device, $user->pin),
                'source' => $source,
                'last_reported_at' => now(),
            ]
        );
    }

    /** @param DeviceUser[] $users */
    public function upsertMany(BiometricDevice $device, array $users, string $source): int
    {
        foreach ($users as $user) {
            $this->upsert($device, $user, $source);
        }

        return count($users);
    }

    /**
     * Full snapshot: users not in the list are removed from the mirror.
     *
     * @param DeviceUser[] $users
     */
    public function replaceAll(BiometricDevice $device, array $users, string $source): int
    {
        $this->upsertMany($device, $users, $source);

        BiometricDeviceUser::where('device_id', $device->id)
            ->whereNotIn('pin', array_map(fn (DeviceUser $u) => $u->pin, $users) ?: [''])
            ->delete();

        return count($users);
    }

    public function remove(BiometricDevice $device, string $pin): void
    {
        BiometricDeviceUser::where('device_id', $device->id)->where('pin', $pin)->delete();
    }

    /** @return DeviceUser[] */
    public function list(BiometricDevice $device): array
    {
        return BiometricDeviceUser::where('device_id', $device->id)->orderBy('pin')->limit(5000)->get()
            ->map(fn (BiometricDeviceUser $u) => new DeviceUser($u->pin, (string) $u->name, $u->card, (int) $u->privilege, $u->employee_id))
            ->all();
    }

    public function employeeIdForPin(BiometricDevice $device, string $pin): ?int
    {
        $known = BiometricDeviceUser::where('device_id', $device->id)->where('pin', $pin)->value('employee_id');

        return $known ?: $this->resolveEmployeeId($device, $pin);
    }

    private function resolveEmployeeId(BiometricDevice $device, string $pin): ?int
    {
        $trimmed = ltrim($pin, '0') ?: $pin;

        return Employee::whereIn('created_by', $this->employees->companyUserIds((int) $device->company_id))
            ->whereIn('biometric_emp_id', array_unique([$pin, $trimmed]))
            ->value('id');
    }
}
