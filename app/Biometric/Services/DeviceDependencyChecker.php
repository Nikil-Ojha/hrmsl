<?php

namespace App\Biometric\Services;

use App\Models\BiometricAttendanceLog;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\Employee;

/**
 * Decides whether a device can be hard-deleted. Devices that produced
 * attendance data or still hold enrolled employees must be deactivated
 * instead so that attendance history stays traceable.
 */
class DeviceDependencyChecker
{
    public function __construct(private readonly EmployeeSelector $selector)
    {
    }

    public function check(BiometricDevice $device): array
    {
        $companyUsers = $this->selector->companyUserIds((int) $device->company_id);

        $attendanceLogs = BiometricAttendanceLog::where('device_sn', $device->serial_number)
            ->whereIn('created_by', $companyUsers)
            ->count();

        $legacyEnrolled = Employee::where('biometric_device_sn', $device->serial_number)
            ->whereIn('created_by', $companyUsers)
            ->where('is_biometric_enrolled', true)
            ->count();

        $mappedEnrolled = BiometricEnrollment::where('device_id', $device->id)
            ->whereNotIn('enrollment_status', ['removed', 'failed'])
            ->count();

        $enrolledEmployees = max($legacyEnrolled, $mappedEnrolled);

        $pendingCommands = BiometricCommand::where('device_id', $device->id)->pending()->count();

        $blockers = [];
        if ($attendanceLogs > 0) {
            $blockers[] = ['key' => 'attendance_logs', 'count' => $attendanceLogs, 'message' => "{$attendanceLogs} attendance log(s) were recorded by this device."];
        }
        if ($enrolledEmployees > 0) {
            $blockers[] = ['key' => 'enrolled_employees', 'count' => $enrolledEmployees, 'message' => "{$enrolledEmployees} employee(s) are enrolled on this device."];
        }

        return [
            'attendance_logs' => $attendanceLogs,
            'enrolled_employees' => $enrolledEmployees,
            'pending_commands' => $pendingCommands,
            'blockers' => $blockers,
            'can_delete' => count($blockers) === 0,
        ];
    }
}
