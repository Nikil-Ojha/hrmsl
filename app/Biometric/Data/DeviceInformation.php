<?php

namespace App\Biometric\Data;

final class DeviceInformation
{
    public function __construct(
        public readonly ?string $serialNumber = null,
        public readonly ?string $manufacturer = null,
        public readonly ?string $model = null,
        public readonly ?string $firmwareVersion = null,
        public readonly ?int $userCount = null,
        public readonly ?int $fingerprintCount = null,
        public readonly ?int $faceCount = null,
        public readonly ?int $logCount = null,
        public readonly ?string $reportedAt = null,
        public readonly array $extra = [],
    ) {
    }

    public static function fromArray(array $data): self
    {
        $int = fn ($v) => $v === null || $v === '' ? null : (int) $v;

        return new self(
            serialNumber: $data['serial_number'] ?? null,
            manufacturer: $data['manufacturer'] ?? null,
            model: $data['model'] ?? null,
            firmwareVersion: $data['firmware_version'] ?? null,
            userCount: $int($data['user_count'] ?? null),
            fingerprintCount: $int($data['fingerprint_count'] ?? null),
            faceCount: $int($data['face_count'] ?? null),
            logCount: $int($data['log_count'] ?? null),
            reportedAt: $data['reported_at'] ?? null,
            extra: $data['extra'] ?? [],
        );
    }

    public function toArray(): array
    {
        return [
            'serial_number' => $this->serialNumber,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'firmware_version' => $this->firmwareVersion,
            'user_count' => $this->userCount,
            'fingerprint_count' => $this->fingerprintCount,
            'face_count' => $this->faceCount,
            'log_count' => $this->logCount,
            'reported_at' => $this->reportedAt,
            'extra' => $this->extra,
        ];
    }
}
