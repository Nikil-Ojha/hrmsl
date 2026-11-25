<?php

namespace App\Biometric\Services;

use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\Employee;

/**
 * Enforces strict tenant isolation and device-level authorization for
 * authenticated Local Device Communication Agents.
 *
 * Security invariants:
 *  - Every device lookup requires BOTH company_id == $agent->company_id AND
 *    agent_id == $agent->id (preventing cross-tenant and cross-agent access).
 *  - Never reveals whether an unauthorized device_id exists in another tenant.
 *  - Never trusts client-supplied employee_id values blindly.
 */
class AgentAuthorizationService
{
    public function __construct(
        private readonly AttendanceProcessor $attendanceProcessor,
        private readonly EmployeeSelector $employees,
    ) {
    }

    /**
     * Authorize a single device by numeric ID or serial number for this agent.
     *
     * @return array{ok: bool, device?: BiometricDevice, status?: int, code?: string, message?: string}
     */
    public function authorizeDevice(
        BiometricAgent $agent,
        ?int $deviceId = null,
        ?string $serialNumber = null,
        bool $requireActive = true,
    ): array {
        if ($deviceId === null && ($serialNumber === null || trim($serialNumber) === '')) {
            return [
                'ok' => false,
                'status' => 422,
                'code' => 'missing_device_identifier',
                'message' => 'A device_id or device_serial is required.',
            ];
        }

        $query = BiometricDevice::where('company_id', $agent->company_id)
            ->where('agent_id', $agent->id);

        if ($deviceId !== null) {
            $query->where('id', $deviceId);
        } else {
            $query->where('serial_number', trim((string) $serialNumber));
        }

        $device = $query->first();

        if (!$device) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'unauthorized_device',
                'message' => 'Device is not authorized for this agent.',
            ];
        }

        if ($requireActive && !$device->is_active) {
            return [
                'ok' => false,
                'status' => 403,
                'code' => 'inactive_device',
                'message' => 'Device is currently inactive.',
            ];
        }

        return [
            'ok' => true,
            'device' => $device,
        ];
    }

    /**
     * Resolve an employee strictly within the authenticated agent's tenant and
     * authorized device mapping (never trusting arbitrary client employee_id).
     */
    public function resolveEmployeeForDevice(
        BiometricAgent $agent,
        BiometricDevice $device,
        string $deviceUserId,
    ): ?Employee {
        if ((int) $device->company_id !== (int) $agent->company_id || (int) $device->agent_id !== (int) $agent->id) {
            return null;
        }

        $employee = $this->attendanceProcessor->resolveEmployee($device, $deviceUserId);
        if (!$employee) {
            return null;
        }

        // Defense-in-depth: double-check employee belongs to this tenant's company user tree
        $companyUserIds = $this->employees->companyUserIds((int) $agent->company_id);
        if (!in_array((int) $employee->created_by, $companyUserIds, true) && (int) $employee->user_id !== (int) $agent->company_id) {
            return null;
        }

        return $employee;
    }
}
