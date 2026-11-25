<?php

namespace App\Biometric\Enums;

enum CommandStatus: string
{
    use HasOptions;

    case Queued = 'queued';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Failed = 'failed';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Acknowledged, self::Failed, self::Expired, self::Cancelled], true);
    }

    /** @return string[] */
    public static function pendingValues(): array
    {
        return [self::Queued->value, self::Sent->value];
    }
}
