<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\PunchPairingMode;
use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\PunchType;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegularization;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceUser;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricEnrollment;
use App\Models\BiometricPunch;
use App\Models\Employee;
use App\Models\LeaveApplication;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Integrates canonical biometric punches with the existing HRMS attendance
 * calculation engine without duplicating calculation logic.
 *
 * Rules supported:
 *  1. First valid punch = clock-in, last valid punch = clock-out (`first_last`)
 *  2. Multiple punches (`in_out_pairs` sums work/break across pairs; `device_state` respects IN/OUT)
 *  3. Multiple shifts & overnight shifts (via ShiftResolver)
 *  4. Grace periods, late arrival, early departure, overtime (via AttendanceRecord::processAttendance())
 *  5. Missing clock-out detection (shift end + policy grace hours)
 *  6. Protection of manual HR corrections, approved regularizations, leaves & holidays
 */
class AttendanceProcessor
{
    public function __construct(
        private readonly EmployeeSelector $employees,
        private readonly ShiftResolver $shifts,
    ) {
    }

    public function resolveEmployee(BiometricDevice $device, string $deviceUserId): ?Employee
    {
        $raw = trim($deviceUserId);
        if ($raw === '') {
            return null;
        }
        $trimmed = ltrim($raw, '0') ?: $raw;
        $codes = array_values(array_unique([$raw, $trimmed]));

        // 0. Normalized employee <-> device mapping (device-specific first, then company-wide)
        $mappedEmpId = BiometricEmployeeMapping::where('company_id', $device->company_id)
            ->where('is_active', true)
            ->whereIn('device_user_id', $codes)
            ->where(fn ($q) => $q->where('device_id', $device->id)->orWhereNull('device_id'))
            ->orderByRaw('CASE WHEN device_id = ? THEN 0 ELSE 1 END', [$device->id])
            ->value('employee_id');

        if ($mappedEmpId) {
            return Employee::with(['user', 'shift', 'attendancePolicy'])->find($mappedEmpId);
        }

        $enrollmentEmpId = BiometricEnrollment::where('company_id', $device->company_id)
            ->where('device_id', $device->id)
            ->whereIn('device_user_id', $codes)
            ->where('enrollment_status', '!=', 'removed')
            ->value('employee_id');

        if ($enrollmentEmpId) {
            return Employee::with(['user', 'shift', 'attendancePolicy'])->find($enrollmentEmpId);
        }

        $dirEmpId = BiometricDeviceUser::where('company_id', $device->company_id)
            ->where('device_id', $device->id)
            ->whereIn('pin', $codes)
            ->whereNotNull('employee_id')
            ->value('employee_id');

        if ($dirEmpId) {
            return Employee::with(['user', 'shift', 'attendancePolicy'])->find($dirEmpId);
        }

        $companyUserIds = $this->employees->companyUserIds((int) $device->company_id);

        return Employee::with(['user', 'shift', 'attendancePolicy'])
            ->whereIn('created_by', $companyUserIds)
            ->where(function ($q) use ($codes) {
                $q->whereIn('biometric_emp_id', $codes)
                    ->orWhereIn('employee_id', $codes);
            })
            ->first();
    }

    public function resolveShift(Employee $employee, array $companyUserIds): ?Shift
    {
        if ($employee->shift_id) {
            $assigned = Shift::where('id', $employee->shift_id)->where('status', 'active')->first();
            if ($assigned) {
                return $assigned;
            }
        }

        return Shift::whereIn('created_by', $companyUserIds)->where('status', 'active')->first();
    }

    public function resolvePolicy(Employee $employee, array $companyUserIds): ?AttendancePolicy
    {
        if ($employee->attendance_policy_id) {
            $assigned = AttendancePolicy::where('id', $employee->attendance_policy_id)->where('status', 'active')->first();
            if ($assigned) {
                return $assigned;
            }
        }

        return AttendancePolicy::whereIn('created_by', $companyUserIds)->where('status', 'active')->first();
    }

    /**
     * Resolve both shift and attendance date for an incoming punch.
     *
     * @return array{shift: ?Shift, attendance_date: string}
     */
    public function resolveShiftAndDateForPunch(Employee $employee, CarbonInterface $localPunchTime, array $companyUserIds): array
    {
        $policy = $this->resolvePolicy($employee, $companyUserIds);

        return $this->shifts->resolveForPunch($employee, $localPunchTime, $companyUserIds, $policy);
    }

