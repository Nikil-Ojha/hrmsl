<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\EnrollmentStatus;
use App\Biometric\Enums\PunchProcessingStatus;
use App\Biometric\Enums\SyncFailureStatus;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\ProviderRegistry;
use App\Biometric\Support\Tenant;
use App\Models\AttendanceRecord;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricPunch;
use App\Models\BiometricSyncFailure;
use App\Models\BiometricSyncRun;
use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DashboardService
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly EmployeeSelector $employees,
    ) {
    }

    /**
     * Evaluate whether an active device requires administrator attention and why.
     *
     * @return list<string>
     */
    public function evaluateAttention(
        BiometricDevice $device,
        int $pendingFailures = 0,
        ?BiometricSyncRun $latestRun = null,
    ): array {
        if (!$device->is_active) {
            return [];
        }

        $reasons = [];
        $effectiveStatus = $device->effective_connection_status;

        if ($effectiveStatus === ConnectionStatus::Error->value) {
            $reasons[] = 'Device reported a communication error';
        } elseif ($effectiveStatus === ConnectionStatus::Offline->value) {
            $reasons[] = 'Device is offline (heartbeat expired)';
        } elseif ($effectiveStatus === ConnectionStatus::Unknown->value) {
            $reasons[] = 'Device has not communicated yet';
        }

        if ($pendingFailures > 0) {
            $reasons[] = "{$pendingFailures} failed sync event(s) pending retry";
        }

        if ($latestRun && $latestRun->status === SyncStatus::Failed) {
            $reasons[] = 'Latest ' . strtolower($latestRun->action->label()) . ' failed';
        }

        if (
            $device->last_error
            && (!$device->last_successful_sync_at || ($device->last_error_at && $device->last_error_at->gt($device->last_successful_sync_at)))
        ) {
            $reasons[] = 'Error: ' . Str::limit((string) $device->last_error, 80);
        }

        if ($device->agent && $device->agent->effective_connection_status !== ConnectionStatus::Online->value) {
            $reasons[] = "Local agent '{$device->agent->name}' is {$device->agent->effective_connection_status}";
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Build the full Biometric Dashboard payload scoped to the current user/tenant.
     *
     * @return array<string, mixed>
     */
    public function build(Request $request): array
    {
        $user = Auth::user();
        $isSuperadmin = $user?->hasRole('superadmin') ?? false;
        $companyId = (int) Tenant::companyId($user);
        $companyUserIds = $this->employees->companyUserIds($companyId);

        // 1. Load all visible devices with relations & active enrollment counts (no N+1)
        $allDevices = BiometricDevice::visibleTo($user)
            ->with([
                'branch:id,name',
                'department:id,name',
                'agent:id,name,agent_identifier,status,connection_status,last_seen_at',
            ])
            ->withCount([
                'enrollments as enrolled_employees_count' => fn ($q) => $q->where('enrollment_status', '!=', EnrollmentStatus::Removed->value),
            ])
            ->orderBy('name')
            ->get();

        $deviceIds = $allDevices->pluck('id')->all();

        // Pending sync failure counts per device
        $pendingFailuresByDevice = empty($deviceIds)
            ? collect()
            : BiometricSyncFailure::whereIn('device_id', $deviceIds)
                ->where('status', SyncFailureStatus::PendingRetry->value)
                ->select('device_id', DB::raw('COUNT(*) as cnt'))
                ->groupBy('device_id')
                ->pluck('cnt', 'device_id');

        // Latest sync run per device
        $latestRunIds = empty($deviceIds)
            ? collect()
            : BiometricSyncRun::whereIn('device_id', $deviceIds)
                ->selectRaw('MAX(id) as max_id')
                ->groupBy('device_id')
                ->pluck('max_id');

        $latestRunsByDevice = $latestRunIds->isEmpty()
            ? collect()
            : BiometricSyncRun::whereIn('id', $latestRunIds)->get()->keyBy('device_id');

        // 2. Transform every device and tally KPI status counts in a single pass
        $onlineCount = 0;
        $offlineCount = 0;
        $errorCount = 0;
        $unknownCount = 0;
        $inactiveCount = 0;
        $attentionCount = 0;

        $rows = $allDevices->map(function (BiometricDevice $d) use (
            $pendingFailuresByDevice,
            $latestRunsByDevice,
            &$onlineCount,
            &$offlineCount,
            &$errorCount,
            &$unknownCount,
            &$inactiveCount,
            &$attentionCount,
        ) {
            $effectiveStatus = $d->effective_connection_status;
            $pendingFailures = (int) ($pendingFailuresByDevice->get($d->id) ?? 0);
            /** @var ?BiometricSyncRun $latestRun */
            $latestRun = $latestRunsByDevice->get($d->id);
            $attentionReasons = $this->evaluateAttention($d, $pendingFailures, $latestRun);
            $requiresAttention = count($attentionReasons) > 0;

            if (!$d->is_active) {
                $inactiveCount++;
            } else {
                match ($effectiveStatus) {
                    ConnectionStatus::Online->value => $onlineCount++,
                    ConnectionStatus::Offline->value => $offlineCount++,
                    ConnectionStatus::Error->value => $errorCount++,
                    default => $unknownCount++,
                };
                if ($requiresAttention) {
                    $attentionCount++;
                }
            }

            $meta = $this->providers->describe($d->protocol);
            $lastComm = $this->maxTimestamp($d->last_seen_at, $d->last_log_received_at);
            $lastSync = $d->last_successful_sync_at ?? $d->last_sync_at;

            return [
                'id' => $d->id,
                'name' => $d->name,
                'serial_number' => $d->serial_number,
                'device_identifier' => $d->device_identifier,
                'manufacturer' => $d->manufacturer,
                'model' => $d->model,
                'device_type_label' => $d->device_type?->label() ?? (string) $d->device_type,
                'protocol' => $d->protocol,
                'protocol_label' => $meta['label'] ?? $d->protocol,
                'connection_type_label' => $d->connection_type?->label() ?? (string) $d->connection_type,
                'ip_address' => $d->ip_address,
                'port' => $d->port,
                'branch_id' => $d->branch_id,
                'branch_name' => $d->branch?->name,
                'department_name' => $d->department?->name,
                'agent_id' => $d->agent_id,
                'agent_name' => $d->agent?->name,
                'agent_status' => $d->agent?->effective_connection_status,
                'is_active' => (bool) $d->is_active,
                'status' => $effectiveStatus,
                'requires_attention' => $requiresAttention,
                'attention_reasons' => $attentionReasons,
                'last_communication_at' => $lastComm?->toIso8601String(),
                'last_communication_ts' => $lastComm?->timestamp ?? 0,
                'last_seen_ip' => $d->last_seen_ip,
                'last_sync_at' => $lastSync?->toIso8601String(),
                'last_sync_ts' => $lastSync?->timestamp ?? 0,
                'last_successful_sync_at' => $d->last_successful_sync_at?->toIso8601String(),
                'last_sync_status' => $latestRun?->status?->value,
                'last_sync_action' => $latestRun?->action?->label(),
                'pending_failures_count' => $pendingFailures,
                'enrolled_employees_count' => (int) ($d->enrolled_employees_count ?? 0),
                'capabilities' => $meta['capabilities'] ?? [
                    'supports_tcp_probe' => false,
                    'time_sync' => false,
                    'employee_sync' => false,
                    'log_fetch' => false,
                ],
            ];
        });

        // 3. Enrollment, punch, sync-run, and failure KPIs
        $enrollmentBase = BiometricEnrollment::visibleTo($user)
            ->where('enrollment_status', '!=', EnrollmentStatus::Removed->value);
        $enrolledEmployeesCount = (clone $enrollmentBase)->distinct('employee_id')->count('employee_id');
        $totalDeviceEnrollments = (clone $enrollmentBase)->count();

        $punchBase = BiometricPunch::visibleTo($user);
        $todayPunches = (clone $punchBase)->whereDate('attendance_date', today())->count();
        $todayProcessedPunches = (clone $punchBase)
            ->whereDate('attendance_date', today())
            ->where('processing_status', PunchProcessingStatus::Processed->value)
            ->count();

        $missingClockOuts = AttendanceRecord::query()
            ->when(!$isSuperadmin, fn ($q) => $q->whereIn('created_by', $companyUserIds))
            ->where('is_missing_clock_out', true)
            ->count();

        // Last successful synchronization across visible devices
        $lastSuccessRun = empty($deviceIds)
            ? null
            : BiometricSyncRun::with('device:id,name')
                ->whereIn('device_id', $deviceIds)
                ->whereIn('status', [SyncStatus::Success->value, SyncStatus::Partial->value])
                ->orderByDesc('finished_at')
                ->orderByDesc('id')
                ->first();

        $latestDeviceSync = $allDevices
            ->filter(fn (BiometricDevice $d) => $d->last_successful_sync_at !== null)
            ->sortByDesc('last_successful_sync_at')
            ->first();

        $lastSuccessAt = $lastSuccessRun?->finished_at ?? $latestDeviceSync?->last_successful_sync_at;
        $lastSuccessDevice = $lastSuccessRun?->device?->name ?? $latestDeviceSync?->name;
        $lastSuccessAction = $lastSuccessRun?->action?->label();

        if ($latestDeviceSync?->last_successful_sync_at && (!$lastSuccessAt || $latestDeviceSync->last_successful_sync_at->gt($lastSuccessAt))) {
            $lastSuccessAt = $latestDeviceSync->last_successful_sync_at;
            $lastSuccessDevice = $latestDeviceSync->name;
            $lastSuccessAction = null;
        }

        // Failed synchronization counts
        $failureBase = BiometricSyncFailure::visibleTo($user);
        $pendingSyncFailures = (clone $failureBase)
            ->where('status', SyncFailureStatus::PendingRetry->value)
            ->count();

        $failedRuns24h = empty($deviceIds)
            ? 0
            : BiometricSyncRun::whereIn('device_id', $deviceIds)
                ->where('status', SyncStatus::Failed->value)
                ->where('started_at', '>=', now()->subHours(24))
                ->count();

        $failedSyncCount = $pendingSyncFailures + $failedRuns24h;

        // 4. Filter, sort & paginate the Device Activity Table
        $filtered = $rows;

        if ($search = mb_strtolower(trim((string) $request->input('search')))) {
            $filtered = $filtered->filter(function (array $row) use ($search) {
                return str_contains(mb_strtolower((string) $row['name']), $search)
                    || str_contains(mb_strtolower((string) $row['serial_number']), $search)
                    || str_contains(mb_strtolower((string) $row['device_identifier']), $search)
                    || str_contains(mb_strtolower((string) ($row['branch_name'] ?? '')), $search)
                    || str_contains(mb_strtolower((string) ($row['ip_address'] ?? '')), $search);
            });
        }

        if ($request->filled('branch_id') && $request->input('branch_id') !== '_empty_') {
            $branchId = (int) $request->input('branch_id');
            $filtered = $filtered->filter(fn (array $r) => (int) ($r['branch_id'] ?? 0) === $branchId);
        }

        $health = (string) $request->input('health', 'all');
        $filtered = match ($health) {
            'online' => $filtered->filter(fn (array $r) => $r['is_active'] && $r['status'] === ConnectionStatus::Online->value),
            'offline' => $filtered->filter(fn (array $r) => $r['is_active'] && $r['status'] === ConnectionStatus::Offline->value),
            'attention' => $filtered->filter(fn (array $r) => $r['requires_attention']),
            'inactive' => $filtered->filter(fn (array $r) => !$r['is_active']),
            default => $filtered,
        };

        $sortField = (string) $request->input('sort_field', 'attention');
        $sortDir = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $filtered = match ($sortField) {
            'name' => $sortDir === 'asc' ? $filtered->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE) : $filtered->sortByDesc('name', SORT_NATURAL | SORT_FLAG_CASE),
            'serial_number' => $sortDir === 'asc' ? $filtered->sortBy('serial_number', SORT_NATURAL | SORT_FLAG_CASE) : $filtered->sortByDesc('serial_number', SORT_NATURAL | SORT_FLAG_CASE),
            'branch_name' => $sortDir === 'asc' ? $filtered->sortBy('branch_name', SORT_NATURAL | SORT_FLAG_CASE) : $filtered->sortByDesc('branch_name', SORT_NATURAL | SORT_FLAG_CASE),
            'last_communication' => $sortDir === 'asc' ? $filtered->sortBy('last_communication_ts') : $filtered->sortByDesc('last_communication_ts'),
            'last_sync' => $sortDir === 'asc' ? $filtered->sortBy('last_sync_ts') : $filtered->sortByDesc('last_sync_ts'),
            'enrolled_employees' => $sortDir === 'asc' ? $filtered->sortBy('enrolled_employees_count') : $filtered->sortByDesc('enrolled_employees_count'),
            default => $filtered->sortBy([
                ['requires_attention', 'desc'],
                ['is_active', 'desc'],
                ['last_communication_ts', 'desc'],
                ['name', 'asc'],
            ]),
        };

        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;
        $page = max(1, (int) $request->input('page', 1));
        $filteredValues = $filtered->values();

        $paginatedDevices = new LengthAwarePaginator(
            $filteredValues->forPage($page, $perPage)->values(),
            $filteredValues->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        // 5. 7-day biometric punch trend
        $startDate = Carbon::today()->subDays(6);
        $trendRaw = (clone $punchBase)
            ->whereDate('attendance_date', '>=', $startDate->toDateString())
            ->select(
                DB::raw('DATE(attendance_date) as day'),
                DB::raw('COUNT(*) as total'),
                DB::raw("SUM(CASE WHEN processing_status = '" . PunchProcessingStatus::Processed->value . "' THEN 1 ELSE 0 END) as processed")
            )
            ->groupBy('day')
            ->get()
            ->keyBy('day');

        $punchTrend7d = [];
        for ($i = 0; $i < 7; $i++) {
            $day = $startDate->copy()->addDays($i);
            $key = $day->toDateString();
            $row = $trendRaw->get($key);
            $punchTrend7d[] = [
                'date' => $key,
                'label' => $day->format('M d'),
                'punches' => (int) ($row->total ?? 0),
                'processed' => (int) ($row->processed ?? 0),
            ];
        }

        // 6. Recent unresolved sync failures (top 5)
        $recentFailures = (clone $failureBase)
            ->with('device:id,name,serial_number')
            ->where('status', SyncFailureStatus::PendingRetry->value)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(fn (BiometricSyncFailure $f) => [
                'id' => $f->id,
                'device_id' => $f->device_id,
                'device_name' => $f->device?->name ?? 'Unknown device',
                'error_code' => $f->error_code,
                'error_message' => Str::limit((string) $f->error_message, 90),
                'retry_count' => $f->retry_count,
                'created_at' => $f->created_at?->toIso8601String(),
            ]);

        // 7. Local agents overview
        $agents = BiometricAgent::visibleTo($user)->get();
        $agentsOnline = $agents->filter(fn (BiometricAgent $a) => $a->effective_connection_status === ConnectionStatus::Online->value)->count();

        // 8. Branches for filter
        $branches = Branch::query()
            ->when(!$isSuperadmin, fn ($q) => $q->whereIn('created_by', $companyUserIds))
            ->orderBy('name')
            ->get(['id', 'name']);

        return [
            'summary' => [
                'total_devices' => $allDevices->count(),
                'online_devices' => $onlineCount,
                'offline_devices' => $offlineCount,
                'attention_devices' => $attentionCount,
                'inactive_devices' => $inactiveCount,
                'enrolled_employees' => $enrolledEmployeesCount,
                'total_device_enrollments' => $totalDeviceEnrollments,
                'today_punches' => $todayPunches,
                'today_processed_punches' => $todayProcessedPunches,
                'missing_clock_outs' => $missingClockOuts,
                'last_successful_sync_at' => $lastSuccessAt?->toIso8601String(),
                'last_successful_sync_device' => $lastSuccessDevice,
                'last_successful_sync_action' => $lastSuccessAction,
                'failed_sync_count' => $failedSyncCount,
                'pending_sync_failures' => $pendingSyncFailures,
                'failed_runs_24h' => $failedRuns24h,
            ],
            'statusBreakdown' => [
                ['name' => 'Online', 'value' => $onlineCount, 'color' => '#10b981'],
                ['name' => 'Offline', 'value' => $offlineCount, 'color' => '#ef4444'],
                ['name' => 'Error', 'value' => $errorCount, 'color' => '#f59e0b'],
                ['name' => 'Never Connected', 'value' => $unknownCount, 'color' => '#64748b'],
                ['name' => 'Inactive', 'value' => $inactiveCount, 'color' => '#cbd5e1'],
            ],
            'punchTrend7d' => $punchTrend7d,
            'recentFailures' => $recentFailures,
            'agentsSummary' => [
                'total' => $agents->count(),
                'online' => $agentsOnline,
                'offline' => max(0, $agents->count() - $agentsOnline),
            ],
            'devices' => $paginatedDevices,
            'branches' => $branches,
            'filters' => $request->only(['search', 'branch_id', 'health', 'sort_field', 'sort_direction', 'per_page']),
        ];
    }

    private function maxTimestamp(?Carbon $a, ?Carbon $b): ?Carbon
    {
        if (!$a) {
            return $b;
        }
        if (!$b) {
            return $a;
        }

        return $a->gte($b) ? $a : $b;
    }
}
