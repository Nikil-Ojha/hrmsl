<?php

namespace App\Services;

use Carbon\Carbon;

class LeaveYearCycle
{
    public static function startMonth(): int
    {
        $settings = settings();
        $type = $settings['leave_year_type'] ?? 'calendar';

        if ($type === 'financial') {
            return 4;
        }

        if ($type === 'custom') {
            return max(1, min(12, (int) ($settings['leave_year_start_month'] ?? 1)));
        }

        return 1;
    }

    public static function startYear($date = null): int
    {
        $date = $date ? Carbon::parse($date) : now();
        $startMonth = self::startMonth();

        return $date->month < $startMonth ? $date->year - 1 : $date->year;
    }

    public static function label(int $startYear): string
    {
        $startMonth = self::startMonth();
        $endYear = $startMonth === 1 ? $startYear : $startYear + 1;

        if ($startMonth === 1) {
            return (string) $startYear;
        }

        return $startYear . '–' . substr((string) $endYear, -2);
    }

    public static function description(): string
    {
        $settings = settings();
        $type = $settings['leave_year_type'] ?? 'calendar';
        $startMonth = self::startMonth();

        if ($startMonth === 1) {
            return 'Calendar Year (January–December)';
        }

        if ($startMonth === 4 && $type === 'financial') {
            return 'Financial Year (April–March)';
        }

        $start = Carbon::create(2000, $startMonth, 1);
        $endMonth = $start->copy()->subDay()->format('F');

        return 'Custom (' . $start->format('F') . '–' . $endMonth . ')';
    }
}
