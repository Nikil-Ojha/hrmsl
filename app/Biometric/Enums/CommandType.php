<?php

namespace App\Biometric\Enums;

/**
 * Vendor-neutral device commands. Drivers translate these into wire format.
 */
enum CommandType: string
{
    use HasOptions;

    case Ping = 'ping';
    case GetInfo = 'get_info';
    case SyncTime = 'sync_time';
    case UserUpsert = 'user_upsert';
    case UserDelete = 'user_delete';
    case StartEnrollment = 'start_enrollment';
    case TemplatePush = 'template_push';
    case TemplatePull = 'template_pull';
    case FetchLogs = 'fetch_logs';
    case ClearLogs = 'clear_logs';
    case QueryUsers = 'query_users';
    case Reboot = 'reboot';

    public function label(): string
    {
        return match ($this) {
            self::Ping => 'Ping',
            self::GetInfo => 'Get Device Info',
            self::SyncTime => 'Sync Time',
            self::UserUpsert => 'Add / Update User',
            self::UserDelete => 'Delete User',
            self::StartEnrollment => 'Start Biometric Enrollment',
            self::TemplatePush => 'Transfer Biometric Template',
            self::TemplatePull => 'Pull Biometric Template',
            self::FetchLogs => 'Fetch Attendance Logs',
            self::ClearLogs => 'Clear Attendance Logs',
            self::QueryUsers => 'Query Users',
            self::Reboot => 'Reboot',
        };
    }
}
