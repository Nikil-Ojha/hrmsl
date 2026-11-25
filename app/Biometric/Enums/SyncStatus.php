<?php

namespace App\Biometric\Enums;

enum SyncStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Running = 'running';
    case Success = 'success';
    case Partial = 'partial';
    case Failed = 'failed';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Partial, self::Failed], true);
    }
}
