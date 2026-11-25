<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Biometric device housekeeping (offline detection, command expiry, sync run finalization)
\Illuminate\Support\Facades\Schedule::command('biometric:monitor')->everyMinute()->withoutOverlapping()->runInBackground();
\Illuminate\Support\Facades\Schedule::command('biometric:retry-sync')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
\Illuminate\Support\Facades\Schedule::command('biometric:detect-missing-clockouts')->hourly()->withoutOverlapping()->runInBackground();
