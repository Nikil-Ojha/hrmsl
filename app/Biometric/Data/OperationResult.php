<?php

namespace App\Biometric\Data;

/**
 * Outcome of a provider operation.
 *
 *  - completed: finished synchronously; $data holds the result
 *  - queued:    command(s) queued; the device completes them asynchronously
 *  - failed:    the operation was attempted and failed
 *  - unsupported / disabled: never attempted
 */
final class OperationResult
{
    public const COMPLETED = 'completed';
    public const QUEUED = 'queued';
    public const FAILED = 'failed';
    public const UNSUPPORTED = 'unsupported';
    public const DISABLED = 'disabled';

    /**
     * @param int[] $commandIds
     */
    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly mixed $data = null,
        public readonly array $commandIds = [],
    ) {
    }

    public static function completed(string $message, mixed $data = null): self
    {
        return new self(self::COMPLETED, $message, $data);
    }

    /** @param int[] $commandIds */
    public static function queued(array $commandIds, string $message, mixed $data = null): self
    {
        return new self(self::QUEUED, $message, $data, $commandIds);
    }

    public static function failed(string $message, mixed $data = null): self
    {
        return new self(self::FAILED, $message, $data);
    }

    public static function unsupported(string $message): self
    {
        return new self(self::UNSUPPORTED, $message);
    }

    public static function disabled(string $message): self
    {
        return new self(self::DISABLED, $message);
    }

    public function isCompleted(): bool
    {
        return $this->status === self::COMPLETED;
    }

    public function isQueued(): bool
    {
        return $this->status === self::QUEUED;
    }

    public function wasAttempted(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::QUEUED, self::FAILED], true);
    }

    public function toArray(): array
    {
        $data = $this->data;
        if (is_object($data) && method_exists($data, 'toArray')) {
            $data = $data->toArray();
        } elseif (is_array($data)) {
            $data = array_map(fn ($d) => is_object($d) && method_exists($d, 'toArray') ? $d->toArray() : $d, $data);
        }

        return [
            'status' => $this->status,
            'message' => $this->message,
            'data' => $data,
            'command_ids' => $this->commandIds,
        ];
    }
}
