<?php

namespace App\Biometric\Enums;

enum PunchProcessingStatus: string
{
    use HasOptions;

    case Pending = 'pending';
    case Processed = 'processed';
    case DuplicateSuppressed = 'duplicate_suppressed';
    case UnmatchedEmployee = 'unmatched_employee';
    case ConflictSkipped = 'conflict_skipped';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processed => 'Processed',
            self::DuplicateSuppressed => 'Duplicate Suppressed',
            self::UnmatchedEmployee => 'Unmatched Employee',
            self::ConflictSkipped => 'Skipped (Protected Record)',
            self::Failed => 'Failed',
        };
    }
}
