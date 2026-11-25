<?php

namespace App\Console\Commands;

use App\Biometric\Services\AttendanceProcessor;
use Illuminate\Console\Command;

class BiometricDetectMissingClockOutsCommand extends Command
{
    protected $signature = 'biometric:detect-missing-clockouts';

    protected $description = 'Flag open attendance records whose shift end + missing clock-out grace period has elapsed';

    public function handle(AttendanceProcessor $processor): int
    {
        $flagged = $processor->detectMissingClockOuts();
        $this->info("Missing clock-out check complete: {$flagged} record(s) flagged.");

        return self::SUCCESS;
    }
}
