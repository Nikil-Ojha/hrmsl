<?php

namespace App\Biometric\Services;

use App\Models\BiometricDevice;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Determines which employees belong on a device. A device assigned to a branch
 * (and optionally a department) only receives that branch's / department's
 * employees; an unassigned device receives the whole company.
 */
class EmployeeSelector
{
    /** @var array<int, int[]> */
    private array $companyUserCache = [];

    public function baseQuery(BiometricDevice $device): Builder
    {
        $query = Employee::query()
            ->whereIn('created_by', $this->companyUserIds((int) $device->company_id))
            ->whereIn('employee_status', ['active', 'probation'])
            ->whereHas('user', fn ($q) => $q->where('status', 'active'));

        if ($device->branch_id) {
            $query->where('branch_id', $device->branch_id);
        }
        if ($device->department_id) {
            $query->where('department_id', $device->department_id);
        }

        return $query;
    }

    /**
     * @param int[]|null $employeeIds Restrict to these employee ids (employees.id)
     */
    public function eligible(BiometricDevice $device, ?array $employeeIds = null): Collection
    {
        return $this->withCode($this->baseQuery($device), $employeeIds)
            ->with('user:id,name')
            ->orderBy('id')
            ->get();
    }

    public function summary(BiometricDevice $device): array
    {
        $total = (clone $this->baseQuery($device))->count();
        $withCode = $this->withCode($this->baseQuery($device))->count();

        return [
            'in_scope' => $total,
            'eligible' => $withCode,
            'missing_code' => $total - $withCode,
            'scope' => $device->department_id ? 'department' : ($device->branch_id ? 'branch' : 'company'),
        ];
    }

    /** @return int[] */
    public function companyUserIds(int $companyId): array
    {
        if (!isset($this->companyUserCache[$companyId])) {
            $ids = getAllCompanyUsers($companyId);
            $ids[] = $companyId;
            $this->companyUserCache[$companyId] = array_values(array_unique(array_map('intval', $ids)));
        }

        return $this->companyUserCache[$companyId];
    }

    private function withCode(Builder $query, ?array $employeeIds = null): Builder
    {
        $query->whereNotNull('biometric_emp_id')->where('biometric_emp_id', '!=', '');

        if ($employeeIds !== null) {
            $query->whereIn('id', $employeeIds);
        }

        return $query;
    }
}
