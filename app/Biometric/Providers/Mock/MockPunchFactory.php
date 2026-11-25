<?php

namespace App\Biometric\Providers\Mock;

use App\Biometric\Data\PunchRecord;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Converts stored simulated log entries into neutral punch records.
 */
final class MockPunchFactory
{
    /** @return PunchRecord[] */
    public static function toRecords(array $logs, ?CarbonInterface $from, ?CarbonInterface $to): array
    {
        $out = [];
        foreach ($logs as $log) {
            $at = Carbon::parse($log['punched_at']);
            if (($from && $at->lt($from)) || ($to && $at->gt($to))) {
                continue;
            }
            $out[] = new PunchRecord((string) $log['pin'], $at, $log['state'] ?? null, $log['verify'] ?? null);
        }

        usort($out, fn (PunchRecord $a, PunchRecord $b) => $a->punchedAt <=> $b->punchedAt);

        return $out;
    }
}
