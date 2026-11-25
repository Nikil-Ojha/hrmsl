<?php

namespace App\Biometric\Enums;

enum DeviceType: string
{
    use HasOptions;

    case Fingerprint = 'fingerprint';
    case Face = 'face';
    case FaceFingerprint = 'face_fingerprint';
    case FingerprintCard = 'fingerprint_card';
    case FaceCard = 'face_card';
    case Card = 'card';
    case Pin = 'pin';
    case Palm = 'palm';
    case Iris = 'iris';
    case MultiModal = 'multi_modal';

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint',
            self::Face => 'Face Recognition',
            self::FaceFingerprint => 'Face + Fingerprint',
            self::FingerprintCard => 'Fingerprint + Card',
            self::FaceCard => 'Face + Card',
            self::Card => 'RFID Card',
            self::Pin => 'PIN',
            self::Palm => 'Palm',
            self::Iris => 'Iris',
            self::MultiModal => 'Multi-modal',
        };
    }
}
