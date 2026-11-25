<?php

namespace App\Biometric\Enums;

enum PunchSource: string
{
    use HasOptions;

    case LocalAgent = 'local_agent';
    case DirectDevice = 'direct_device';
    case AdmsPush = 'adms_push';
    case FkWebPush = 'fkweb_push';
    case UsbImport = 'usb_import';
    case Api = 'api';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::LocalAgent => 'Local Agent',
            self::DirectDevice => 'Direct Device API',
            self::AdmsPush => 'ADMS / iClock Push',
            self::FkWebPush => 'FKWeb Push',
            self::UsbImport => 'USB Import',
            self::Api => 'REST API',
            self::Manual => 'Manual',
        };
    }
}
