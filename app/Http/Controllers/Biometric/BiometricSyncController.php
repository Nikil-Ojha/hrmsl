<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\Enums\PunchPairingMode;
use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\PunchSource;
use App\Biometric\Enums\PunchType;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncFailureStatus;
use App\Biometric\Services\AttendanceProcessor;
use App\Biometric\Services\BiometricAuditLogger;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Services\SyncFailureRetryService;
use App\Biometric\Support\Tenant;
use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\AttendanceRegularization;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use App\Models\BiometricSyncFailure;
use App\Models\BiometricSyncRun;
use App\Models\Employee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class BiometricSyncController extends Controller
{
    public function __construct(
        private readonly AttendanceProcessor $processor,
        private readonly SyncFailureRetryService $retries,
        private readonly EmployeeSelector $employees,
        private readonly BiometricAuditLogger $audit,
    ) {
    }

    public function index(Request $request): Response
    {
        $companyId = (int) Tenant::companyId();
        $companyUserIds = $this->employees->companyUserIds($companyId);
        $isSuperadmin = Auth::user()->hasRole('superadmin');

        // 1. Processed Daily Attendance (separate from raw biometric logs!)
        $processedBase = AttendanceRecord::query()
            ->when(!$isSuperadmin, fn ($q) => $q->whereIn('created_by', $companyUserIds));

        $processedQuery = (clone $processedBase)
            ->with(['employee:id,name,email', 'employee.employee:id,user_id,employee_id', 'shift:id,name,start_time,end_time,is_night_shift', 'attendancePolicy:id,name,punch_pairing_mode']);

        if ($search = trim((string) $request->input('search'))) {
            $processedQuery->whereHas('employee', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"));
        }
        if ($request->filled('date')) {
            $processedQuery->whereDate('date', $request->input('date'));
        }
        if ($request->input('missing_only') === '1') {
            $processedQuery->where('is_missing_clock_out', true);
        }

        $processed = $processedQuery->orderByDesc('date')->orderByDesc('id')
            ->paginate(15, ['*'], 'processed_page')
            ->withQueryString()
            ->through(fn (AttendanceRecord $r) => [
                'id' => $r->id,
                'user_id' => $r->employee_id,
                'employee_profile_id' => $r->employee?->employee?->id,
                'employee_name' => $r->employee?->name,
                'employee_code' => $r->employee?->employee?->employee_id,
                'date' => Carbon::parse($r->date)->toDateString(),
                'shift_name' => $r->shift?->name,
                'is_night_shift' => (bool) $r->shift?->is_night_shift,
                'policy_name' => $r->attendancePolicy?->name,
                'pairing_mode' => $r->attendancePolicy?->punch_pairing_mode ?? 'first_last',
                'clock_in' => $r->clock_in,
                'clock_out' => $r->clock_out,
                'total_hours' => (float) $r->total_hours,
                'break_hours' => (float) $r->break_hours,
                'overtime_hours' => (float) $r->overtime_hours,
                'overtime_amount' => (float) $r->overtime_amount,
                'is_late' => (bool) $r->is_late,
                'late_minutes' => (int) ($r->late_minutes ?? 0),
                'is_early_departure' => (bool) $r->is_early_departure,
                'early_departure_minutes' => (int) ($r->early_departure_minutes ?? 0),
                'is_missing_clock_out' => (bool) $r->is_missing_clock_out,
                'status' => $r->status,
                'source' => $r->source ?? 'manual',
                'is_manual_override' => (bool) $r->is_manual_override,
                'punch_count' => (int) ($r->punch_count ?? 0),
            ]);

        // 2. Raw Biometric Attendance Events (biometric_punches)
        $punchBase = BiometricPunch::visibleTo();
        $punchQuery = (clone $punchBase)->with([
            'employee.user:id,name',
            'device:id,name,serial_number',
            'attendanceRecord:id,date,clock_in,clock_out,status,total_hours',
        ]);

        if ($search) {
            $punchQuery->where(function ($q) use ($search) {
                $q->where('device_user_id', 'like', "%{$search}%")
                    ->orWhere('original_device_log_id', 'like', "%{$search}%")
                    ->orWhereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('device', fn ($d) => $d->where('name', 'like', "%{$search}%")->orWhere('serial_number', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('device_id') && $request->input('device_id') !== '_empty_') {
            $punchQuery->where('device_id', (int) $request->input('device_id'));
        }
        if ($request->filled('status') && $request->input('status') !== '_empty_') {
            $punchQuery->where('processing_status', $request->input('status'));
        }
        if ($request->filled('source') && $request->input('source') !== '_empty_') {
            $punchQuery->where('source', $request->input('source'));
        }
        if ($request->filled('date')) {
            $punchQuery->whereDate('attendance_date', $request->input('date'));
        }

        $punches = $punchQuery->orderByDesc('punched_at_utc')->orderByDesc('id')
            ->paginate(20, ['*'], 'punches_page')
            ->withQueryString()
            ->through(fn (BiometricPunch $p) => [
                'id' => $p->id,
                'employee_id' => $p->employee_id,
                'user_id' => $p->user_id,
                'employee_name' => $p->employee?->user?->name,
                'employee_code' => $p->employee?->employee_id,
                'device_id' => $p->device_id,
                'device_name' => $p->device?->name,
                'device_serial' => $p->device?->serial_number,
                'device_user_id' => $p->device_user_id,
                'punched_at_local' => $p->punched_at_local?->format('Y-m-d H:i:s'),
                'punched_at_utc' => $p->punched_at_utc?->toIso8601String(),
                'device_timezone' => $p->device_timezone,
                'punch_type' => $p->punch_type->value,
                'punch_type_label' => $p->punch_type->label(),
                'verification_method' => $p->verification_method->value,
                'verification_method_label' => $p->verification_method->label(),
                'original_device_log_id' => $p->original_device_log_id,
                'source' => $p->source->value,
                'source_label' => $p->source->label(),
                'synced_at' => $p->synced_at?->toIso8601String(),
                'attendance_date' => $p->attendance_date?->toDateString(),
                'attendance_record_id' => $p->attendance_record_id,
                'processing_status' => $p->processing_status->value,
                'processing_status_label' => $p->processing_status->label(),
                'processing_note' => $p->processing_note,
                'raw_metadata' => $p->raw_metadata,
            ]);

        // 3. Failed Synchronization Logs
        $failureBase = BiometricSyncFailure::visibleTo();
        $failures = (clone $failureBase)
            ->with(['device:id,name,serial_number', 'agent:id,name'])
            ->when($request->filled('failure_status') && $request->input('failure_status') !== '_empty_', fn ($q) => $q->where('status', $request->input('failure_status')))
            ->latest('id')
            ->paginate(15, ['*'], 'failures_page')
            ->withQueryString()
            ->through(fn (BiometricSyncFailure $f) => [
                'id' => $f->id,
                'device_id' => $f->device_id,
                'device_name' => $f->device?->name,
                'device_serial' => $f->device?->serial_number,
                'agent_name' => $f->agent?->name,
                'source' => $f->source->label(),
                'device_user_id' => $f->device_user_id,
                'original_device_log_id' => $f->original_device_log_id,
                'punched_at_raw' => $f->punched_at_raw,
                'error_code' => $f->error_code,
                'error_message' => $f->error_message,
                'status' => $f->status->value,
                'status_label' => $f->status->label(),
                'retry_count' => $f->retry_count,
                'next_retry_at' => $f->next_retry_at?->toIso8601String(),
                'last_attempted_at' => $f->last_attempted_at?->toIso8601String(),
                'resolved_at' => $f->resolved_at?->toIso8601String(),
            ]);

        // 4. Device-wise Synchronization History
        $historyQuery = BiometricSyncRun::with(['device:id,name,serial_number'])
            ->when(!$isSuperadmin, fn ($q) => $q->where('company_id', $companyId))
            ->whereIn('action', [SyncAction::LogSync->value, SyncAction::LogFetch->value])
            ->when($request->filled('device_id') && $request->input('device_id') !== '_empty_', fn ($q) => $q->where('device_id', (int) $request->input('device_id')));

        $history = $historyQuery->latest('id')
            ->paginate(15, ['*'], 'history_page')
            ->withQueryString()
            ->through(fn (BiometricSyncRun $r) => [
                'id' => $r->id,
                'device_id' => $r->device_id,
                'device_name' => $r->device?->name,
                'device_serial' => $r->device?->serial_number,
                'action_label' => $r->action->label(),
                'trigger' => $r->trigger,
                'status' => $r->status->value,
                'total_items' => $r->total_items,
                'success_items' => $r->success_items,
                'failed_items' => $r->failed_items,
                'result' => $r->result,
                'message' => $r->message,
                'started_at' => $r->started_at?->toIso8601String(),
                'finished_at' => $r->finished_at?->toIso8601String(),
            ]);

        return Inertia::render('hr/biometric-sync/index', [
            'processed' => $processed,
            'punches' => $punches,
            'failures' => $failures,
            'history' => $history,
            'stats' => [
                'today_punches' => (clone $punchBase)->whereDate('attendance_date', today())->count(),
                'processed' => (clone $punchBase)->where('processing_status', PunchProcessingStatus::Processed->value)->count(),
                'missing_clock_outs' => (clone $processedBase)->where('is_missing_clock_out', true)->count(),
                'pending_failures' => (clone $failureBase)->where('status', SyncFailureStatus::PendingRetry->value)->count(),
            ],
            'options' => [
                'devices' => BiometricDevice::visibleTo()->orderBy('name')->get(['id', 'name', 'serial_number']),
                'processing_statuses' => PunchProcessingStatus::options(),
                'sources' => PunchSource::options(),
                'punch_types' => PunchType::options(),
                'failure_statuses' => SyncFailureStatus::options(),
                'pairing_modes' => PunchPairingMode::options(),
            ],
            'filters' => $request->only(['search', 'device_id', 'status', 'source', 'date', 'failure_status', 'missing_only', 'tab']),
        ]);
    }

    /**
     * Side-by-side raw biometric logs vs processed daily attendance audit JSON
     * for an employee on a specific attendance date.
     */
    public function rawLogsForDate(int $userId, string $date): JsonResponse
    {
        $companyId = (int) Tenant::companyId();
        $companyUserIds = $this->employees->companyUserIds($companyId);
        $isSuperadmin = Auth::user()->hasRole('superadmin');

        $employee = Employee::with(['user:id,name,email', 'shift', 'attendancePolicy'])
            ->where('user_id', $userId)
            ->when(!$isSuperadmin, fn ($q) => $q->whereIn('created_by', $companyUserIds))
            ->firstOrFail();

        $record = AttendanceRecord::with(['shift', 'attendancePolicy'])
            ->where('employee_id', $userId)
            ->whereDate('date', $date)
            ->first();

        $regularization = $record
            ? AttendanceRegularization::with('approver:id,name')->where('attendance_record_id', $record->id)->latest('id')->first()
            : null;

        $rawPunches = BiometricPunch::with('device:id,name,serial_number')
            ->where('employee_id', $employee->id)
            ->whereDate('attendance_date', $date)
            ->orderBy('punched_at_utc')
            ->orderBy('id')
            ->get()
            ->map(fn (BiometricPunch $p) => [
                'id' => $p->id,
                'device_name' => $p->device?->name,
                'device_serial' => $p->device?->serial_number,
                'device_user_id' => $p->device_user_id,
                'punched_at_local' => $p->punched_at_local?->format('Y-m-d H:i:s'),
                'punched_at_utc' => $p->punched_at_utc?->toIso8601String(),
                'device_timezone' => $p->device_timezone,
                'punch_type' => $p->punch_type->value,
                'punch_type_label' => $p->punch_type->label(),
                'verification_method_label' => $p->verification_method->label(),
                'original_device_log_id' => $p->original_device_log_id,
                'source_label' => $p->source->label(),
                'synced_at' => $p->synced_at?->toIso8601String(),
                'processing_status' => $p->processing_status->value,
                'processing_status_label' => $p->processing_status->label(),
                'processing_note' => $p->processing_note,
                'raw_metadata' => $p->raw_metadata,
            ]);

        return response()->json([
            'employee' => [
                'id' => $employee->id,
                'user_id' => $employee->user_id,
                'name' => $employee->user?->name,
                'employee_code' => $employee->employee_id,
                'biometric_emp_id' => $employee->biometric_emp_id,
            ],
            'attendance_date' => $date,
            'processed_record' => $record ? [
                'id' => $record->id,
                'clock_in' => $record->clock_in,
                'clock_out' => $record->clock_out,
                'total_hours' => (float) $record->total_hours,
                'break_hours' => (float) $record->break_hours,
                'overtime_hours' => (float) $record->overtime_hours,
                'overtime_amount' => (float) $record->overtime_amount,
                'is_late' => (bool) $record->is_late,
                'late_minutes' => (int) ($record->late_minutes ?? 0),
                'is_early_departure' => (bool) $record->is_early_departure,
                'early_departure_minutes' => (int) ($record->early_departure_minutes ?? 0),
                'is_missing_clock_out' => (bool) $record->is_missing_clock_out,
                'status' => $record->status,
                'source' => $record->source ?? 'manual',
                'is_manual_override' => (bool) $record->is_manual_override,
                'punch_count' => (int) ($record->punch_count ?? 0),
                'shift' => $record->shift ? [
                    'name' => $record->shift->name,
                    'start_time' => $record->shift->start_time,
                    'end_time' => $record->shift->end_time,
                    'is_night_shift' => (bool) $record->shift->is_night_shift,
                    'grace_period' => (int) $record->shift->grace_period,
                ] : null,
                'policy' => $record->attendancePolicy ? [
                    'name' => $record->attendancePolicy->name,
                    'punch_pairing_mode' => $record->attendancePolicy->punch_pairing_mode ?? 'first_last',
                    'late_arrival_grace' => (int) $record->attendancePolicy->late_arrival_grace,
                    'early_departure_grace' => (int) $record->attendancePolicy->early_departure_grace,
                    'duplicate_punch_window_sec' => (int) ($record->attendancePolicy->duplicate_punch_window_sec ?? 60),
                    'missing_clock_out_grace_hours' => (int) ($record->attendancePolicy->missing_clock_out_grace_hours ?? 4),
                    'auto_detect_shift' => (bool) $record->attendancePolicy->auto_detect_shift,
                ] : null,
            ] : null,
            'regularization' => $regularization ? [
                'id' => $regularization->id,
                'status' => $regularization->status,
                'requested_clock_in' => $regularization->requested_clock_in,
                'requested_clock_out' => $regularization->requested_clock_out,
                'reason' => $regularization->reason,
                'approved_by' => $regularization->approver?->name,
                'approved_at' => $regularization->approved_at?->toIso8601String(),
            ] : null,
            'raw_punches' => $rawPunches,
        ]);
    }

    public function recalculateAttendance(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $companyId = (int) Tenant::companyId();
        $companyUserIds = $this->employees->companyUserIds($companyId);
        abort_unless(Auth::user()->hasRole('superadmin') || in_array($record->created_by, $companyUserIds), 404);

        $employee = Employee::where('user_id', $record->employee_id)->first();
        if (!$employee) {
            return redirect()->back()->with('error', __('Employee profile not found.'));
        }

        $before = $record->only(['clock_in', 'clock_out', 'total_hours', 'status', 'is_manual_override', 'is_missing_clock_out']);
        $force = $request->boolean('force_release_override', false);
        $updated = $this->processor->processEmployeeDate($employee, Carbon::parse($record->date)->toDateString(), $companyId, $force);

        $this->audit->log(
            category: $force ? BiometricAuditLogger::CAT_ATTENDANCE_CORRECTION : BiometricAuditLogger::CAT_SYNC,
            action: $force ? 'attendance.override_released' : 'sync.attendance_recalculated',
            description: ($force ? 'Released manual override and recalculated' : 'Recalculated') . " daily attendance for {$employee->user?->name} on " . Carbon::parse($record->date)->toDateString() . '.',
            companyId: $companyId,
            targetType: 'AttendanceRecord',
            targetId: $record->id,
            targetLabel: ($employee->user?->name ?? 'Employee') . ' (' . Carbon::parse($record->date)->toDateString() . ')',
            employeeId: $employee->id,
            before: $before,
            after: $updated?->only(['clock_in', 'clock_out', 'total_hours', 'status', 'is_manual_override', 'is_missing_clock_out']),
            metadata: ['force_release_override' => $force],
        );

        return redirect()->back()->with('success', __('Attendance recalculated from raw biometric punches.'));
    }

    public function detectMissingClockOuts(): RedirectResponse
    {
        $companyId = Auth::user()->hasRole('superadmin') ? null : (int) Tenant::companyId();
        $flagged = $this->processor->detectMissingClockOuts($companyId);

        $this->audit->log(
            category: BiometricAuditLogger::CAT_SYNC,
            action: 'sync.missing_clockouts_scanned',
            description: "Scanned open attendance records for missing clock-outs ({$flagged} flagged).",
            companyId: (int) Tenant::companyId(),
            metadata: ['flagged_count' => $flagged],
        );

        return redirect()->back()->with('success', __(':count open attendance record(s) flagged for missing clock-out.', ['count' => $flagged]));
    }

    public function reprocessPunch(BiometricPunch $punch): RedirectResponse
    {
        abort_unless(BiometricPunch::visibleTo()->whereKey($punch->id)->exists(), 404);

        $updated = $this->processor->reprocessPunch($punch);

        $this->audit->log(
            category: BiometricAuditLogger::CAT_SYNC,
            action: 'sync.punch_reprocessed',
            description: "Reprocessed biometric punch #{$punch->id} (PIN {$punch->device_user_id}) -> {$updated->processing_status->value}.",
            companyId: (int) $punch->company_id,
            targetType: 'BiometricPunch',
            targetId: $punch->id,
            targetLabel: "Punch #{$punch->id}",
            deviceId: $punch->device_id,
            employeeId: $updated->employee_id,
            metadata: ['status' => $updated->processing_status->value, 'note' => $updated->processing_note],
        );

        return redirect()->back()->with(
            $updated->processing_status === PunchProcessingStatus::Processed ? 'success' : 'error',
            $updated->processing_status === PunchProcessingStatus::Processed
                ? __('Attendance event reprocessed and daily attendance updated.')
                : ($updated->processing_note ?: __('Could not process attendance event.'))
        );
    }

    public function retryFailure(BiometricSyncFailure $failure): RedirectResponse
    {
        abort_unless(BiometricSyncFailure::visibleTo()->whereKey($failure->id)->exists(), 404);

        $res = $this->retries->retry($failure);
        $this->audit->log(
            category: BiometricAuditLogger::CAT_SYNC,
            action: 'sync.failure_retried',
            description: "Retried failed sync entry #{$failure->id} (" . ($res['resolved'] ? 'resolved' : 'still pending') . ').',
            companyId: (int) $failure->company_id,
            targetType: 'BiometricSyncFailure',
            targetId: $failure->id,
            targetLabel: "Failure #{$failure->id}",
            deviceId: $failure->device_id,
            metadata: ['resolved' => (bool) $res['resolved']],
        );

        return redirect()->back()->with($res['resolved'] ? 'success' : 'error', $res['message']);
    }

    public function retryAllFailures(): RedirectResponse
    {
        $failures = BiometricSyncFailure::visibleTo()
            ->where('status', SyncFailureStatus::PendingRetry->value)
            ->orderBy('id')
            ->limit(100)
            ->get();

        $resolved = 0;
        foreach ($failures as $failure) {
            if ($this->retries->retry($failure)['resolved']) {
                $resolved++;
            }
        }

        return redirect()->back()->with('success', __(':resolved of :total failed sync log(s) resolved.', [
            'resolved' => $resolved,
            'total' => $failures->count(),
        ]));
    }

    public function dismissFailure(BiometricSyncFailure $failure): RedirectResponse
    {
        abort_unless(BiometricSyncFailure::visibleTo()->whereKey($failure->id)->exists(), 404);

        $failure->forceFill(['status' => SyncFailureStatus::Abandoned, 'next_retry_at' => null])->save();

        return redirect()->back()->with('success', __('Failed synchronization log dismissed.'));
    }
}
