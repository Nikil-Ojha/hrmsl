<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendancePolicy extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'late_arrival_grace',
        'early_departure_grace',
        'overtime_rate_per_hour',
        'punch_pairing_mode',
        'duplicate_punch_window_sec',
        'missing_clock_out_grace_hours',
        'auto_detect_shift',
        'status',
        'created_by',
    ];

    protected $casts = [
        'overtime_rate_per_hour' => 'decimal:2',
        'late_arrival_grace' => 'integer',
        'early_departure_grace' => 'integer',
        'duplicate_punch_window_sec' => 'integer',
        'missing_clock_out_grace_hours' => 'integer',
        'auto_detect_shift' => 'boolean',
    ];

    /**
     * Get the user who created the policy.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Check if arrival time is late (supports Carbon datetimes and time strings).
     */
    public function isLateArrival($actualTime, $expectedTime): bool
    {
        return $this->lateMinutes($actualTime, $expectedTime) > 0;
    }

    /**
     * Minutes late beyond grace period (0 if within grace period or early).
     */
    public function lateMinutes($actualTime, $expectedTime): int
    {
        $actual = $actualTime instanceof \Carbon\CarbonInterface ? $actualTime->copy() : Carbon::parse($actualTime);
        $expected = $expectedTime instanceof \Carbon\CarbonInterface ? $expectedTime->copy() : Carbon::parse($expectedTime);
        $deadline = $expected->copy()->addMinutes((int) $this->late_arrival_grace);

        if ($actual->lte($deadline)) {
            return 0;
        }

        // Total minutes after shift start once grace period is exceeded.
        return (int) max(1, $expected->diffInMinutes($actual));
    }

    /**
     * Check if departure time is early (supports Carbon datetimes and time strings).
     */
    public function isEarlyDeparture($actualTime, $expectedTime): bool
    {
        return $this->earlyDepartureMinutes($actualTime, $expectedTime) > 0;
    }

    /**
     * Minutes departed early beyond grace period (0 if within grace period or on/after end).
     */
    public function earlyDepartureMinutes($actualTime, $expectedTime): int
    {
        $actual = $actualTime instanceof \Carbon\CarbonInterface ? $actualTime->copy() : Carbon::parse($actualTime);
        $expected = $expectedTime instanceof \Carbon\CarbonInterface ? $expectedTime->copy() : Carbon::parse($expectedTime);
        $earliestAllowed = $expected->copy()->subMinutes((int) $this->early_departure_grace);

        if ($actual->gte($earliestAllowed)) {
            return 0;
        }

        return (int) max(1, $actual->diffInMinutes($expected));
    }

    /**
     * Calculate overtime amount.
     */
    public function calculateOvertimeAmount($overtimeHours)
    {
        return $overtimeHours * $this->overtime_rate_per_hour;
    }
}
