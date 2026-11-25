<?php

namespace App\Biometric\Services;

use App\Models\AttendancePolicy;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves the active shift and logical attendance date for a biometric punch.
 *
 * Supports:
 *  - Fixed per-employee shifts (day and overnight)
 *  - Multiple company shifts with automatic shift detection (`auto_detect_shift`)
 *  - Cross-midnight overnight shifts (never assumes calendar date == attendance day)
 */
class ShiftResolver
{
    /**
     * Resolve ['shift' => ?Shift, 'attendance_date' => string] for a local punch.
     *
     * @return array{shift: ?Shift, attendance_date: string}
     */
    public function resolveForPunch(
        Employee $employee,
        CarbonInterface $localPunchTime,
        array $companyUserIds,
        ?AttendancePolicy $policy = null,
    ): array {
        $autoDetect = (bool) ($policy?->auto_detect_shift ?? false);

        // 1. Fixed employee shift (unless policy enables multi-shift auto-detection)
        if (!$autoDetect && $employee->shift_id) {
            $assigned = Shift::where('id', $employee->shift_id)->where('status', 'active')->first();
            if ($assigned) {
                return [
                    'shift' => $assigned,
                    'attendance_date' => $this->attendanceDateForShift($localPunchTime, $assigned),
                ];
            }
        }

        // 2. Multiple shifts resolution across all active company shifts
        $shifts = Shift::whereIn('created_by', $companyUserIds)
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        if ($shifts->isEmpty()) {
            return ['shift' => null, 'attendance_date' => $localPunchTime->toDateString()];
        }

        if ($shifts->count() === 1) {
            $only = $shifts->first();

            return [
                'shift' => $only,
                'attendance_date' => $this->attendanceDateForShift($localPunchTime, $only),
            ];
        }

        return $this->matchBestShift($employee, $localPunchTime, $shifts);
    }

    /**
     * Compute the logical attendance date for a punch given a known shift.
     */
    public function attendanceDateForShift(CarbonInterface $localPunchTime, ?Shift $shift): string
    {
        if (!$shift || !$shift->is_night_shift || !$shift->start_time || !$shift->end_time) {
            return $localPunchTime->toDateString();
        }

        $startMin = $this->minutesOfDay((string) $shift->start_time);
        $endMin = $this->minutesOfDay((string) $shift->end_time);

        if ($endMin >= $startMin) {
            return $localPunchTime->toDateString();
        }

        $cutoffHours = max(1, (int) config('biometric.sync.night_shift_cutoff_hours', 6));
        $cutoffMin = min($startMin - 60, $endMin + ($cutoffHours * 60));
        $punchMin = ($localPunchTime->hour * 60) + $localPunchTime->minute;

        if ($punchMin <= $cutoffMin) {
            return $localPunchTime->copy()->subDay()->toDateString();
        }

        return $localPunchTime->toDateString();
    }

    /**
     * Compute shift-anchored [start, end] datetimes for an attendance date.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function shiftWindow(string $attendanceDate, Shift $shift): array
    {
        $start = Carbon::parse($attendanceDate . ' ' . $shift->start_time);
        $end = Carbon::parse($attendanceDate . ' ' . $shift->end_time);

        if ($shift->is_night_shift && $end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * Whether a missing clock-out is now overdue (past shift end + grace hours).
     */
    public function isMissingClockOutOverdue(
        string $attendanceDate,
        ?Shift $shift,
        int $graceHours = 4,
        ?CarbonInterface $referenceNow = null,
    ): bool {
        $now = $referenceNow ? Carbon::parse($referenceNow) : Carbon::now();
        $grace = max(1, $graceHours);

        if ($shift && $shift->start_time && $shift->end_time) {
            [, $shiftEnd] = $this->shiftWindow($attendanceDate, $shift);

            return $now->gt($shiftEnd->copy()->addHours($grace));
        }

        // Without a shift, consider missing clock-out overdue after the attendance date + grace hours
        return $now->gt(Carbon::parse($attendanceDate)->endOfDay()->addHours($grace));
    }

    /**
     * Match a punch against multiple active company shifts, checking yesterday's
     * overnight shift first when the employee already punched in for it.
     *
     * @param Collection<int, Shift> $shifts
     * @return array{shift: Shift, attendance_date: string}
     */
    private function matchBestShift(Employee $employee, CarbonInterface $localPunchTime, Collection $shifts): array
    {
        $today = $localPunchTime->toDateString();
        $yesterday = $localPunchTime->copy()->subDay()->toDateString();
        $cutoffHours = max(1, (int) config('biometric.sync.night_shift_cutoff_hours', 6));

        // First: if employee already punched in yesterday for an overnight shift and
        // this early-morning punch falls within that shift's end + cutoff, pair it there!
        $nightShifts = $shifts->filter(fn (Shift $s) => $s->is_night_shift);
        if ($nightShifts->isNotEmpty() && $employee->exists) {
            $hadYesterdayPunch = BiometricPunch::where('employee_id', $employee->id)
                ->whereDate('attendance_date', $yesterday)
                ->exists();

            if ($hadYesterdayPunch) {
                foreach ($nightShifts as $ns) {
                    [$nsStart, $nsEnd] = $this->shiftWindow($yesterday, $ns);
                    if ($localPunchTime->gte($nsStart) && $localPunchTime->lte($nsEnd->copy()->addHours($cutoffHours))) {
                        return ['shift' => $ns, 'attendance_date' => $yesterday];
                    }
                }
            }
        }

        // Second: evaluate candidate (shift, date) pairs for both yesterday (night shifts)
        // and today (all shifts), selecting the shift window that contains the punch
        // or has the closest start_time.
        $bestShift = $shifts->first();
        $bestDate = $today;
        $bestScore = PHP_INT_MAX;

        foreach ($shifts as $shift) {
            $candidateDates = $shift->is_night_shift ? [$yesterday, $today] : [$today];
            foreach ($candidateDates as $candDate) {
                [$start, $end] = $this->shiftWindow($candDate, $shift);
                $windowStart = $start->copy()->subHours(3);
                $windowEnd = $end->copy()->addHours($cutoffHours);

                if ($localPunchTime->lt($windowStart) || $localPunchTime->gt($windowEnd)) {
                    continue;
                }

                // Prefer shifts where punch is closest to shift start or inside [start, end]
                $inCore = $localPunchTime->betweenIncluded($start->copy()->subHours(2), $end->copy()->addHours(2));
                $distToStart = (int) abs($localPunchTime->diffInMinutes($start));
                $distToEnd = (int) abs($localPunchTime->diffInMinutes($end));
                $score = ($inCore ? 0 : 10000) + min($distToStart, $distToEnd);

                if ($score < $bestScore) {
                    $bestScore = $score;
                    $bestShift = $shift;
                    $bestDate = $candDate;
                }
            }
        }

        return ['shift' => $bestShift, 'attendance_date' => $bestDate];
    }

    private function minutesOfDay(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0) * 60) + (int) ($parts[1] ?? 0);
    }
}
