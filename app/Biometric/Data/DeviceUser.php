<?php

namespace App\Biometric\Data;

use App\Models\Employee;

/**
 * Vendor-neutral representation of a user record stored on a device.
 */
final class DeviceUser
{
    public function __construct(
        public readonly string $pin,
        public readonly string $name = '',
        public readonly ?string $card = null,
        public readonly int $privilege = 0,
        public readonly ?int $employeeId = null,
        public readonly ?string $employeeCode = null,
        public readonly array $extra = [],
    ) {
    }

    public static function fromEmployee(Employee $employee): self
    {
        $employee->loadMissing('user:id,name');

        return new self(
            pin: (string) $employee->biometric_emp_id,
            name: (string) ($employee->user?->name ?? ('Emp ' . $employee->biometric_emp_id)),
            employeeId: $employee->id,
            employeeCode: $employee->employee_id,
        );
    }

    public function toPayload(): array
    {
        return array_filter([
            'pin' => $this->pin,
            'name' => $this->name,
            'card' => $this->card,
            'privilege' => $this->privilege,
            'employee_code' => $this->employeeCode,
        ], fn ($v) => $v !== null && $v !== '') + ['privilege' => $this->privilege];
    }

    public function toArray(): array
    {
        return [
            'pin' => $this->pin,
            'name' => $this->name,
            'card' => $this->card,
            'privilege' => $this->privilege,
            'employee_id' => $this->employeeId,
            'employee_code' => $this->employeeCode,
            'extra' => $this->extra,
        ];
    }
}
