<?php

namespace App\Biometric\Services;

use App\Biometric\BiometricDeviceFactory;
use App\Biometric\Contracts\QueuesDeviceCommands;
use App\Biometric\Enums\CommandStatus;
use App\Biometric\Enums\CommandType;
use App\Biometric\Support\EncodedCommand;
use App\Biometric\Support\UnsupportedCommandException;
use App\Models\BiometricCommand;
use App\Biometric\Data\DeviceUser;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceCommandLog;
use App\Models\BiometricEncryptedTemplate;
use App\Models\BiometricSyncRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Vendor-neutral outbound command queue. Protocol endpoints call
 * {@see nextFor()} when a device polls and {@see acknowledge()} when it reports
 * the result.
 */
class CommandQueue
{
    public function __construct(
        private readonly BiometricDeviceFactory $factory,
        private readonly SyncRunService $runs,
        private readonly DeviceActivityRecorder $activity,
        private readonly DeviceUserDirectory $directory,
    ) {
    }

    public function queue(
        BiometricDevice $device,
        CommandType $type,
        array $payload = [],
        ?BiometricSyncRun $run = null,
        ?int $employeeId = null,
        int $priority = 100,
        ?User $user = null,
    ): BiometricCommand {
        $command = BiometricCommand::create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'agent_id' => $device->agent_id,
            'sync_run_id' => $run?->id,
            'employee_id' => $employeeId,
            'type' => $type,
            'payload' => $payload ?: null,
            'status' => CommandStatus::Queued,
            'priority' => $priority,
            'expires_at' => now()->addMinutes((int) config('biometric.commands.ttl_minutes', 1440)),
            'created_by' => $user?->id,
        ]);

        $this->logTransition($command, 'queued', null, CommandStatus::Queued->value, $device->agent_id, $user?->id, 'Command queued for delivery.');

        return $command;
    }

    /**
     * Claim and encode the next commands for a polling device.
     *
     * @return Collection<int, array{command: BiometricCommand, encoded: EncodedCommand}>
     */
    public function nextFor(BiometricDevice $device, ?int $limit = null): Collection
    {
        if (!$device->is_active) {
            return collect();
        }

        $limit = $limit ?: (int) config('biometric.commands.batch_size', 20);
        $provider = $this->factory->make($device);
        $adapter = $provider instanceof QueuesDeviceCommands ? $provider->adapter() : null;

        return DB::transaction(function () use ($device, $limit, $adapter) {
            $commands = BiometricCommand::where('device_id', $device->id)
                ->where('status', CommandStatus::Queued->value)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('priority')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = collect();
            foreach ($commands as $command) {
                if (!$adapter) {
                    $this->fail($command, 'This device provider does not accept queued commands.');
                    continue;
                }

                try {
                    $encoded = $adapter->encode($command, $device);
                } catch (UnsupportedCommandException $e) {
                    $this->fail($command, $e->getMessage());
                    continue;
                }

                $prevStatus = $command->status->value;
                $command->forceFill([
                    'status' => CommandStatus::Sent,
                    'wire_payload' => $encoded->toStorage(),
                    'sent_at' => now(),
                    'attempts' => $command->attempts + 1,
                ])->save();

                $this->logTransition($command, 'sent', $prevStatus, CommandStatus::Sent->value, $device->agent_id, null, 'Dispatched to device protocol endpoint.');

                $out->push(['command' => $command, 'encoded' => $encoded]);
            }

            return $out;
        });
    }

    /**
     * Handle the device's result for a command (by wire id).
     */
    public function acknowledge(BiometricDevice $device, int|string $wireId, bool $success, ?string $returnCode = null, array $result = []): ?BiometricCommand
    {
        $id = BiometricCommand::idFromWireId($wireId);
        if (!$id) {
            return null;
        }

        // Scoped to the reporting device: a device can never ack another device's command.
        $command = BiometricCommand::where('id', $id)->where('device_id', $device->id)->first();
        if (!$command || $command->status->isFinal()) {
            return $command;
        }

        $prevStatus = $command->status->value;
        $sanitizedResult = $this->sanitizeResult($result);

        $command->forceFill([
            'status' => $success ? CommandStatus::Acknowledged : CommandStatus::Failed,
            'acknowledged_at' => now(),
            'return_code' => $returnCode,
            'result' => $sanitizedResult ?: null,
            'error' => $success ? null : ('Device returned code ' . ($returnCode ?? 'unknown') . '.'),
        ])->save();

        // Vendor-specific interpretation of the result lives in the provider.
        $this->factory->make($device)->onCommandAcknowledged($command, $success, $sanitizedResult);
        try {
            app(EnrollmentService::class)->handleCommandAck($command, $success, $result);
        } catch (\App\Biometric\Support\DeviceActionException $e) {
            $command->forceFill(['status' => CommandStatus::Failed, 'error' => $e->getMessage()])->save();
            $success = false;
        }

        $this->logTransition(
            $command,
            $success ? 'acknowledged' : 'failed',
            $prevStatus,
            $command->status->value,
            $device->agent_id,
            null,
            $success ? 'Device acknowledged command.' : $command->error,
            $returnCode,
            $sanitizedResult ?: null,
        );

        if (!$success) {
            $this->activity->error($device, 'command_failed', sprintf(
                '%s command failed on device (return code %s).',
                $command->type->label(),
                $returnCode ?? 'unknown'
            ), ['command_id' => $command->id], $command->syncRun);
        }

        if ($command->syncRun) {
            $this->runs->refreshFromCommands($command->syncRun);
        }

        return $command;
    }

    public function fail(BiometricCommand $command, string $error, CommandStatus $status = CommandStatus::Failed): void
    {
        $prevStatus = $command->status->value;
        $command->forceFill(['status' => $status, 'error' => $error])->save();

        $this->logTransition($command, $status->value, $prevStatus, $status->value, $command->agent_id, null, $error);

        if ($command->syncRun) {
            $this->runs->refreshFromCommands($command->syncRun);
        }
    }

    public function cancelPending(BiometricDevice $device, string $reason): int
    {
        $pending = BiometricCommand::where('device_id', $device->id)->pending()->get();
        $runIds = $pending->pluck('sync_run_id')->filter()->unique();

        foreach ($pending as $cmd) {
            $prevStatus = $cmd->status->value;
            $cmd->forceFill([
                'status' => CommandStatus::Cancelled,
                'error' => $reason,
            ])->save();
            $this->logTransition($cmd, 'cancelled', $prevStatus, CommandStatus::Cancelled->value, $cmd->agent_id, auth()->id(), $reason);
        }

        foreach (BiometricSyncRun::whereIn('id', $runIds)->get() as $run) {
            $this->runs->refreshFromCommands($run);
        }

        return $pending->count();
    }

    /**
     * Claim pending vendor-neutral commands for all active devices assigned to
     * a Local Device Communication Agent.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function nextForAgent(BiometricAgent $agent, ?int $limit = null): Collection
    {
        $limit = min(max(1, $limit ?: (int) config('biometric.commands.batch_size', 20)), 100);

        return DB::transaction(function () use ($agent, $limit) {
            $deviceIds = BiometricDevice::where('agent_id', $agent->id)
                ->where('company_id', $agent->company_id)
                ->where('is_active', true)
                ->pluck('id');

            if ($deviceIds->isEmpty()) {
                return collect();
            }

            $commands = BiometricCommand::with('device')
                ->whereIn('device_id', $deviceIds)
                ->where('status', CommandStatus::Queued->value)
                ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->orderBy('priority')
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            $out = collect();
            foreach ($commands as $command) {
                $payload = $command->payload ?? [];
                if ($command->type === CommandType::SyncTime && empty($payload['datetime'])) {
                    $tz = $command->device?->timezoneName() ?: config('app.timezone', 'UTC');
                    $payload['timezone'] = $tz;
                    $payload['datetime'] = now()->setTimezone($tz)->format('Y-m-d\TH:i:sP');
                }

                $loggedPayload = $payload;
                if ($command->type === CommandType::TemplatePush && !empty($payload['template_id'])) {
                    $tpl = BiometricEncryptedTemplate::find((int) $payload['template_id']);
                    if (!$tpl || !$command->device) {
                        $this->fail($command, 'Encrypted biometric template is no longer available for transfer.');
                        continue;
                    }
                    try {
                        $payload['template_data'] = app(BiometricTemplateVault::class)->decryptForAuthorizedTransfer($tpl, $command->device);
                        $loggedPayload['template_data'] = '[REDACTED_TEMPLATE_REF:' . $tpl->id . ']';
                    } catch (\Throwable $e) {
                        $this->fail($command, $e->getMessage());
                        continue;
                    }
                }

                $envelope = [
                    'command_id' => $command->id,
                    'device_id' => $command->device_id,
                    'device_serial' => $command->device?->serial_number,
                    'device_identifier' => $command->device?->device_identifier,
                    'type' => $command->type->value,
                    'payload' => (object) $payload,
                    'issued_at' => $command->created_at?->toIso8601String(),
                    'expires_at' => $command->expires_at?->toIso8601String(),
                ];

                $storedEnvelope = $envelope;
                $storedEnvelope['payload'] = (object) $loggedPayload;

                $prevStatus = $command->status->value;
                $command->forceFill([
                    'agent_id' => $agent->id,
                    'status' => CommandStatus::Sent,
                    'wire_payload' => json_encode($storedEnvelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'sent_at' => now(),
                    'attempts' => $command->attempts + 1,
                ])->save();

                $this->logTransition($command, 'sent', $prevStatus, CommandStatus::Sent->value, $agent->id, null, "Claimed by local agent '{$agent->name}'.");

                $out->push($envelope);
            }

            return $out;
        });
    }

    /**
     * Acknowledge a command executed locally by a BiometricAgent.
     */
    public function acknowledgeFromAgent(
        BiometricAgent $agent,
        int $commandId,
        bool $success,
        ?string $returnCode = null,
        array $result = [],
        ?string $error = null
    ): ?BiometricCommand {
        $command = BiometricCommand::with(['device', 'syncRun'])
            ->where('id', $commandId)
            ->where('company_id', $agent->company_id)
            ->first();

        if (!$command || !$command->device || (int) $command->device->agent_id !== (int) $agent->id) {
            return null;
        }

        if ($command->status->isFinal()) {
            return $command;
        }

        $prevStatus = $command->status->value;
        $sanitizedResult = $this->sanitizeResult($result);

        $command->forceFill([
            'agent_id' => $agent->id,
            'status' => $success ? CommandStatus::Acknowledged : CommandStatus::Failed,
            'acknowledged_at' => now(),
            'return_code' => $returnCode,
            'result' => $sanitizedResult ?: null,
            'error' => $success ? null : ($error ?: ('Agent reported failure (code ' . ($returnCode ?? 'unknown') . ').')),
        ])->save();

        $device = $command->device;

        // If the agent fetched the device's user list, update our directory mirror.
        if ($success && $command->type === CommandType::QueryUsers && !empty($result['users']) && is_array($result['users'])) {
            $users = [];
            foreach ($result['users'] as $u) {
                if (is_array($u) && !empty($u['pin'])) {
                    $users[] = new DeviceUser(
                        pin: (string) $u['pin'],
                        name: (string) ($u['name'] ?? ''),
                        card: !empty($u['card']) ? (string) $u['card'] : null,
                        privilege: (int) ($u['privilege'] ?? 0),
                    );
                }
            }
            $this->directory->replaceAll($device, $users, 'device_report');
        }

        $this->factory->make($device)->onCommandAcknowledged($command, $success, $sanitizedResult);
        try {
            app(EnrollmentService::class)->handleCommandAck($command, $success, $result);
        } catch (\App\Biometric\Support\DeviceActionException $e) {
            $command->forceFill(['status' => CommandStatus::Failed, 'error' => $e->getMessage()])->save();
            $success = false;
        }

        if ($success) {
            $this->activity->heartbeat($device, $agent->last_seen_ip);
        } else {
            $this->activity->error($device, 'command_failed', sprintf(
                '%s command failed via agent %s: %s',
                $command->type->label(),
                $agent->name,
                $command->error
            ), ['command_id' => $command->id, 'agent_id' => $agent->id], $command->syncRun);
        }

        if ($command->syncRun) {
            $this->runs->refreshFromCommands($command->syncRun);
        }

        $this->logTransition(
            $command,
            $success ? 'acknowledged' : 'failed',
            $prevStatus,
            $command->status->value,
            $agent->id,
            null,
            $success ? "Executed via local agent '{$agent->name}'." : $command->error,
            $returnCode,
            $sanitizedResult ?: null,
        );

        return $command;
    }

    private function logTransition(
        BiometricCommand $command,
        string $event,
        ?string $fromStatus,
        string $toStatus,
        ?int $agentId = null,
        ?int $actorId = null,
        ?string $message = null,
        ?string $returnCode = null,
        ?array $responsePayload = null,
    ): void {
        $latencyMs = null;
        if (in_array($event, ['acknowledged', 'failed'], true) && $command->sent_at) {
            $latencyMs = max(0, (int) $command->sent_at->diffInMilliseconds(now()));
        }

        try {
            BiometricDeviceCommandLog::create([
                'company_id' => $command->company_id,
                'command_id' => $command->id,
                'device_id' => $command->device_id,
                'agent_id' => $agentId ?? $command->agent_id,
                'actor_id' => $actorId,
                'event' => $event,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'attempt_number' => (int) ($command->attempts ?? 0),
                'return_code' => $returnCode,
                'message' => $message !== null ? mb_substr($message, 0, 500) : null,
                'response_payload' => $responsePayload,
                'latency_ms' => $latencyMs,
                'occurred_at' => now(),
            ]);
        } catch (\Throwable) {
            // Never disrupt command processing if logging fails in a partial test environment.
        }
    }

    private function sanitizeResult(array $result): array
    {
        foreach (['template_data', 'template', 'image', 'photo', 'raw_image', 'fingerprint_image', 'face_image'] as $sensitiveKey) {
            if (array_key_exists($sensitiveKey, $result)) {
                $result[$sensitiveKey] = '[REDACTED]';
            }
        }

        return $result;
    }
}