    public function resolveAttendanceDate(CarbonInterface $localPunchTime, ?Shift $shift): string
    {
        return $this->shifts->attendanceDateForShift($localPunchTime, $shift);
    }

    /**
     * Recalculate daily attendance for (employee, attendanceDate) using all stored
     * biometric punches, policy rules, shift rules, and AttendanceRecord::processAttendance().
     */
    public function processEmployeeDate(
        Employee $employee,
        string $attendanceDate,
        int $companyId,
        bool $forceReleaseOverride = false,
        ?CarbonInterface $referenceNow = null,
    ): ?AttendanceRecord {
        if (!$employee->user_id) {
            return null;
        }

        $companyUserIds = $this->employees->companyUserIds($companyId);
        $policy = $this->resolvePolicy($employee, $companyUserIds);
        $windowSec = $policy && $policy->duplicate_punch_window_sec !== null
            ? max(0, (int) $policy->duplicate_punch_window_sec)
            : max(0, (int) config('biometric.sync.duplicate_window_seconds', 60));
        $pairingMode = PunchPairingMode::tryFrom((string) ($policy?->punch_pairing_mode ?? 'first_last')) ?? PunchPairingMode::FirstLast;
        $missingGraceHours = max(1, (int) ($policy?->missing_clock_out_grace_hours ?? 4));

        return DB::transaction(function () use (
            $employee,
            $attendanceDate,
            $companyId,
            $companyUserIds,
            $policy,
            $windowSec,
            $pairingMode,
            $missingGraceHours,
            $forceReleaseOverride,
            $referenceNow
        ) {
            $punches = BiometricPunch::where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->whereDate('attendance_date', $attendanceDate)
                ->orderBy('punched_at_utc')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($punches->isEmpty()) {
                return null;
            }

            // 1. Suppress rapid duplicate taps within configured bounce window
            $effective = collect();
            $lastKeptUtc = null;
            foreach ($punches as $punch) {
                if ($windowSec > 0 && $lastKeptUtc !== null && abs($punch->punched_at_utc->diffInSeconds($lastKeptUtc)) <= $windowSec) {
                    $punch->forceFill([
                        'processing_status' => PunchProcessingStatus::DuplicateSuppressed,
                        'processing_note' => "Suppressed: within {$windowSec}s duplicate punch window.",
                        'processed_at' => now(),
                    ])->saveQuietly();
                    continue;
                }
                $effective->push($punch);
                $lastKeptUtc = $punch->punched_at_utc;
            }

            if ($effective->isEmpty()) {
                return null;
            }

            // 2. Lock or create AttendanceRecord for this employee + attendanceDate
            $existing = AttendanceRecord::where('employee_id', $employee->user_id)
                ->whereDate('date', $attendanceDate)
                ->lockForUpdate()
                ->first();

            // 3. Respect manual corrections, approved regularizations, approved leaves & holidays
            if (!$forceReleaseOverride) {
                $protectionReason = $this->checkProtectedRecord($employee, $attendanceDate, $existing);
                if ($protectionReason !== null) {
                    BiometricPunch::whereIn('id', $effective->pluck('id'))->update([
                        'attendance_record_id' => $existing?->id,
                        'processing_status' => PunchProcessingStatus::ConflictSkipped->value,
                        'processing_note' => $protectionReason,
                        'processed_at' => now(),
                        'updated_at' => now(),
                    ]);

                    return $existing;
                }
            }

            // 4. Resolve shift (supports multiple shifts via auto_detect_shift!)
            /** @var BiometricPunch $firstPunch */
            $firstPunch = $effective->first();
            $resolved = $this->shifts->resolveForPunch($employee, $firstPunch->punched_at_local, $companyUserIds, $policy);
            $shift = $resolved['shift'] ?? $this->resolveShift($employee, $companyUserIds);

            // 5. Pair punches according to policy's punch_pairing_mode
            $paired = $this->pairPunches($effective, $pairingMode);

            $record = $existing ?? new AttendanceRecord([
                'employee_id' => $employee->user_id,
                'date' => $attendanceDate,
                'created_by' => $employee->created_by ?? $companyId,
                'is_weekend' => Carbon::parse($attendanceDate)->isWeekend(),
            ]);

            if ($forceReleaseOverride) {
                $record->is_manual_override = false;
            }

            $record->shift_id = $shift?->id ?? $record->shift_id;
            $record->attendance_policy_id = $policy?->id ?? $record->attendance_policy_id;
            $record->biometric_id = (string) $paired['in_punch']->id;
            $record->first_punch_id = $paired['in_punch']->id;
            $record->last_punch_id = $paired['out_punch']?->id ?? $paired['in_punch']->id;
            $record->punch_count = $effective->count();
            $record->source = 'biometric';
            $record->clock_in = $paired['in_punch']->punched_at_local->format('H:i:s');
            $record->clock_in_at = $paired['in_punch']->punched_at_local;

            if ($paired['out_punch']) {
                $record->clock_out = $paired['out_punch']->punched_at_local->format('H:i:s');
                $record->clock_out_at = $paired['out_punch']->punched_at_local;
                $record->is_missing_clock_out = $paired['has_unpaired_trailing']
                    && $this->shifts->isMissingClockOutOverdue($attendanceDate, $shift, $missingGraceHours, $referenceNow);
            } else {
                $record->clock_out = null;
                $record->clock_out_at = null;
                $record->is_missing_clock_out = $this->shifts->isMissingClockOutOverdue($attendanceDate, $shift, $missingGraceHours, $referenceNow);
            }

            $record->save();

            // 6. Delegate all attendance calculations to existing AttendanceRecord::processAttendance()
            $record->refresh();
            if ($record->clock_in && $record->clock_out) {
                $record->processAttendance($paired['paired_work_hours'], $paired['paired_break_hours']);
                if ($paired['has_unpaired_trailing'] && $this->shifts->isMissingClockOutOverdue($attendanceDate, $shift, $missingGraceHours, $referenceNow)) {
                    $record->forceFill(['is_missing_clock_out' => true])->saveQuietly();
                }
            } else {
                $record->checkLateArrival();
                $record->status = 'present';
                $record->save();
            }

            BiometricPunch::whereIn('id', $effective->pluck('id'))->update([
                'attendance_record_id' => $record->id,
                'processing_status' => PunchProcessingStatus::Processed->value,
                'processing_note' => $record->is_missing_clock_out ? 'Clock-in recorded; missing clock-out flagged.' : null,
                'processed_at' => now(),
                'updated_at' => now(),
            ]);

            return $record->fresh();
        });
    }

