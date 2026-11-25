<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

class SettingMiddleware
{
    /**
     * Common legacy IANA timezone aliases removed when tzdata-legacy is absent.
     *
     * @var array<string, string>
     */
    private const LEGACY_ALIASES = [
        'Asia/Calcutta' => 'Asia/Kolkata',
        'Asia/Katmandu' => 'Asia/Kathmandu',
        'Asia/Dacca' => 'Asia/Dhaka',
        'Asia/Saigon' => 'Asia/Ho_Chi_Minh',
        'Asia/Rangoon' => 'Asia/Yangon',
        'Asia/Chungking' => 'Asia/Shanghai',
        'Asia/Thimbu' => 'Asia/Thimphu',
        'Europe/Kiev' => 'Europe/Kyiv',
        'US/Eastern' => 'America/New_York',
        'US/Central' => 'America/Chicago',
        'US/Mountain' => 'America/Denver',
        'US/Pacific' => 'America/Los_Angeles',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $settings = settings();
        if (!empty($settings['defaultTimezone'])) {
            $tz = trim((string) $settings['defaultTimezone']);
            $tz = self::LEGACY_ALIASES[$tz] ?? $tz;

            if (!in_array($tz, timezone_identifiers_list(), true)) {
                $tz = 'UTC';
            }

            Config::set('app.timezone', $tz);
            date_default_timezone_set($tz);
        }

        return $next($request);
    }
}
