<?php

namespace App\Biometric\Services;

use App\Biometric\BiometricDeviceFactory;
use App\Biometric\Contracts\BiometricDeviceInterface;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationContext;
use App\Biometric\Data\OperationResult;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\AgentStatus;
use App\Biometric\Enums\CommandType;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\Support\DeviceActionException;
use App\Models\BiometricDevice;
use App\Models\BiometricSyncRun;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Application service behind every device action. It owns the business
 * rules (permissions are checked by the controller): device must be active,
 * operation must be available, one run per action at a time, history is
 * recorded. All hardware communication is delegated to the device's
 * provider through BiometricDeviceInterface; this class never contains
 * vendor-specific code.
 */
class DeviceManager
{
    /** UI action key => [operation, sync action, requires active device] */
    private const ACTIONS = [
        'connectivity' => [Operation::TestConnection, SyncAction::Connectivity, false],
        'time_sync' => [Operation::SyncDeviceTime, SyncAction::TimeSync, true],
        'employee_sync' => [Operation::EnrollEmployee, SyncAction::EmployeeSync, true],
        'log_fetch' => [Operation::GetAttendanceLogs, SyncAction::LogFetch, true],
        'device_info' => [Operation::DeviceInformation, SyncAction::DeviceInfo, true],
        'user_fetch' => [Operation::GetUsers, SyncAction::UserFetch, true],
        'user_delete' => [Operation::DeleteEmployee, SyncAction::UserDelete, true],
        'log_clear' => [Operation::ClearAttendanceLogs, SyncAction::LogClear, true],
        'restart' => [Operation::RestartDevice, SyncAction::Restart, true],
    ];

    public function __construct(
        private readonly BiometricDeviceFactory $factory,
        private readonly SyncRunService $runs,
        private readonly EmployeeSelector $employees,
        private readonly CommandQueue $commands,
    ) {
    }

    public function provider(BiometricDevice $device): BiometricDeviceInterface
    {
        return $this->factory->make($device);
    }

    /* ================================================================= */
    /* Actions                                                            */
    /* ================================================================= */

    public function checkConnectivity(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        $provider = $this->assertAvailable($device, 'connectivity', checkRunning: false);

        // For agent-managed devices, evaluate agent health and queue a local LAN/USB ping.
        if ($device->isManagedByAgent()) {
            $device->loadMissing('agent');
            $agent = $device->agent;
            $run = $this->runs->start($device, SyncAction::Connectivity, ['via_agent' => $agent?->agent_identifier], $user);

            if (!$agent || $agent->status !== AgentStatus::Active) {
                return $this->runs->finish($run, SyncStatus::Failed, __('Assigned local agent is not active.'), ['via_agent' => true], __('Assigned local agent is not active.'));
            }

            if ($device->is_active) {
                $this->commands->queue($device, CommandType::Ping, [], $run, null, 5, $user);
            }

            $agentOnline = $agent->effective_connection_status === ConnectionStatus::Online->value;
            $deviceFresh = $device->isHeartbeatFresh();
            $checks = [
                [
                    'name' => 'agent',
                    'label' => 'Local Agent',
                    'passed' => $agentOnline,
                    'detail' => $agentOnline
                        ? sprintf('Agent %s is online (last heartbeat %s).', $agent->name, $agent->last_seen_at?->diffForHumans())
                        : sprintf('Agent %s is offline (last heartbeat %s).', $agent->name, $agent->last_seen_at?->diffForHumans() ?? 'never'),
                ],
                [
                    'name' => 'device_health',
                    'label' => 'Device via Agent',
                    'passed' => $deviceFresh,
                    'detail' => $deviceFresh
                        ? sprintf('Device reported healthy %s. A live ping was queued for the agent.', $device->last_seen_at?->diffForHumans())
                        : 'Device has not reported healthy recently. A live ping was queued for the local agent.',
                ],
            ];

            $online = $agentOnline && $deviceFresh;
            $device->forceFill([
                'connection_status' => $online ? ConnectionStatus::Online : ConnectionStatus::Offline,
                'last_connectivity_check_at' => now(),
            ])->saveQuietly();

            $message = $online
                ? __('Device is reachable via local agent :agent. Live ping queued.', ['agent' => $agent->name])
                : __('Device is not currently reported online by local agent :agent. A live ping has been queued.', ['agent' => $agent->name]);

            return $this->runs->finish($run, $online ? SyncStatus::Success : SyncStatus::Failed, $message, [
                'online' => $online,
                'message' => $message,
                'checks' => $checks,
            ], $online ? null : $message);
        }

        $run = $this->runs->start($device, SyncAction::Connectivity, [], $user);
        $result = $provider->testConnection();

        $tcpAttempted = collect($result->checks)->contains(fn ($c) => $c['name'] === 'tcp' && $c['passed'] !== null);
        $tcpSucceeded = collect($result->checks)->contains(fn ($c) => $c['name'] === 'tcp' && $c['passed'] === true);
        $status = match (true) {
            $result->online => ConnectionStatus::Online,
            $device->last_seen_at !== null || $tcpAttempted || $provider::definition()->communication === ProviderDefinition::SIMULATED => ConnectionStatus::Offline,
            default => ConnectionStatus::Unknown,
        };

        $updates = [
            'connection_status' => $status,
            'last_connectivity_check_at' => now(),
        ];

        // When a live TCP connection to the device succeeds, record last_seen_at so
        // effective_connection_status does not immediately revert to Offline on reload.
        if ($tcpSucceeded) {
            $updates['last_seen_at'] = now();
            if ($device->ip_address && !$device->last_seen_ip) {
                $updates['last_seen_ip'] = $device->ip_address;
            }
        }

        $device->forceFill($updates)->saveQuietly();

        return $this->runs->finish(
            $run,
            $result->online ? SyncStatus::Success : SyncStatus::Failed,
            $result->message,
            $result->toArray(),
            $result->online ? null : $result->message
        );
    }