    /**
     * Pair multiple valid punches into clock-in, clock-out, and optional exact
     * multi-segment work/break hours.
     *
     * @param Collection<int, BiometricPunch> $effective Ordered ascending by punched_at_utc
     * @return array{in_punch: BiometricPunch, out_punch: ?BiometricPunch, paired_work_hours: ?float, paired_break_hours: ?float, has_unpaired_trailing: bool}
     */
    public function pairPunches(Collection $effective, PunchPairingMode $mode): array
    {
        $first = $effective->first();
        $last = $effective->last();

        if ($effective->count() === 1) {
            return [
                'in_punch' => $first,
                'out_punch' => null,
                'paired_work_hours' => null,
                'paired_break_hours' => null,
                'has_unpaired_trailing' => true,
            ];
        }

        if ($mode === PunchPairingMode::DeviceState) {
            $explicitIn = $effective->first(fn (BiometricPunch $p) => $p->punch_type === PunchType::In) ?? $first;
            $explicitOut = $effective
                ->filter(fn (BiometricPunch $p) => $p->id !== $explicitIn->id && $p->punched_at_utc->gte($explicitIn->punched_at_utc) && $p->punch_type === PunchType::Out)
                ->last();

            // Fall back to last punch if no explicit OUT punches were tagged (e.g. UNKNOWN device states)
            $out = $explicitOut ?? ($last->id !== $explicitIn->id ? $last : null);

            return [
                'in_punch' => $explicitIn,
                'out_punch' => $out,
                'paired_work_hours' => null,
                'paired_break_hours' => null,
                'has_unpaired_trailing' => $out === null,
            ];
        }

        if ($mode === PunchPairingMode::InOutPairs) {
            $list = $effective->values()->all();
            $count = count($list);
            $workMinutes = 0;
            $breakMinutes = 0;
            $lastPairedOut = null;

            for ($i = 0; $i + 1 < $count; $i += 2) {
                /** @var BiometricPunch $in */
                $in = $list[$i];
                /** @var BiometricPunch $out */
                $out = $list[$i + 1];
                $workMinutes += max(0, (int) $in->punched_at_utc->diffInMinutes($out->punched_at_utc));
                if ($lastPairedOut !== null) {
                    $breakMinutes += max(0, (int) $lastPairedOut->punched_at_utc->diffInMinutes($in->punched_at_utc));
                }
                $lastPairedOut = $out;
            }

            return [
                'in_punch' => $list[0],
                'out_punch' => $lastPairedOut,
                'paired_work_hours' => round($workMinutes / 60, 2),
                'paired_break_hours' => round($breakMinutes / 60, 2),
                'has_unpaired_trailing' => ($count % 2) === 1,
            ];
        }

        // Default: FirstLast
        return [
            'in_punch' => $first,
            'out_punch' => $last->id !== $first->id ? $last : null,
            'paired_work_hours' => null,
            'paired_break_hours' => null,
            'has_unpaired_trailing' => false,
        ];
    }

