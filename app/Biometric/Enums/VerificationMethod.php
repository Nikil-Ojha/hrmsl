<?php

namespace App\Biometric\Enums;

enum VerificationMethod: string
{
    use HasOptions;

    case Fingerprint = 'fingerprint';
    case Face = 'face';
    case Pin = 'pin';
    case Card = 'card';
    case Palm = 'palm';
    case Iris = 'iris';
    case Mobile = 'mobile';
    case Unknown = 'unknown';

    public function label(): string
    {
        return match ($this) {
            self::Fingerprint => 'Fingerprint',
            self::Face => 'Face Recognition',
            self::Pin => 'PIN / Password',
            self::Card => 'RFID Card',
            self::Palm => 'Palm',
            self::Iris => 'Iris',
            self::Mobile => 'Mobile',
            self::Unknown => 'Unknown',
        };
    }

    /**
     * Normalize vendor verification codes (e.g. ZKTeco Verify 0=Password, 1=FP, 4=Card, 15=Face).
     */
    public static function fromRaw(mixed $raw): self
    {
        $val = strtolower(trim((string) $raw));
        if ($val === '') {
            return self::Unknown;
        }

        return match ($val) {
            '1', 'fp', 'finger', 'fingerprint', 'biometric_fp' => self::Fingerprint,
            '15', '16', 'face', 'facial', 'face_recognition' => self::Face,
            '0', '3', 'pin', 'password', 'pwd', 'passcode' => self::Pin,
            '2', '4', 'rfid', 'card', 'badge', 'ic_card' => self::Card,
            '25', 'palm', 'palm_vein' => self::Palm,
            'iris' => self::Iris,
            'mobile', 'app' => self::Mobile,
            default => self::Unknown,
        };
    }
}