    public function syncTime(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'time_sync', $user, [
            'timezone' => $device->timezoneName(),
            'requested_device_time' => Carbon::now()->setTimezone($device->timezoneName())->format('Y-m-d H:i:s'),
        ], fn (BiometricDeviceInterface $p) => $p->syncDeviceTime(), priority: 10);
    }

    /**
     * @param int[]|null $employeeIds null = every eligible employee in the device scope
     */
    public function syncEmployees(BiometricDevice $device, ?User $user = null, ?array $employeeIds = null): BiometricSyncRun
    {
        $this->assertAvailable($device, 'employee_sync');

        $summary = $this->employees->summary($device);
        $employees = $this->employees->eligible($device, $employeeIds);

        if ($employees->isEmpty()) {
            throw new DeviceActionException($summary['missing_code'] > 0
                ? __('No employees to synchronize. :count employee(s) in scope have no biometric code assigned.', ['count' => $summary['missing_code']])
                : __('No active employees are in scope for this device.'));
        }

        return $this->perform($device, 'employee_sync', $user, [
            'scope' => $employeeIds === null ? $summary['scope'] : 'selected',
            'employee_count' => $employees->count(),
            'skipped_missing_code' => $employeeIds === null ? $summary['missing_code'] : null,
        ], function (BiometricDeviceInterface $provider) use ($employees) {
            return array_map(
                fn ($employee) => $provider->enrollEmployee(DeviceUser::fromEmployee($employee)),
                $employees->all()
            );
        }, priority: 50, alreadyChecked: true);
    }

    public function fetchLogs(BiometricDevice $device, ?User $user = null, ?string $from = null, ?string $to = null): BiometricSyncRun
    {
        $to = $to ? Carbon::parse($to) : now();
        $from = $from ? Carbon::parse($from) : $this->defaultLogFetchStart($device);

        if ($from->gte($to)) {
            throw new DeviceActionException(__('The start of the range must be before the end.'));
        }
        if ($from->diffInDays($to) > 92) {
            throw new DeviceActionException(__('Logs can be fetched for at most 92 days at a time.'));
        }

        return $this->perform($device, 'log_fetch', $user, [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
        ], fn (BiometricDeviceInterface $p) => $p->getAttendanceLogs($from, $to), priority: 20);
    }

    public function refreshInformation(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'device_info', $user, [], fn (BiometricDeviceInterface $p) => $p->getDeviceInformation(true), priority: 30);
    }

    public function fetchUsers(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'user_fetch', $user, [], fn (BiometricDeviceInterface $p) => $p->getUsers(), priority: 40);
    }

    public function removeUser(BiometricDevice $device, string $pin, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'user_delete', $user, ['pin' => $pin], fn (BiometricDeviceInterface $p) => $p->deleteEmployee($pin), priority: 40, allowConcurrent: true);
    }

    public function clearLogs(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'log_clear', $user, [], fn (BiometricDeviceInterface $p) => $p->clearAttendanceLogs(), priority: 60);
    }

    public function restart(BiometricDevice $device, ?User $user = null): BiometricSyncRun
    {
        return $this->perform($device, 'restart', $user, [], fn (BiometricDeviceInterface $p) => $p->restartDevice(), priority: 90);
    }

    /**
     * Which actions the UI should enable, with the reason when not.
     */
    public function availableActions(BiometricDevice $device): array
    {
        $out = [];
        foreach (array_keys(self::ACTIONS) as $key) {
            try {
                $this->assertAvailable($device, $key, checkRunning: false);
                $out[$key] = ['enabled' => true, 'reason' => null];
            } catch (DeviceActionException $e) {
                $out[$key] = ['enabled' => false, 'reason' => $e->getMessage()];
            }
        }

        return $out;
    }

    /* ================================================================= */
    /* Internals                                                          */
    /* ================================================================= */

    /**
     * @param callable(BiometricDeviceInterface): (OperationResult|OperationResult[]) $operation
     */
    private function perform(
        BiometricDevice $device,
        string $action,
        ?User $user,
        array $parameters,
        callable $operation,
        int $priority = 100,
        bool $alreadyChecked = false,
        bool $allowConcurrent = false,
    ): BiometricSyncRun {
        $provider = $alreadyChecked ? $this->factory->make($device) : $this->assertAvailable($device, $action, checkRunning: !$allowConcurrent);
        [, $syncAction] = self::ACTIONS[$action];

        return DB::transaction(function () use ($device, $user, $parameters, $operation, $priority, $provider, $syncAction) {
            $run = $this->runs->start($device, $syncAction, $parameters, $user);
            $results = $operation($provider->withContext(new OperationContext($run, $user, $priority)));

            return $this->settle($run, is_array($results) ? $results : [$results]);
        });
    }

    /**
     * Turn provider results into run state: queued commands wait for the
     * device; synchronous results finish the run immediately.
     *
     * @param OperationResult[] $results
     */
    private function settle(BiometricSyncRun $run, array $results): BiometricSyncRun
    {
        $queued = array_filter($results, fn (OperationResult $r) => $r->isQueued());
        $completed = array_filter($results, fn (OperationResult $r) => $r->isCompleted());
        $failed = array_filter($results, fn (OperationResult $r) => !$r->isQueued() && !$r->isCompleted());

        if ($queued) {
            $count = array_sum(array_map(fn (OperationResult $r) => count($r->commandIds), $queued));
            $run->forceFill([
                'total_items' => $count,
                'message' => __(':count command(s) queued. Waiting for the device to connect and acknowledge.', ['count' => $count]),
            ])->save();

            return $run;
        }

        $total = count($results);
        $run->forceFill([
            'total_items' => $total,
            'success_items' => count($completed),
            'failed_items' => count($failed),
        ])->save();

        $status = match (true) {
            count($failed) === 0 => SyncStatus::Success,
            count($completed) === 0 => SyncStatus::Failed,
            default => SyncStatus::Partial,
        };

        $first = $total === 1 ? $results[0] : null;
        $message = $first ? $first->message : __(':ok of :total item(s) completed.', ['ok' => count($completed), 'total' => $total]);
        $error = $failed ? reset($failed)->message : null;

        return $this->runs->finish($run, $status, $message, $this->summarize($results), $error);
    }

    /** Keep run results small: counts plus a sample of returned records. */
    private function summarize(array $results): array
    {
        if (count($results) !== 1) {
            return ['items' => count($results)];
        }

        $data = $results[0]->toArray()['data'];
        if (is_array($data) && array_is_list($data)) {
            return ['count' => count($data), 'sample' => array_slice($data, 0, 50)];
        }

        return is_array($data) ? ['data' => $data] : [];
    }

    private function assertAvailable(BiometricDevice $device, string $action, bool $checkRunning = true): BiometricDeviceInterface
    {
        [$operation, $syncAction, $requiresActive] = self::ACTIONS[$action];
        $provider = $this->factory->make($device);
        $simulated = $provider::definition()->communication === ProviderDefinition::SIMULATED;

        if ($requiresActive && !$device->is_active) {
            throw new DeviceActionException(__('Activate the device before running this action.'));
        }

        if ($device->protocol === 'local_agent' && !$device->isManagedByAgent()) {
            throw new DeviceActionException(__('Assign a Local Device Communication Agent to this device first.'));
        }

        if (!$simulated && !$device->isNetworked() && !$device->isManagedByAgent()) {
            throw new DeviceActionException(__('USB devices require an assigned Local Device Communication Agent (or manual file import).'));
        }

        if ($reason = $provider->unavailableReason($operation)) {
            throw new DeviceActionException($reason);
        }

        if ($checkRunning && ($running = $this->runs->inProgress($device, $syncAction))) {
            throw new DeviceActionException(__('A :action is already in progress for this device (started :time).', [
                'action' => strtolower($syncAction->label()),
                'time' => $running->started_at?->diffForHumans() ?? '',
            ]));
        }

        return $provider;
    }

    private function defaultLogFetchStart(BiometricDevice $device): CarbonInterface
    {
        $last = $device->lastSuccessfulRun(SyncAction::LogFetch);
        $lastTo = data_get($last?->parameters, 'to');

        return $lastTo ? Carbon::parse($lastTo)->subMinutes(5) : now()->subDays(7);
    }
}
