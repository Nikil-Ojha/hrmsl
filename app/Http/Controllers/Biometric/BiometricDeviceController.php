<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\ProviderRegistry;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\CommandStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\ConnectionType;
use App\Biometric\Enums\DeviceType;
use App\Biometric\Enums\EventLevel;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\Services\BiometricAuditLogger;
use App\Biometric\Services\CommandQueue;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\DeviceApiKeyManager;
use App\Biometric\Services\DeviceDependencyChecker;
use App\Biometric\Services\DeviceManager;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Support\DeviceActionException;
use App\Biometric\Support\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Biometric\BiometricDeviceRequest;
use App\Models\BiometricAgent;
use App\Models\BiometricAttendanceLog;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\BiometricDeviceUser;
use App\Models\BiometricSyncRun;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BiometricDeviceController extends Controller
{
    public function __construct(
        private readonly ProviderRegistry $providers,
        private readonly DeviceManager $manager,
        private readonly EmployeeSelector $employees,
        private readonly DeviceActivityRecorder $activity,
        private readonly BiometricAuditLogger $audit,
    ) {
    }

    /* ================================================================= */
    /* List                                                               */
    /* ================================================================= */

    public function index(Request $request): Response
    {
        $base = BiometricDevice::visibleTo();

        $query = (clone $base)->with(['branch:id,name', 'department:id,name', 'agent:id,name,agent_identifier,status,connection_status,last_seen_at']);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('device_identifier', 'like', "%{$search}%")
                    ->orWhere('manufacturer', 'like', "%{$search}%")
                    ->orWhere('model', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        if (in_array($request->input('status'), ['active', 'inactive'], true)) {
            $query->where('is_active', $request->input('status') === 'active');
        }

        foreach (['branch_id', 'protocol', 'device_type', 'connection_type'] as $filter) {
            if ($request->filled($filter) && $request->input($filter) !== '_empty_') {
                $query->where($filter, $request->input($filter));
            }
        }

        if ($request->filled('connection_status') && $request->input('connection_status') !== '_empty_') {
            $this->applyEffectiveStatusFilter($query, (string) $request->input('connection_status'));
        }

        $sortable = ['name', 'serial_number', 'device_identifier', 'last_successful_sync_at', 'last_seen_at', 'created_at'];
        $sortField = in_array($request->input('sort_field'), $sortable, true) ? $request->input('sort_field') : 'created_at';
        $sortDirection = $request->input('sort_direction') === 'asc' ? 'asc' : 'desc';

        $perPage = (int) $request->input('per_page', 10);
        $perPage = in_array($perPage, [10, 25, 50, 100], true) ? $perPage : 10;

        $devices = $query->orderBy($sortField, $sortDirection)->paginate($perPage)->withQueryString();
        $devices->getCollection()->transform(fn (BiometricDevice $d) => $this->presentListItem($d));

        $online = $this->applyEffectiveStatusFilter(clone $base, ConnectionStatus::Online->value)->count();

        return Inertia::render('hr/biometric-devices/index', [
            'devices' => $devices,
            'stats' => [
                'total' => (clone $base)->count(),
                'active' => (clone $base)->where('is_active', true)->count(),
                'inactive' => (clone $base)->where('is_active', false)->count(),
                'online' => $online,
                'offline' => (clone $base)->where('is_active', true)->count() - $this->applyEffectiveStatusFilter((clone $base)->where('is_active', true), ConnectionStatus::Online->value)->count(),
            ],
            'filters' => $request->only(['search', 'status', 'branch_id', 'protocol', 'device_type', 'connection_type', 'connection_status', 'sort_field', 'sort_direction', 'per_page']),
            'options' => $this->formOptions(),
            'issuedApiKey' => session('biometric_api_key'),
        ]);
    }

    /* ================================================================= */
    /* CRUD                                                               */
    /* ================================================================= */

    public function store(BiometricDeviceRequest $request, DeviceApiKeyManager $keys): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();

        $device = DB::transaction(function () use ($data, $user) {
            $device = new BiometricDevice();
            $device->fill($this->attributesFrom($data));
            $device->company_id = Tenant::companyId($user);
            $device->created_by = $user->id;
            $device->device_identifier = $data['device_identifier'] ?? $this->generateIdentifier((int) $device->company_id);
            $device->credentials = $this->mergeCredentials(null, $data['credentials'] ?? []);
            $device->connection_status = ConnectionStatus::Unknown;

            // If this serial number was already pushing logs before registration, hydrate last contact.
            $latestLog = BiometricAttendanceLog::where('device_sn', $device->serial_number)
                ->where(function ($q) use ($device) {
                    $q->where('company_id', $device->company_id)
                        ->orWhere(function ($legacy) use ($device) {
                            $legacy->whereNull('company_id')
                                ->where('created_by', $device->company_id);
                        });
                })
                ->max('created_at');

            if ($latestLog) {
                $seenAt = \Illuminate\Support\Carbon::parse($latestLog);
                $device->last_seen_at = $seenAt;
                $device->last_log_received_at = $seenAt;
                $threshold = (int) config('biometric.connectivity.online_threshold_seconds', 300);
                if ($seenAt->gt(now()->subSeconds($threshold))) {
                    $device->connection_status = ConnectionStatus::Online;
                }
            }

            $device->save();

            $this->activity->event($device, EventLevel::Info, 'created', 'Device registered by ' . $user->name . '.');

            return $device;
        });

        $issuedKey = null;
        if ($device->auth_type->usesIssuedApiKey()) {
            $issuedKey = $keys->issue($device);
        }

        $this->audit->log(
            category: BiometricAuditLogger::CAT_DEVICE,
            action: 'device.created',
            description: "Registered biometric device '{$device->name}' ({$device->serial_number}).",
            companyId: (int) $device->company_id,
            targetType: 'BiometricDevice',
            targetId: $device->id,
            targetLabel: $device->name,
            deviceId: $device->id,
            after: $device->only(['name', 'serial_number', 'device_identifier', 'manufacturer', 'model', 'protocol', 'connection_type', 'ip_address', 'port', 'branch_id', 'is_active']),
            actor: $user,
        );

        return redirect()
            ->route('hr.biometric-devices.show', $device->id)
            ->with('success', __('Biometric device created successfully.'))
            ->with('biometric_api_key', $issuedKey);
    }

    public function show(Request $request, BiometricDevice $device): Response
    {
        $this->authorizeDevice($device, 'view-biometric-devices');

        $device->load(['branch:id,name', 'department:id,name', 'creator:id,name', 'agent:id,name,agent_identifier,status,connection_status,last_seen_at']);
        $provider = $this->manager->provider($device);

        $runs = BiometricSyncRun::with('initiator:id,name')
            ->where('device_id', $device->id)
            ->when($request->filled('run_action') && $request->run_action !== '_empty_', fn ($q) => $q->where('action', $request->run_action))
            ->when($request->filled('run_status') && $request->run_status !== '_empty_', fn ($q) => $q->where('status', $request->run_status))
            ->latest('id')
            ->paginate(10, ['*'], 'runs_page')
            ->withQueryString();

        $events = BiometricDeviceEvent::where('device_id', $device->id)
            ->when($request->filled('event_level') && $request->event_level !== '_empty_', fn ($q) => $q->where('level', $request->event_level))
            ->latest('occurred_at')
            ->latest('id')
            ->paginate(10, ['*'], 'events_page')
            ->withQueryString();

        $lastSuccess = [];
        foreach (SyncAction::cases() as $action) {
            $run = $device->lastSuccessfulRun($action);
            $lastSuccess[$action->value] = $run ? [
                'id' => $run->id,
                'finished_at' => $run->finished_at?->toIso8601String(),
                'message' => $run->message,
            ] : null;
        }

        $pendingCommands = BiometricCommand::where('device_id', $device->id)->pending()->count();
        $recentCommands = BiometricCommand::with('employee.user:id,name')
            ->where('device_id', $device->id)
            ->latest('id')
            ->limit(15)
            ->get()
            ->map(fn (BiometricCommand $c) => [
                'id' => $c->id,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'status' => $c->status->value,
                'employee' => $c->employee?->user?->name,
                'sent_at' => $c->sent_at?->toIso8601String(),
                'acknowledged_at' => $c->acknowledged_at?->toIso8601String(),
                'created_at' => $c->created_at?->toIso8601String(),
                'error' => $c->error,
            ]);

        return Inertia::render('hr/biometric-devices/show', [
            'device' => $this->presentDetail($device),
            'driver' => $this->providers->describe((string) $device->protocol),
            'setupHints' => $provider->setupHints(),
            'deviceStatus' => $provider->getDeviceStatus()->toArray(),
            'deviceInfo' => $provider->getDeviceInformation(false)->toArray()['data'],
            'deviceUsers' => BiometricDeviceUser::with('employee.user:id,name')
                ->where('device_id', $device->id)
                ->when($request->filled('user_search'), fn ($q) => $q->where(fn ($q2) => $q2->where('pin', 'like', '%' . $request->user_search . '%')->orWhere('name', 'like', '%' . $request->user_search . '%')))
                ->orderByRaw('CAST(pin AS UNSIGNED), pin')
                ->paginate(15, ['*'], 'users_page')
                ->withQueryString()
                ->through(fn (BiometricDeviceUser $u) => [
                    'id' => $u->id,
                    'pin' => $u->pin,
                    'name' => $u->name,
                    'card' => $u->card,
                    'privilege' => $u->privilege,
                    'employee' => $u->employee?->user?->name,
                    'employee_code' => $u->employee?->employee_id,
                    'source' => $u->source,
                    'last_reported_at' => $u->last_reported_at?->toIso8601String(),
                ]),
            'availableActions' => $this->manager->availableActions($device),
            'lastSuccess' => $lastSuccess,
            'syncRuns' => $runs->through(fn (BiometricSyncRun $r) => $this->presentRun($r)),
            'events' => $events->through(fn (BiometricDeviceEvent $e) => [
                'id' => $e->id,
                'level' => $e->level->value,
                'type' => $e->type,
                'message' => $e->message,
                'context' => $e->context,
                'sync_run_id' => $e->sync_run_id,
                'occurred_at' => $e->occurred_at?->toIso8601String(),
            ]),
            'errorCount' => BiometricDeviceEvent::where('device_id', $device->id)->where('level', EventLevel::Error->value)->where('occurred_at', '>=', now()->subDays(7))->count(),
            'pendingCommands' => $pendingCommands,
            'recentCommands' => $recentCommands,
            'employeeSummary' => $this->employees->summary($device),
            'issuedApiKey' => session('biometric_api_key'),
            'filters' => $request->only(['run_action', 'run_status', 'event_level', 'user_search']),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(BiometricDeviceRequest $request, BiometricDevice $device, DeviceApiKeyManager $keys, CommandQueue $commands): RedirectResponse
    {
        $this->authorizeDevice($device, 'edit-biometric-devices');

        $data = $request->validated();
        $beforeSnapshot = $device->only(['name', 'serial_number', 'device_identifier', 'manufacturer', 'model', 'protocol', 'connection_type', 'ip_address', 'port', 'branch_id', 'department_id', 'agent_id', 'timezone', 'is_active']);
        $originalProtocol = $device->protocol;
        $originalAuth = $device->auth_type;

        $device->fill($this->attributesFrom($data));
        if (!empty($data['device_identifier'])) {
            $device->device_identifier = $data['device_identifier'];
        }
        $device->credentials = $this->mergeCredentials($device->credentials, $data['credentials'] ?? []);

        $changes = array_keys($device->getDirty());
        $device->save();

        // Changing protocol invalidates commands that were encoded for the old protocol.
        if ($originalProtocol !== $device->protocol) {
            $commands->cancelPending($device, 'Cancelled because the device protocol was changed.');
        }

        $issuedKey = null;
        if ($device->auth_type->usesIssuedApiKey() && (!$device->has_api_key || $originalAuth !== $device->auth_type)) {
            $issuedKey = $keys->issue($device);
        } elseif (!$device->auth_type->usesIssuedApiKey() && $device->has_api_key) {
            $keys->revoke($device);
        }

        $changes = array_values(array_diff($changes, ['credentials', 'updated_at']));
        if ($changes) {
            $this->activity->event($device, EventLevel::Info, 'updated', 'Configuration updated by ' . $request->user()->name . '.', ['fields' => $changes]);
        }

        $this->audit->log(
            category: BiometricAuditLogger::CAT_DEVICE,
            action: 'device.updated',
            description: "Updated configuration for device '{$device->name}'." . ($changes ? ' Fields: ' . implode(', ', $changes) . '.' : ''),
            companyId: (int) $device->company_id,
            targetType: 'BiometricDevice',
            targetId: $device->id,
            targetLabel: $device->name,
            deviceId: $device->id,
            before: $beforeSnapshot,
            after: $device->only(['name', 'serial_number', 'device_identifier', 'manufacturer', 'model', 'protocol', 'connection_type', 'ip_address', 'port', 'branch_id', 'department_id', 'agent_id', 'timezone', 'is_active']),
            metadata: ['changed_fields' => $changes],
            actor: $request->user(),
        );

        return redirect()->back()
            ->with('success', __('Biometric device updated successfully.'))
            ->with('biometric_api_key', $issuedKey);
    }

    public function toggleStatus(Request $request, BiometricDevice $device, CommandQueue $commands): RedirectResponse
    {
        $this->authorizeDevice($device, 'edit-biometric-devices');

        $device->is_active = !$device->is_active;
        $device->save();

        if (!$device->is_active) {
            $cancelled = $commands->cancelPending($device, 'Cancelled because the device was deactivated.');
        }

        $this->activity->event(
            $device,
            $device->is_active ? EventLevel::Info : EventLevel::Warning,
            $device->is_active ? 'activated' : 'deactivated',
            ($device->is_active ? 'Device activated' : 'Device deactivated') . ' by ' . $request->user()->name . '.',
            isset($cancelled) && $cancelled ? ['cancelled_commands' => $cancelled] : []
        );

        $this->audit->log(
            category: BiometricAuditLogger::CAT_DEVICE,
            action: $device->is_active ? 'device.activated' : 'device.deactivated',
            description: ($device->is_active ? 'Activated' : 'Deactivated') . " biometric device '{$device->name}'.",
            companyId: (int) $device->company_id,
            targetType: 'BiometricDevice',
            targetId: $device->id,
            targetLabel: $device->name,
            deviceId: $device->id,
            before: ['is_active' => !$device->is_active],
            after: ['is_active' => (bool) $device->is_active],
            metadata: isset($cancelled) ? ['cancelled_commands' => $cancelled] : [],
            actor: $request->user(),
        );

        return redirect()->back()->with('success', $device->is_active
            ? __('Device activated successfully.')
            : __('Device deactivated successfully.'));
    }

    public function dependencies(BiometricDevice $device, DeviceDependencyChecker $checker): JsonResponse
    {
        $this->authorizeDevice($device, 'delete-biometric-devices');

        return response()->json($checker->check($device));
    }

    public function destroy(BiometricDevice $device, DeviceDependencyChecker $checker): RedirectResponse
    {
        $this->authorizeDevice($device, 'delete-biometric-devices');

        $result = $checker->check($device);
        if (!$result['can_delete']) {
            return redirect()->back()->with('error', __('This device cannot be deleted: :reasons Deactivate it instead to keep the attendance history.', [
                'reasons' => implode(' ', array_column($result['blockers'], 'message')),
            ]));
        }

        $snapshot = $device->only(['id', 'name', 'serial_number', 'device_identifier', 'protocol', 'branch_id']);
        $companyId = (int) $device->company_id;

        // Sync runs, commands and events cascade with the device.
        $device->delete();

        $this->audit->log(
            category: BiometricAuditLogger::CAT_DEVICE,
            action: 'device.deleted',
            description: "Deleted biometric device '{$snapshot['name']}' ({$snapshot['serial_number']}).",
            companyId: $companyId,
            targetType: 'BiometricDevice',
            targetId: (int) $snapshot['id'],
            targetLabel: (string) $snapshot['name'],
            before: $snapshot,
        );

        return redirect()->route('hr.biometric-devices.index')->with('success', __('Biometric device deleted successfully.'));
    }

    public function regenerateApiKey(BiometricDevice $device, DeviceApiKeyManager $keys): RedirectResponse
    {
        $this->authorizeDevice($device, ['manage-biometric-settings', 'edit-biometric-devices']);

        if (!$device->auth_type->usesIssuedApiKey()) {
            return redirect()->back()->with('error', __('This device does not use an issued API key.'));
        }

        $plain = $keys->issue($device);
        $this->activity->event($device, EventLevel::Warning, 'api_key_rotated', 'API key regenerated by ' . Auth::user()->name . '. The previous key no longer works.');
        $this->audit->log(
            category: BiometricAuditLogger::CAT_SETTINGS,
            action: 'device.api_key_rotated',
            description: "Regenerated API key for device '{$device->name}'.",
            companyId: (int) $device->company_id,
            targetType: 'BiometricDevice',
            targetId: $device->id,
            targetLabel: $device->name,
            deviceId: $device->id,
        );

        return redirect()->back()
            ->with('success', __('A new API key was generated. Update the device configuration with it.'))
            ->with('biometric_api_key', $plain);
    }

    /* ================================================================= */
    /* Device actions                                                     */
    /* ================================================================= */

    public function checkConnectivity(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        return $this->runAction(function () use ($device, $request) {
            $run = $this->manager->checkConnectivity($device, $request->user());
            $this->audit->log(BiometricAuditLogger::CAT_SYNC, 'sync.connectivity_checked', "Checked connectivity for '{$device->name}': {$run->status->value}.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id, 'status' => $run->status->value], $request->user());

            return [$run->status === SyncStatus::Success ? 'success' : 'error', $run->message];
        });
    }

    public function syncTime(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        return $this->runAction(function () use ($device, $request) {
            $run = $this->manager->syncTime($device, $request->user());
            $this->audit->log(BiometricAuditLogger::CAT_SYNC, 'sync.time_synced', "Triggered time synchronization on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id, 'status' => $run->status->value], $request->user());

            return $this->flashRun($run);
        });
    }

    public function syncEmployees(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        $validated = $request->validate([
            'employee_ids' => ['nullable', 'array', 'max:5000'],
            'employee_ids.*' => ['integer'],
        ]);

        return $this->runAction(function () use ($device, $request, $validated) {
            $ids = !empty($validated['employee_ids']) ? array_map('intval', $validated['employee_ids']) : null;
            $run = $this->manager->syncEmployees($device, $request->user(), $ids);
            $this->audit->log(BiometricAuditLogger::CAT_SYNC, 'sync.employees_synced', "Triggered employee synchronization on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id, 'total_items' => $run->total_items, 'status' => $run->status->value], $request->user());

            return $this->flashRun($run);
        });
    }

    public function fetchLogs(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after:from'],
        ]);

        return $this->runAction(function () use ($device, $request, $validated) {
            $run = $this->manager->fetchLogs($device, $request->user(), $validated['from'] ?? null, $validated['to'] ?? null);
            $this->audit->log(BiometricAuditLogger::CAT_SYNC, 'sync.logs_fetched', "Triggered attendance log fetch on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id, 'from' => $validated['from'] ?? null, 'to' => $validated['to'] ?? null, 'status' => $run->status->value], $request->user());

            return $this->flashRun($run);
        });
    }

    public function employeePreview(BiometricDevice $device): JsonResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        $employees = $this->employees->baseQuery($device)
            ->with('user:id,name')
            ->orderBy('id')
            ->limit(1000)
            ->get(['id', 'user_id', 'employee_id', 'biometric_emp_id', 'is_biometric_enrolled', 'biometric_device_sn'])
            ->map(fn ($e) => [
                'id' => $e->id,
                'name' => $e->user?->name,
                'employee_id' => $e->employee_id,
                'biometric_code' => $e->biometric_emp_id,
                'on_this_device' => $e->is_biometric_enrolled && $e->biometric_device_sn === $device->serial_number,
            ]);

        return response()->json([
            'summary' => $this->employees->summary($device),
            'employees' => $employees,
        ]);
    }

    public function refreshInfo(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        return $this->runAction(fn () => $this->flashRun($this->manager->refreshInformation($device, $request->user())));
    }

    public function fetchUsers(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, 'sync-biometric-devices');

        return $this->runAction(fn () => $this->flashRun($this->manager->fetchUsers($device, $request->user())));
    }

    public function removeUser(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, ['execute-biometric-commands', 'control-biometric-devices']);
        $validated = $request->validate(['pin' => ['required', 'string', 'max:50']]);

        return $this->runAction(function () use ($device, $validated, $request) {
            $run = $this->manager->removeUser($device, $validated['pin'], $request->user());
            $this->audit->log(BiometricAuditLogger::CAT_COMMAND, 'command.user_removed', "Executed remove-user command for PIN {$validated['pin']} on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['pin' => $validated['pin'], 'sync_run_id' => $run->id], $request->user());

            return $this->flashRun($run);
        });
    }

    public function clearLogs(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, ['execute-biometric-commands', 'control-biometric-devices']);

        return $this->runAction(function () use ($device, $request) {
            $run = $this->manager->clearLogs($device, $request->user());
            $this->audit->log(BiometricAuditLogger::CAT_COMMAND, 'command.logs_cleared', "Executed clear-attendance-logs command on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id], $request->user());

            return $this->flashRun($run);
        });
    }

    public function restart(Request $request, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeDevice($device, ['execute-biometric-commands', 'control-biometric-devices']);

        return $this->runAction(function () use ($device, $request) {
            $run = $this->manager->restart($device, $request->user());
            $this->audit->log(BiometricAuditLogger::CAT_COMMAND, 'command.restarted', "Executed restart command on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['sync_run_id' => $run->id], $request->user());

            return $this->flashRun($run);
        });
    }

    public function cancelCommands(Request $request, BiometricDevice $device, CommandQueue $commands): RedirectResponse
    {
        $this->authorizeDevice($device, ['execute-biometric-commands', 'control-biometric-devices', 'sync-biometric-devices']);

        $count = $commands->cancelPending($device, 'Cancelled by ' . $request->user()->name . '.');
        $this->audit->log(BiometricAuditLogger::CAT_COMMAND, 'command.cancelled', "Cancelled {$count} pending command(s) on '{$device->name}'.", (int) $device->company_id, 'BiometricDevice', $device->id, $device->name, $device->id, null, null, null, ['cancelled_count' => $count], $request->user());

        return redirect()->back()->with('success', __(':count pending command(s) cancelled.', ['count' => $count]));
    }

    /* ================================================================= */
    /* Helpers                                                            */
    /* ================================================================= */

    /** Flash message for a run: queued, finished successfully or failed. */
    private function flashRun(BiometricSyncRun $run): array
    {
        return [$run->status === SyncStatus::Failed ? 'error' : 'success', (string) ($run->status === SyncStatus::Failed ? ($run->error ?: $run->message) : $run->message)];
    }

    private function runAction(callable $callback): RedirectResponse
    {
        try {
            [$type, $message] = $callback();

            return redirect()->back()->with($type, $message);
        } catch (DeviceActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Route-model binding resolves any id; enforce tenant scope and granular permission(s).
     *
     * @param string|list<string> $permissions
     */
    private function authorizeDevice(BiometricDevice $device, string|array $permissions): void
    {
        $user = Auth::user();
        $list = is_array($permissions) ? $permissions : [$permissions];
        $allowed = false;
        foreach ($list as $perm) {
            if ($user->can($perm)) {
                $allowed = true;
                break;
            }
        }
        abort_unless($allowed, 403, __('Permission Denied.'));
        abort_unless(BiometricDevice::visibleTo($user)->whereKey($device->id)->exists(), 404);
    }

    private function attributesFrom(array $data): array
    {
        return collect($data)->only([
            'name', 'serial_number', 'manufacturer', 'model', 'device_type', 'protocol', 'connection_type',
            'ip_address', 'port', 'branch_id', 'department_id', 'agent_id', 'timezone', 'auth_type', 'is_active', 'notes',
        ])->all();
    }

    /**
     * Blank secret fields on edit mean "keep the stored value".
     */
    private function mergeCredentials(?array $existing, array $incoming): ?array
    {
        $merged = $existing ?? [];
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $merged[$key] = $value;
        }

        return $merged ?: null;
    }

    private function generateIdentifier(int $companyId): string
    {
        do {
            $identifier = 'DEV-' . Str::upper(Str::random(6));
        } while (BiometricDevice::where('company_id', $companyId)->where('device_identifier', $identifier)->exists());

        return $identifier;
    }

    /**
     * Filter on the *effective* connection status (stored status corrected by
     * heartbeat freshness) so the list is right even when the monitor is late.
     */
    private function applyEffectiveStatusFilter($query, string $status)
    {
        $threshold = now()->subSeconds((int) config('biometric.connectivity.online_threshold_seconds', 300));

        return match ($status) {
            'online' => $query->where('last_seen_at', '>=', $threshold)
                ->whereIn('connection_status', [ConnectionStatus::Online->value, ConnectionStatus::Unknown->value]),
            'offline' => $query->where(function ($q) use ($threshold) {
                $q->where('connection_status', ConnectionStatus::Offline->value)
                    ->orWhere(fn ($q2) => $q2->where('connection_status', ConnectionStatus::Online->value)
                        ->where(fn ($q3) => $q3->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold)));
            }),
            'error' => $query->where('connection_status', ConnectionStatus::Error->value),
            'unknown' => $query->where('connection_status', ConnectionStatus::Unknown->value)
                ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold)),
            default => $query,
        };
    }

    private function presentListItem(BiometricDevice $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'serial_number' => $d->serial_number,
            'device_identifier' => $d->device_identifier,
            'manufacturer' => $d->manufacturer,
            'model' => $d->model,
            'device_type' => $d->device_type?->value,
            'device_type_label' => $d->device_type?->label(),
            'protocol' => $d->protocol,
            'protocol_label' => $this->providers->label((string) $d->protocol),
            'connection_type' => $d->connection_type?->value,
            'connection_type_label' => $d->connection_type?->label(),
            'ip_address' => $d->ip_address,
            'port' => $d->port,
            'branch' => $d->branch?->name,
            'department' => $d->department?->name,
            'agent_id' => $d->agent_id,
            'agent_name' => $d->agent?->name,
            'agent_identifier' => $d->agent?->agent_identifier,
            'agent_status' => $d->agent?->effective_connection_status,
            'is_active' => $d->is_active,
            'connection_status' => $d->effective_connection_status,
            'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            'last_sync_at' => $d->last_sync_at?->toIso8601String(),
            'last_successful_sync_at' => $d->last_successful_sync_at?->toIso8601String(),
            'last_error' => $d->last_error,
            'last_error_at' => $d->last_error_at?->toIso8601String(),
        ] + $this->formValues($d);
    }

    private function presentDetail(BiometricDevice $d): array
    {
        return $this->presentListItem($d) + [
            'firmware_version' => $d->firmware_version,
            'timezone_effective' => $d->timezoneName(),
            'auth_type' => $d->auth_type?->value,
            'auth_type_label' => $d->auth_type?->label(),
            'has_api_key' => $d->has_api_key,
            'api_key_prefix' => $d->api_key_prefix,
            'last_seen_ip' => $d->last_seen_ip,
            'last_log_received_at' => $d->last_log_received_at?->toIso8601String(),
            'last_connectivity_check_at' => $d->last_connectivity_check_at?->toIso8601String(),
            'notes' => $d->notes,
            'creator' => $d->creator?->name,
            'created_at' => $d->created_at?->toIso8601String(),
            'updated_at' => $d->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Raw values used to pre-fill the edit form. Secrets are never sent; only
     * which secret fields are set.
     */
    private function formValues(BiometricDevice $d): array
    {
        $credentials = $d->credentials ?? [];
        $nonSecret = [];
        $secretsSet = [];

        if ($this->providers->has((string) $d->protocol)) {
            foreach ($this->providers->definition((string) $d->protocol)->credentialFields as $field) {
                if (!empty($field['secret'])) {
                    $secretsSet[$field['name']] = !empty($credentials[$field['name']]);
                } else {
                    $nonSecret[$field['name']] = $credentials[$field['name']] ?? '';
                }
            }
        }

        return [
            'form' => [
                'name' => $d->name,
                'serial_number' => $d->serial_number,
                'device_identifier' => $d->device_identifier,
                'manufacturer' => $d->manufacturer ?? '',
                'model' => $d->model ?? '',
                'device_type' => $d->device_type?->value,
                'protocol' => $d->protocol,
                'connection_type' => $d->connection_type?->value,
                'ip_address' => $d->ip_address ?? '',
                'port' => $d->port,
                'branch_id' => $d->branch_id,
                'department_id' => $d->department_id,
                'agent_id' => $d->agent_id,
                'timezone' => $d->timezone ?? '',
                'auth_type' => $d->auth_type?->value,
                'credentials' => $nonSecret,
                'secrets_set' => $secretsSet,
                'is_active' => $d->is_active,
                'notes' => $d->notes ?? '',
            ],
        ];
    }

    private function presentRun(BiometricSyncRun $r): array
    {
        return [
            'id' => $r->id,
            'action' => $r->action->value,
            'action_label' => $r->action->label(),
            'trigger' => $r->trigger,
            'status' => $r->status->value,
            'total_items' => $r->total_items,
            'success_items' => $r->success_items,
            'failed_items' => $r->failed_items,
            'parameters' => $r->parameters,
            'result' => $r->result,
            'message' => $r->message,
            'error' => $r->error,
            'started_at' => $r->started_at?->toIso8601String(),
            'finished_at' => $r->finished_at?->toIso8601String(),
            'duration_seconds' => $r->duration_seconds,
            'initiated_by' => $r->initiator?->name,
        ];
    }

    private function formOptions(): array
    {
        $companyUsers = $this->employees->companyUserIds((int) Tenant::companyId());

        return [
            'drivers' => $this->providers->describeAll(),
            'operations' => \App\Biometric\Enums\Operation::options(),
            'device_types' => DeviceType::options(),
            'connection_types' => ConnectionType::options(),
            'connection_statuses' => ConnectionStatus::options(),
            'auth_types' => AuthType::options(),
            'sync_actions' => SyncAction::options(),
            'sync_statuses' => SyncStatus::options(),
            'event_levels' => EventLevel::options(),
            'command_statuses' => CommandStatus::options(),
            'branches' => Branch::whereIn('created_by', $companyUsers)->orderBy('name')->get(['id', 'name']),
            'departments' => Department::whereIn('created_by', $companyUsers)->orderBy('name')->get(['id', 'name', 'branch_id']),
            'agents' => BiometricAgent::visibleTo()->orderBy('name')->get(['id', 'name', 'agent_identifier', 'branch_id', 'status']),
            'timezones' => \DateTimeZone::listIdentifiers(),
            'default_timezone' => settings()['defaultTimezone'] ?? config('app.timezone', 'UTC'),
        ];
    }
}
