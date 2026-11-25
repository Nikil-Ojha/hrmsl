<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class AttendanceRecord extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'employee_id',
        'biometric_id',
        'first_punch_id',
        'last_punch_id',
        'punch_count',
        'shift_id',
        'attendance_policy_id',
        'date',
        'clock_in',
        'clock_in_at',
        'clock_out',
        'clock_out_at',
        'total_hours',
        'break_hours',
        'overtime_hours',
        'overtime_amount',
        'is_late',
        'late_minutes',
        'is_early_departure',
        'early_departure_minutes',
        'is_missing_clock_out',
        'is_absent',
        'is_holiday',
        'is_weekend',
        'status',
        'source',
        'is_manual_override',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'date' => 'date',
        'break_hours' => 'decimal:2',
        'overtime_hours' => 'decimal:2',
        'overtime_amount' => 'decimal:2',
        'is_late' => 'boolean',
        'late_minutes' => 'integer',
        'is_early_departure' => 'boolean',
        'early_departure_minutes' => 'integer',
        'is_missing_clock_out' => 'boolean',
        'is_absent' => 'boolean',
        'is_holiday' => 'boolean',
        'is_weekend' => 'boolean',
        'is_manual_override' => 'boolean',
        'punch_count' => 'integer',
        'clock_in_at' => 'datetime',
        'clock_out_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    public function attendancePolicy()
    {
        return $this->belongsTo(AttendancePolicy::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function biometricPunches()
    {
        return $this->hasMany(BiometricPunch::class, 'attendance_record_id')->orderBy('punched_at_utc');
    }

    /**
     * Resolve shift-anchored start and end datetimes for this attendance date.
     *
     * @return array{0: Carbon, 1: Carbon}|null [shiftStart, shiftEnd]
     */
    public function shiftWindow(): ?array
    {
        if (!$this->shift || !$this->shift->start_time || !$this->shift->end_time) {
            return null;
        }

        $dateStr = $this->date ? Carbon::parse($this->date)->toDateString() : Carbon::today()->toDateString();
        $start = Carbon::parse($dateStr . ' ' . $this->shift->start_time);
        $end = Carbon::parse($dateStr . ' ' . $this->shift->end_time);

        if ($this->shift->is_night_shift && $end->lte($start)) {
            $end->addDay();
        }

        return [$start, $end];
    }

    /**
     * Resolve shift-anchored actual clock-in datetime (handles post-midnight
     * late arrival on overnight shifts accurately!).
     */
    public function resolvedClockInDateTime(): ?Carbon
    {
        if ($this->clock_in_at) {
            return Carbon::parse($this->clock_in_at);
        }

        $rawIn = $this->getRawOriginal('clock_in') ?: $this->clock_in;
        if (!$rawIn) {
            return null;
        }

        $dateStr = $this->date ? Carbon::parse($this->date)->toDateString() : Carbon::today()->toDateString();
        $clockIn = Carbon::parse($dateStr . ' ' . $rawIn);

        // Overnight shift: e.g. shift 22:00->06:00, employee arrives after midnight at 00:15
        if ($window = $this->shiftWindow()) {
            [$shiftStart, $shiftEnd] = $window;
            if ($this->shift->is_night_shift && $shiftEnd->gt($shiftStart->copy()->endOfDay())) {
                $nextDayIn = $clockIn->copy()->addDay();
                if ($nextDayIn->lte($shiftEnd) && $clockIn->lt($shiftStart->copy()->subHours(6))) {
                    return $nextDayIn;
                }
            }
        }

        return $clockIn;
    }

    /**
     * Resolve shift-anchored actual clock-out datetime (handles both next-day
     * clock-out AND pre-midnight early departure on overnight shifts!).
     */
    public function resolvedClockOutDateTime(?Carbon $clockIn = null): ?Carbon
    {
        if ($this->clock_out_at) {
            return Carbon::parse($this->clock_out_at);
        }

        $rawOut = $this->getRawOriginal('clock_out') ?: $this->clock_out;
        if (!$rawOut) {
            return null;
        }

        $dateStr = $this->date ? Carbon::parse($this->date)->toDateString() : Carbon::today()->toDateString();
        $clockOut = Carbon::parse($dateStr . ' ' . $rawOut);
        $clockIn = $clockIn ?? $this->resolvedClockInDateTime();

        if ($clockIn && $clockOut->lt($clockIn)) {
            $clockOut->addDay();
        }

        return $clockOut;
    }

    /**
     * Calculate total working hours (fully overnight-shift & break-window aware).
     */
    public function calculateTotalHours()
    {
        $clockIn = $this->resolvedClockInDateTime();
        $clockOut = $this->resolvedClockOutDateTime($clockIn);

        if ($clockIn && $clockOut) {
            $totalMinutes = abs($clockOut->diffInMinutes($clockIn));

            $breakMinutes = 0;
            if ($this->shift && $this->shift->break_start_time && $this->shift->break_end_time) {
                $dateStr = $this->date ? Carbon::parse($this->date)->toDateString() : Carbon::today()->toDateString();
                $breakStart = Carbon::parse($dateStr . ' ' . $this->shift->break_start_time);
                $breakEnd = Carbon::parse($dateStr . ' ' . $this->shift->break_end_time);

                // For overnight shifts whose break starts after midnight (e.g. 01:00–01:30 on a 22:00–06:00 shift)
                if ($window = $this->shiftWindow()) {
                    [$shiftStart] = $window;
                    if ($this->shift->is_night_shift && $breakStart->lt($shiftStart->copy()->subHours(6))) {
                        $breakStart->addDay();
                        $breakEnd->addDay();
                    }
                }
                if ($breakEnd->lt($breakStart)) {
                    $breakEnd->addDay();
                }

                // Exact overlap between [clockIn, clockOut] and [breakStart, breakEnd]
                $overlapStart = $clockIn->gt($breakStart) ? $clockIn : $breakStart;
                $overlapEnd = $clockOut->lt($breakEnd) ? $clockOut : $breakEnd;
                if ($overlapEnd->gt($overlapStart)) {
                    $overlapMinutes = $overlapStart->diffInMinutes($overlapEnd);
                    // Cap to configured shift break_duration if employee worked the full break
                    if ($clockIn->lte($breakStart) && $clockOut->gte($breakEnd) && $this->shift->break_duration > 0) {
                        $breakMinutes = (int) $this->shift->break_duration;
                    } else {
                        $breakMinutes = (int) $overlapMinutes;
                    }
                }
            }

            $workingMinutes = max(0, $totalMinutes - $breakMinutes);
            $calculatedHours = round($workingMinutes / 60, 2);

            $this->attributes['total_hours'] = $calculatedHours;
            $this->attributes['break_hours'] = round($breakMinutes / 60, 2);
        } else {
            $this->attributes['total_hours'] = 0;
            $this->attributes['break_hours'] = 0;
        }

        return $this->attributes['total_hours'] ?? 0;
    }

    /**
     * Check if employee is late (overnight-shift aware + shift grace fallback).
     */
    public function checkLateArrival()
    {
        $clockIn = $this->resolvedClockInDateTime();
        $window = $this->shiftWindow();

        if ($clockIn && $window) {
            [$shiftStart] = $window;

            if ($this->attendancePolicy) {
                $mins = $this->attendancePolicy->lateMinutes($clockIn, $shiftStart);
            } else {
                $grace = (int) ($this->shift->grace_period ?? 0);
                $deadline = $shiftStart->copy()->addMinutes($grace);
                $mins = $clockIn->gt($deadline) ? (int) max(1, $shiftStart->diffInMinutes($clockIn)) : 0;
            }

            $this->late_minutes = $mins;
            $this->is_late = $mins > 0;
        } else {
            $this->late_minutes = 0;
            $this->is_late = false;
        }

        return $this->is_late;
    }

    /**
     * Check if employee left early (overnight-shift aware).
     */
    public function checkEarlyDeparture()
    {
        $clockIn = $this->resolvedClockInDateTime();
        $clockOut = $this->resolvedClockOutDateTime($clockIn);
        $window = $this->shiftWindow();

        if ($clockOut && $window && $this->attendancePolicy) {
            [, $shiftEnd] = $window;
            $mins = $this->attendancePolicy->earlyDepartureMinutes($clockOut, $shiftEnd);
            $this->early_departure_minutes = $mins;
            $this->is_early_departure = $mins > 0;
        } else {
            $this->early_departure_minutes = 0;
            $this->is_early_departure = false;
        }

        return $this->is_early_departure;
    }

    /**
     * Process complete attendance - calculate everything automatically.
     *
     * Optional $pairedWorkHours / $pairedBreakHours allow `in_out_pairs`
     * multi-punch mode to pass exact summed working/break durations while
     * reusing overtime, grace, late/early and status calculation cleanly.
     */
    public function processAttendance(?float $pairedWorkHours = null, ?float $pairedBreakHours = null)
    {
        // Step 1: Calculate total working hours (or accept multi-punch paired sum)
        if ($pairedWorkHours !== null) {
            $this->attributes['total_hours'] = round(max(0, $pairedWorkHours), 2);
            $this->attributes['break_hours'] = round(max(0, $pairedBreakHours ?? 0), 2);
        } else {
            $this->calculateTotalHours();
        }

        // Clear missing clock-out once both times exist
        if ($this->clock_in && $this->clock_out) {
            $this->is_missing_clock_out = false;
        }

        // Step 2: Calculate overtime using shift working hours dynamically
        if ($this->shift && $this->shift->working_hours > 0) {
            $standardHours = $this->shift->working_hours;
        } else {
            $standardHours = 8;
        }

        $this->overtime_hours = max(0, round($this->total_hours - $standardHours, 2));

        // Step 3: Calculate overtime amount using policy
        if ($this->overtime_hours > 0 && $this->attendancePolicy) {
            $this->overtime_amount = round($this->overtime_hours * $this->attendancePolicy->overtime_rate_per_hour, 2);
        } else {
            $this->overtime_amount = 0;
        }

        // Step 4: Check late arrival and early departure
        if ($this->clock_in) {
            $this->checkLateArrival();
        }
        if ($this->clock_in && $this->clock_out) {
            $this->checkEarlyDeparture();
        }

        // Step 5: Set status based on holiday or total hours
        if ($this->is_holiday) {
            $this->status = 'holiday';
        } elseif ($this->exists || $this->isDirty('clock_in') || $this->isDirty('clock_out')) {
            $fullDayThreshold = $standardHours;
            $halfDayThreshold = $standardHours / 2;

            if ($this->total_hours >= $fullDayThreshold) {
                $this->status = 'present';
            } elseif ($this->total_hours >= $halfDayThreshold) {
                $this->status = 'half_day';
            } elseif ($this->total_hours > 0) {
                $this->status = 'absent';
            } else {
                $this->status = 'absent';
            }
        }

        $this->save();
    }

    /**
     * Format clock in time for frontend (H:i format).
     */
    public function getClockInAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('H:i') : null;
    }

    /**
     * Format clock out time for frontend (H:i format).
     */
    public function getClockOutAttribute($value)
    {
        return $value ? Carbon::parse($value)->format('H:i') : null;
    }
}
