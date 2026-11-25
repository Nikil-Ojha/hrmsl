<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\Enums\AgentStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Services\AgentCredentialManager;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Support\Tenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Biometric\BiometricAgentRequest;
use App\Models\BiometricAgent;
use App\Models\BiometricAgentDiscovery;
use App\Models\BiometricApiAuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\Branch;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class BiometricAgentController extends Controller
{
    public function __construct(
        private readonly AgentCredentialManager $credentials,
        private readonly EmployeeSelector $employees,
    ) {
    }

    public function index(Request $request): Response
    {
        $base = BiometricAgent::visibleTo();
        $query = (clone $base)->with(['branch:id,name'])->withCount(['devices', 'discoveries as pending_discoveries_count' => fn ($q) => $q->where('status', 'discovered')]);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('agent_identifier', 'like', "%{$search}%")
                    ->orWhere('hostname', 'like', "%{$search}%")
                    ->orWhere('last_seen_ip', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status') && $request->input('status') !== '_empty_') {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('branch_id') && $request->input('branch_id') !== '_empty_') {
            $query->where('branch_id', (int) $request->input('branch_id'));
        }

        $perPage = in_array((int) $request->input('per_page', 10), [10, 25, 50, 100], true) ? (int) $request->input('per_page', 10) : 10;
        $agents = $query->latest('id')->paginate($perPage)->withQueryString();
        $agents->getCollection()->transform(fn (BiometricAgent $a) => $this->presentListItem($a));

        $threshold = now()->subSeconds((int) config('biometric.agent.online_threshold_seconds', 180));
        $onlineCount = (clone $base)
            ->where('status', AgentStatus::Active->value)
            ->where('last_seen_at', '>=', $threshold)
            ->count();

        return Inertia::render('hr/biometric-agents/index', [
            'agents' => $agents,
            'stats' => [
                'total' => (clone $base)->count(),
                'online' => $onlineCount,
                'pending' => (clone $base)->where('status', AgentStatus::Pending->value)->count(),
                'revoked' => (clone $base)->where('status', AgentStatus::Revoked->value)->count(),
                'queued_logs' => (int) (clone $base)->where('status', AgentStatus::Active->value)->sum('local_queue_depth'),
            ],
            'filters' => $request->only(['search', 'status', 'branch_id', 'per_page']),
            'options' => $this->formOptions(),
            'provisioningBundle' => session('biometric_agent_provisioning'),
        ]);
    }

    public function store(BiometricAgentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $user = $request->user();
        $companyId = Tenant::companyId($user);

        $agent = BiometricAgent::create([
            'company_id' => $companyId,
            'created_by' => $user->id,
            'branch_id' => $data['branch_id'] ?? null,
            'name' => $data['name'],
            'agent_identifier' => $data['agent_identifier'] ?? $this->generateIdentifier(),
            'status' => AgentStatus::Pending,
            'connection_status' => ConnectionStatus::Unknown,
            'settings' => $this->extractSettings($data),
            'notes' => $data['notes'] ?? null,
        ]);

        $token = $this->credentials->issueProvisioningToken($agent);

        app(\App\Biometric\Services\BiometricAuditLogger::class)->log(
            category: \App\Biometric\Services\BiometricAuditLogger::CAT_AGENT,
            action: 'agent.created',
            description: "Registered local biometric agent '{$agent->name}' ({$agent->agent_identifier}).",
            companyId: (int) $agent->company_id,
            targetType: 'BiometricAgent',
            targetId: $agent->id,
            targetLabel: $agent->name,
        );

        return redirect()
            ->route('hr.biometric-agents.show', $agent->id)
            ->with('success', __('Biometric agent registered. Use the one-time provisioning token below to connect the local installation.'))
            ->with('biometric_agent_provisioning', $this->provisioningBundle($agent, $token));
    }

    public function show(Request $request, BiometricAgent $agent): Response
    {
        $this->authorizeAgent($agent, 'view-biometric-agents');

        $agent->load(['branch:id,name', 'creator:id,name']);

        $assignedDevices = BiometricDevice::with(['branch:id,name', 'department:id,name'])
            ->where('agent_id', $agent->id)
            ->orderBy('name')
            ->get()
            ->map(fn (BiometricDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'serial_number' => $d->serial_number,
                'device_identifier' => $d->device_identifier,
                'protocol' => $d->protocol,
                'connection_type' => $d->connection_type?->value,
                'ip_address' => $d->ip_address,
                'port' => $d->port,
                'branch' => $d->branch?->name,
                'is_active' => $d->is_active,
                'connection_status' => $d->effective_connection_status,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
                'last_error' => $d->last_error,
            ]);

        $unassignedDevices = BiometricDevice::visibleTo()
            ->whereNull('agent_id')
            ->orderBy('name')
            ->get(['id', 'name', 'serial_number', 'ip_address', 'connection_type']);

        $discoveries = BiometricAgentDiscovery::with('registeredDevice:id,name')
            ->where('agent_id', $agent->id)
            ->latest('last_seen_at')
            ->limit(100)
            ->get()
            ->map(fn (BiometricAgentDiscovery $d) => [
                'id' => $d->id,
                'fingerprint' => $d->fingerprint,
                'connection_type' => $d->connection_type,
                'ip_address' => $d->ip_address,
                'port' => $d->port,
                'usb_path' => $d->usb_path,
                'serial_number' => $d->serial_number,
                'manufacturer_hint' => $d->manufacturer_hint,
                'model_hint' => $d->model_hint,
                'protocol_hint' => $d->protocol_hint,
                'status' => $d->status,
                'registered_device_id' => $d->registered_device_id,
                'registered_device_name' => $d->registeredDevice?->name,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(),
            ]);

        $deviceIds = $assignedDevices->pluck('id')->all();
        $recentEvents = $deviceIds
            ? BiometricDeviceEvent::with('device:id,name')
                ->whereIn('device_id', $deviceIds)
                ->latest('occurred_at')
                ->limit(30)
                ->get()
                ->map(fn (BiometricDeviceEvent $e) => [
                    'id' => $e->id,
                    'device_id' => $e->device_id,
                    'device_name' => $e->device?->name,
                    'level' => $e->level->value,
                    'type' => $e->type,
                    'message' => $e->message,
                    'occurred_at' => $e->occurred_at?->toIso8601String(),
                ])
            : [];

        $apiAuditLogs = BiometricApiAuditLog::with('device:id,name')
            ->where(function ($q) use ($agent) {
                $q->where('agent_id', $agent->id)
                    ->orWhere(function ($q2) use ($agent) {
                        $q2->whereNull('agent_id')
                            ->where('agent_identifier_claimed', $agent->agent_identifier);
                    });
            })
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (BiometricApiAuditLog $l) => [
                'id' => $l->id,
                'method' => $l->method,
                'path' => $l->path,
                'status_code' => $l->status_code,
                'outcome' => $l->outcome,
                'device_name' => $l->device?->name,
                'ip_address' => $l->ip_address,
                'latency_ms' => $l->latency_ms,
                'summary' => $l->summary,
                'created_at' => $l->created_at?->toIso8601String(),
            ]);

        return Inertia::render('hr/biometric-agents/show', [
            'agent' => $this->presentDetail($agent),
            'assignedDevices' => $assignedDevices,
            'unassignedDevices' => $unassignedDevices,
            'discoveries' => $discoveries,
            'recentEvents' => $recentEvents,
            'apiAuditLogs' => $apiAuditLogs,
            'provisioningBundle' => session('biometric_agent_provisioning'),
            'options' => $this->formOptions(),
        ]);
    }

    public function update(BiometricAgentRequest $request, BiometricAgent $agent): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');
        $data = $request->validated();

        $agent->fill([
            'name' => $data['name'],
            'branch_id' => $data['branch_id'] ?? null,
            'settings' => $this->extractSettings($data, $agent->settings ?? []),
            'notes' => $data['notes'] ?? null,
        ]);
        if (!empty($data['agent_identifier'])) {
            $agent->agent_identifier = $data['agent_identifier'];
        }
        $agent->save();

        return redirect()->back()->with('success', __('Biometric agent updated successfully.'));
    }

    public function reissueToken(BiometricAgent $agent): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');

        $token = $this->credentials->resetForReprovisioning($agent);

        app(\App\Biometric\Services\BiometricAuditLogger::class)->log(
            category: \App\Biometric\Services\BiometricAuditLogger::CAT_SETTINGS,
            action: 'agent.token_reissued',
            description: "Reissued one-time provisioning token for local agent '{$agent->name}' (previous credentials invalidated).",
            companyId: (int) $agent->company_id,
            targetType: 'BiometricAgent',
            targetId: $agent->id,
            targetLabel: $agent->name,
        );

        return redirect()->back()
            ->with('success', __('New one-time provisioning token issued. Previous agent credentials have been invalidated.'))
            ->with('biometric_agent_provisioning', $this->provisioningBundle($agent, $token));
    }

    public function revoke(BiometricAgent $agent): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');

        $this->credentials->revoke($agent);

        app(\App\Biometric\Services\BiometricAuditLogger::class)->log(
            category: \App\Biometric\Services\BiometricAuditLogger::CAT_SETTINGS,
            action: 'agent.revoked',
            description: "Revoked credentials for local agent '{$agent->name}' ({$agent->agent_identifier}).",
            companyId: (int) $agent->company_id,
            targetType: 'BiometricAgent',
            targetId: $agent->id,
            targetLabel: $agent->name,
        );

        return redirect()->back()->with('success', __('Agent credentials revoked. The installation can no longer access HRMS.'));
    }

    public function assignDevice(Request $request, BiometricAgent $agent): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');
        $validated = $request->validate(['device_id' => ['required', 'integer']]);

        $device = BiometricDevice::where('company_id', $agent->company_id)->find($validated['device_id']);
        if (!$device) {
            return redirect()->back()->with('error', __('Device not found in your company.'));
        }

        $device->forceFill(['agent_id' => $agent->id])->save();

        return redirect()->back()->with('success', __('Device assigned to local agent.'));
    }

    public function unassignDevice(BiometricAgent $agent, BiometricDevice $device): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');

        if ((int) $device->agent_id === (int) $agent->id) {
            $device->forceFill(['agent_id' => null])->save();
        }

        return redirect()->back()->with('success', __('Device detached from local agent.'));
    }

    public function ignoreDiscovery(BiometricAgent $agent, BiometricAgentDiscovery $discovery): RedirectResponse
    {
        $this->authorizeAgent($agent, 'edit-biometric-agents');

        if ((int) $discovery->agent_id === (int) $agent->id) {
            $discovery->forceFill(['status' => 'ignored'])->save();
        }

        return redirect()->back()->with('success', __('Discovered device dismissed.'));
    }

    public function destroy(BiometricAgent $agent): RedirectResponse
    {
        $this->authorizeAgent($agent, 'delete-biometric-agents');

        $assignedCount = $agent->devices()->count();
        if ($assignedCount > 0) {
            return redirect()->back()->with('error', __('Detach :count assigned device(s) before deleting this agent, or revoke it instead.', ['count' => $assignedCount]));
        }

        $agent->delete();

        return redirect()->route('hr.biometric-agents.index')->with('success', __('Biometric agent deleted successfully.'));
    }

    private function authorizeAgent(BiometricAgent $agent, string $permission): void
    {
        $user = Auth::user();
        abort_unless($user->can($permission), 403, __('Permission Denied.'));
        abort_unless(BiometricAgent::visibleTo($user)->whereKey($agent->id)->exists(), 404);
    }

    private function generateIdentifier(): string
    {
        do {
            $id = 'AGT-' . Str::upper(Str::random(8));
        } while (BiometricAgent::where('agent_identifier', $id)->exists());

        return $id;
    }

    private function extractSettings(array $data, array $existing = []): array
    {
        return array_merge($existing, [
            'heartbeat_interval_sec' => (int) ($data['heartbeat_interval_sec'] ?? $existing['heartbeat_interval_sec'] ?? 60),
            'command_poll_interval_sec' => (int) ($data['command_poll_interval_sec'] ?? $existing['command_poll_interval_sec'] ?? 15),
            'log_poll_interval_sec' => (int) ($data['log_poll_interval_sec'] ?? $existing['log_poll_interval_sec'] ?? 60),
            'log_batch_size' => (int) ($data['log_batch_size'] ?? $existing['log_batch_size'] ?? 200),
            'discovery_enabled' => (bool) ($data['discovery_enabled'] ?? false),
            'discovery_cidrs' => array_values($data['discovery_cidrs'] ?? []),
        ]);
    }

    private function provisioningBundle(BiometricAgent $agent, string $plainToken): array
    {
        $serverUrl = rtrim((string) config('app.url'), '/');

        return [
            'agent_identifier' => $agent->agent_identifier,
            'provisioning_token' => $plainToken,
            'expires_at' => $agent->provisioning_token_expires_at?->toIso8601String(),
            'server_url' => $serverUrl,
            'cli_command' => sprintf(
                'python -m hrms_agent provision --server "%s" --agent-id "%s" --token "%s"',
                $serverUrl,
                $agent->agent_identifier,
                $plainToken
            ),
        ];
    }

    private function presentListItem(BiometricAgent $a): array
    {
        $settings = $a->mergedSettings();

        return [
            'id' => $a->id,
            'name' => $a->name,
            'agent_identifier' => $a->agent_identifier,
            'branch' => $a->branch?->name,
            'status' => $a->status->value,
            'status_label' => $a->status->label(),
            'connection_status' => $a->effective_connection_status,
            'agent_version' => $a->agent_version,
            'os_info' => $a->os_info,
            'hostname' => $a->hostname,
            'last_seen_ip' => $a->last_seen_ip,
            'last_seen_at' => $a->last_seen_at?->toIso8601String(),
            'last_sync_at' => $a->last_sync_at?->toIso8601String(),
            'local_queue_depth' => $a->local_queue_depth,
            'local_error_count' => $a->local_error_count,
            'last_error' => $a->last_error,
            'last_error_at' => $a->last_error_at?->toIso8601String(),
            'devices_count' => $a->devices_count ?? 0,
            'pending_discoveries_count' => $a->pending_discoveries_count ?? 0,
            'form' => [
                'name' => $a->name,
                'agent_identifier' => $a->agent_identifier,
                'branch_id' => $a->branch_id,
                'heartbeat_interval_sec' => $settings['heartbeat_interval_sec'],
                'command_poll_interval_sec' => $settings['command_poll_interval_sec'],
                'log_poll_interval_sec' => $settings['log_poll_interval_sec'],
                'log_batch_size' => $settings['log_batch_size'],
                'discovery_enabled' => (bool) $settings['discovery_enabled'],
                'discovery_cidrs' => implode(', ', $settings['discovery_cidrs'] ?? []),
                'notes' => $a->notes ?? '',
            ],
        ];
    }

    private function presentDetail(BiometricAgent $a): array
    {
        return $this->presentListItem($a) + [
            'has_active_provisioning_token' => $a->has_active_provisioning_token,
            'provisioning_token_expires_at' => $a->provisioning_token_expires_at?->toIso8601String(),
            'provisioned_at' => $a->provisioned_at?->toIso8601String(),
            'credentials_rotated_at' => $a->credentials_rotated_at?->toIso8601String(),
            'revoked_at' => $a->revoked_at?->toIso8601String(),
            'access_token_prefix' => $a->access_token_prefix,
            'capabilities' => $a->capabilities ?? [],
            'settings' => $a->mergedSettings(),
            'notes' => $a->notes,
            'creator' => $a->creator?->name,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    private function formOptions(): array
    {
        $companyUsers = $this->employees->companyUserIds((int) Tenant::companyId());

        return [
            'statuses' => AgentStatus::options(),
            'branches' => Branch::whereIn('created_by', $companyUsers)->orderBy('name')->get(['id', 'name']),
        ];
    }
}