    /**
     * Scan open attendance records from the last 7 days with clock_in but no
     * clock_out and flag `is_missing_clock_out = true` once shift end + grace elapses.
     */
    public function detectMissingClockOuts(?int $companyId = null, ?CarbonInterface $referenceNow = null): int
    {
        $query = AttendanceRecord::with(['shift', 'attendancePolicy'])
            ->whereNotNull('clock_in')
            ->whereNull('clock_out')
            ->where('is_missing_clock_out', false)
            ->where('is_manual_override', false)
            ->whereNotIn('status', ['on_leave', 'holiday', 'absent'])
            ->whereDate('date', '>=', now()->subDays(7)->toDateString());

        if ($companyId !== null) {
            $query->whereIn('created_by', $this->employees->companyUserIds($companyId));
        }

        $flagged = 0;
        foreach ($query->get() as $record) {
            $grace = max(1, (int) ($record->attendancePolicy?->missing_clock_out_grace_hours ?? 4));
            $dateStr = Carbon::parse($record->date)->toDateString();

            if ($this->shifts->isMissingClockOutOverdue($dateStr, $record->shift, $grace, $referenceNow)) {
                $record->forceFill(['is_missing_clock_out' => true])->saveQuietly();
                $flagged++;
            }
        }

        return $flagged;
    }

    public function reprocessPunch(BiometricPunch $punch, bool $forceReleaseOverride = false): BiometricPunch
    {
        $punch->loadMissing(['device', 'employee']);

        $employee = $punch->employee;
        if (!$employee && $punch->device) {
            $employee = $this->resolveEmployee($punch->device, $punch->device_user_id);
        }

        if (!$employee || !$employee->user_id) {
            $punch->forceFill([
                'processing_status' => PunchProcessingStatus::UnmatchedEmployee,
                'processing_note' => "No employee mapped for device user ID '{$punch->device_user_id}'.",
                'processed_at' => now(),
            ])->save();

            return $punch;
        }

        $companyUserIds = $this->employees->companyUserIds((int) $punch->company_id);
        $resolved = $this->resolveShiftAndDateForPunch($employee, $punch->punched_at_local, $companyUserIds);
        $attendanceDate = $resolved['attendance_date'];

        $punch->forceFill([
            'employee_id' => $employee->id,
            'user_id' => $employee->user_id,
            'attendance_date' => $attendanceDate,
        ])->save();

        $this->processEmployeeDate($employee, $attendanceDate, (int) $punch->company_id, $forceReleaseOverride);

        return $punch->fresh();
    }

    private function checkProtectedRecord(Employee $employee, string $attendanceDate, ?AttendanceRecord $existing): ?string
    {
        if ($existing) {
            if ($existing->is_manual_override) {
                return 'Skipped: attendance record was manually edited by HR.';
            }

            if (in_array($existing->status, ['on_leave', 'holiday'], true)) {
                return "Skipped: attendance record is marked as '{$existing->status}'.";
            }

            $hasApprovedReg = AttendanceRegularization::where('attendance_record_id', $existing->id)
                ->where('status', 'approved')
                ->exists();
            if ($hasApprovedReg) {
                return 'Skipped: attendance record has an approved regularization.';
            }
        }

        $hasApprovedLeave = LeaveApplication::where('employee_id', $employee->user_id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $attendanceDate)
            ->whereDate('end_date', '>=', $attendanceDate)
            ->exists();

        if ($hasApprovedLeave) {
            return 'Skipped: employee has approved leave on this date.';
        }

        return null;
    }
}
