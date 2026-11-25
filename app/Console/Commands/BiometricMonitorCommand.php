<?php

namespace App\Console\Commands;

use App\Biometric\Enums\CommandStatus;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\Services\AgentApiAuditLogger;
use App\Biometric\Services\CommandQueue;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\SyncRunService;
use App\Models\BiometricCommand;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceEvent;
use App\Models\BiometricSyncRun;
use Illuminate\Console\Command;

/**
 * Housekeeping for biometric devices. Scheduled every minute.
 *  - marks devices offline when their heartbeat goes stale
 *  - expires commands never picked up and fails commands never acknowledged
 *  - finalizes sync runs whose commands are all done
 *  - prunes old device events
 */
class BiometricMonitorCommand extends Command
{
    protected $signature = 'biometric:monitor';

    protected $description = 'Update biometric device connection status, expire stale commands and finalize sync runs';

    public function handle(
        DeviceActivityRecorder $activity,
        CommandQueue $commands,
        SyncRunService $runs,
        AgentApiAuditLogger $audit,
    ): int {
        // 1. Offline detection
        $threshold = now()->subSeconds((int) config('biometric.connectivity.online_threshold_seconds', 300));
        $offline = 0;
        BiometricDevice::where('connection_status', ConnectionStatus::Online->value)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $threshold))
            ->chunkById(200, function ($devices) use ($activity, &$offline) {
                foreach ($devices as $device) {
                    $activity->markOffline($device);
                    $offline++;
                }
            });

        // 1b. Mark stale local agents offline
        $agentThreshold = now()->subSeconds((int) config('biometric.agent.online_threshold_seconds', 180));
        $agentsOffline = BiometricAgent::where('connection_status', ConnectionStatus::Online->value)
            ->where(fn ($q) => $q->whereNull('last_seen_at')->orWhere('last_seen_at', '<', $agentThreshold))
            ->update(['connection_status' => ConnectionStatus::Offline->value, 'updated_at' => now()]);

        // 2. Expire queued commands past their TTL
        $expired = 0;
        BiometricCommand::where('status', CommandStatus::Queued->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->chunkById(200, function ($items) use ($commands, &$expired) {
                foreach ($items as $command) {
                    $commands->fail($command, 'The device did not pick up the command before it expired.', CommandStatus::Expired);
                    $expired++;
                }
            });

        // 3. Fail sent commands that were never acknowledged
        $ackTimeout = now()->subMinutes((int) config('biometric.commands.ack_timeout_minutes', 30));
        $timedOut = 0;
        BiometricCommand::where('status', CommandStatus::Sent->value)
            ->where('sent_at', '<', $ackTimeout)
            ->chunkById(200, function ($items) use ($commands, &$timedOut) {
                foreach ($items as $command) {
                    $commands->fail($command, 'The device did not acknowledge the command in time.');
                    $timedOut++;
                }
            });

        // 4. Finalize runs that have no pending commands left
        BiometricSyncRun::whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value])
            ->where('action', '!=', 'connectivity')
            ->chunkById(200, function ($items) use ($runs) {
                foreach ($items as $run) {
                    $runs->refreshFromCommands($run);
                }
            });

        // 5. Retention (device events, expired replay nonces, aged API audit logs)
        $days = (int) config('biometric.retention.events_days', 90);
        $pruned = BiometricDeviceEvent::where('occurred_at', '<', now()->subDays($days))->delete();
        $apiPruned = $audit->prune();

        $this->info(sprintf(
            'Biometric monitor: %d devices offline, %d agents offline, %d expired, %d timed out, %d events pruned, %d nonces pruned, %d audit logs pruned.',
            $offline, $agentsOffline, $expired, $timedOut, $pruned, $apiPruned['nonces_deleted'], $apiPruned['logs_deleted']
        ));

        return self::SUCCESS;
    }
}
