<?php

namespace App\Biometric\Enums;

enum SyncFailureStatus: string
{
    use HasOptions;

    case PendingRetry = 'pending_retry';
    case Resolved = 'resolved';
    case Abandoned = 'abandoned';

    public function label(): string
    {
        return match ($this) {
            self::PendingRetry => 'Pending Retry',
            self::Resolved => 'Resolved',
            self::Abandoned => 'Dismissed / Abandoned',
        };
    }
}
