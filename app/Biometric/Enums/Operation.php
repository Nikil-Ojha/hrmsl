<?php

namespace App\Biometric\Enums;

/**
 * Every operation exposed by BiometricDeviceInterface. Providers declare a
 * SupportLevel for each one.
 */
enum Operation: string
{
    use HasOptions;

    case Connect = 'connect';
    case TestConnection = 'test_connection';
    case DeviceInformation = 'device_information';
    case DeviceStatus = 'device_status';
    case GetUsers = 'get_users';
    case EnrollEmployee = 'enroll_employee';
    case UpdateEmployee = 'update_employee';
    case DeleteEmployee = 'delete_employee';
    case GetAttendanceLogs = 'get_attendance_logs';
    case ClearAttendanceLogs = 'clear_attendance_logs';
    case SyncDeviceTime = 'sync_device_time';
    case RestartDevice = 'restart_device';

    public function label(): string
    {
        return match ($this) {
            self::Connect => 'Connect',
            self::TestConnection => 'Test Connection',
            self::DeviceInformation => 'Device Information',
            self::DeviceStatus => 'Device Status',
            self::GetUsers => 'Get Users',
            self::EnrollEmployee => 'Enroll Employee',
            self::UpdateEmployee => 'Update Employee',
            self::DeleteEmployee => 'Delete Employee',
            self::GetAttendanceLogs => 'Get Attendance Logs',
            self::ClearAttendanceLogs => 'Clear Attendance Logs',
            self::SyncDeviceTime => 'Sync Device Time',
            self::RestartDevice => 'Restart Device',
        };
    }
}
