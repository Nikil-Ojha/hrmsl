<?php

namespace App\Biometric\Enums;

enum SyncAction: string
{
    use HasOptions;

    case Connectivity = 'connectivity';
    case TimeSync = 'time_sync';
    case EmployeeSync = 'employee_sync';
    case LogFetch = 'log_fetch';
    case LogSync = 'log_sync';
    case DeviceInfo = 'device_info';
    case UserFetch = 'user_fetch';
    case UserDelete = 'user_delete';
    case Enrollment = 'enrollment';
    case LogClear = 'log_clear';
    case Restart = 'restart';

    public function label(): string
    {
        return match ($this) {
            self::Connectivity => 'Connectivity Check',
            self::TimeSync => 'Time Sync',
            self::EmployeeSync => 'Employee Sync',
            self::LogFetch => 'Attendance Log Fetch',
            self::LogSync => 'Attendance Log Sync',
            self::DeviceInfo => 'Device Information Refresh',
            self::UserFetch => 'Device User Fetch',
            self::UserDelete => 'Remove User From Device',
            self::Enrollment => 'Employee Biometric Enrollment',
            self::LogClear => 'Clear Attendance Logs',
            self::Restart => 'Device Restart',
        };
    }
}
