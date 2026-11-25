<?php

namespace App\Biometric\Enums;

enum AuthType: string
{
    use HasOptions;

    case None = 'none';
    case ApiKey = 'api_key';
    case Hmac = 'hmac';
    case Basic = 'basic';
    case Token = 'token';
    case CommKey = 'comm_key';

    public function label(): string
    {
        return match ($this) {
            self::None => 'None',
            self::ApiKey => 'Device API Key',
            self::Hmac => 'API Key + HMAC Signature',
            self::Basic => 'Username / Password',
            self::Token => 'Bearer Token',
            self::CommKey => 'Device Comm Key',
        };
    }

    /** Whether the server issues an API key that the device must present. */
    public function usesIssuedApiKey(): bool
    {
        return in_array($this, [self::ApiKey, self::Hmac], true);
    }
}
