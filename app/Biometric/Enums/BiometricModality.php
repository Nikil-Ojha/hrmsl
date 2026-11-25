<?php

namespace App\Biometric\Enums;

enum BiometricModality: string
{
    use HasOptions;

    case Fingerprint = 'fingerprint';
    case Face = 'face';
    case Card = 'card';
    case Pin = 'pin';
    case Palm = 'palm';
    case Iris = 'iris';

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint',
            self::Face => 'Facial Recognition',
            self::Card => 'RFID Card',
            self::Pin => 'PIN',
            self::Palm => 'Palm',
            self::Iris => 'Iris',
        };
    }

    /**
     * Modalities supported by a given hardware DeviceType.
     *
     * @return self[]
     */
    public static function forDeviceType(?DeviceType $deviceType): array
    {
        return match ($deviceType) {
            DeviceType::Fingerprint => [self::Fingerprint, self::Pin],
            DeviceType::Face => [self::Face, self::Pin],
            DeviceType::FaceFingerprint => [self::Fingerprint, self::Face, self::Pin],
            DeviceType::FingerprintCard => [self::Fingerprint, self::Card, self::Pin],
            DeviceType::FaceCard => [self::Face, self::Card, self::Pin],
            DeviceType::Card => [self::Card, self::Pin],
            DeviceType::Pin => [self::Pin],
            DeviceType::Palm => [self::Palm, self::Pin],
            DeviceType::Iris => [self::Iris, self::Pin],
            DeviceType::MultiModal, null => [self::Fingerprint, self::Face, self::Card, self::Pin, self::Palm],
        };
    }
}
