<?php

namespace App\Biometric\Enums;

enum EnrollmentStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case AwaitingCapture = 'awaiting_capture';
    case Enrolled = 'enrolled';
    case Syncing = 'syncing';
    case Failed = 'failed';
    case Removing = 'removing';
    case Removed = 'removed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::AwaitingCapture => 'Awaiting Device Scan',
            self::Enrolled => 'Enrolled',
            self::Syncing => 'Syncing',
            self::Failed => 'Failed',
            self::Removing => 'Removing',
            self::Removed => 'Removed',
        };
    }
}
