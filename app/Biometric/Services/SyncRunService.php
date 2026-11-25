<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\CommandStatus;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncStatus;
use App\Models\BiometricDevice;
use App\Models\BiometricSyncRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle of a synchronization run (sync history).
 */
class SyncRunService
{
    public function __construct(private readonly DeviceActivityRecorder $activity)
    {
    }

    public function start(
        BiometricDevice $device,
        SyncAction $action,
        array $parameters = [],
        ?User $user = null,
        string $trigger = 'manual'
    ): BiometricSyncRun {
        return BiometricSyncRun::create([
            'company_id' => $device->company_id,
            'device_id' => $device->id,
            'action' => $action,
            'trigger' => $trigger,
            'status' => SyncStatus::Running,
            'parameters' => $parameters ?: null,
            'started_at' => now(),
            'initiated_by' => $user?->id,
        ]);
    }

    public function inProgress(BiometricDevice $device, SyncAction $action): ?BiometricSyncRun
    {
        return BiometricSyncRun::where('device_id', $device->id)
            ->where('action', $action->value)
            ->whereIn('status', [SyncStatus::Queued->value, SyncStatus::Running->value])
            ->latest('id')
            ->first();
    }

    public function finish(
        BiometricSyncRun $run,
        SyncStatus $status,
        ?string $message = null,
        array $result = [],
        ?string $error = null
    ): BiometricSyncRun {
        $run->forceFill([
            'status' => $status,
            'message' => $message ?? $run->message,
            'result' => $result ?: $run->result,
            'error' => $error,
            'finished_at' => now(),
        ])->save();

        $device = $run->device;
        if ($device && $run->action !== SyncAction::Connectivity) {
            $attributes = ['last_sync_at' => now()];
            if (in_array($status, [SyncStatus::Success, SyncStatus::Partial], true)) {
                $attributes['last_successful_sync_at'] = now();
            }
            $device->forceFill($attributes)->saveQuietly();
        }

        if ($device && $status === SyncStatus::Failed) {
            $this->activity->error($device, $run->action->value . '_failed', $error ?: ($message ?: 'Synchronization failed.'), [], $run);
        }

        return $run;
    }

    /**
     * Recalculate a command-backed run from its commands and finalize it once
     * no command is pending any more.
     */
    public function refreshFromCommands(BiometricSyncRun $run): BiometricSyncRun
    {
        if ($run->status->isFinal()) {
            return $run;
        }

        $counts = $run->commands()
            ->select('status', DB::raw('COUNT(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $count = fn (CommandStatus ...$statuses) => array_sum(array_map(fn ($s) => (int) ($counts[$s->value] ?? 0), $statuses));

        $success = $count(CommandStatus::Acknowledged);
        $failed = $count(CommandStatus::Failed, CommandStatus::Expired, CommandStatus::Cancelled);
        $pending = $count(CommandStatus::Queued, CommandStatus::Sent);
        $total = $success + $failed + $pending;

        $run->forceFill([
            'total_items' => max($run->total_items, $total),
            'success_items' => $success,
            'failed_items' => $failed,
        ])->save();

        if ($pending > 0) {
            return $run;
        }

        if ($total === 0) {
            return $this->finish($run, SyncStatus::Failed, null, [], 'No commands were generated.');
        }

        $status = match (true) {
            $failed === 0 => SyncStatus::Success,
            $success === 0 => SyncStatus::Failed,
            default => SyncStatus::Partial,
        };

        $message = sprintf('%d of %d command(s) acknowledged by the device.', $success, $total);
        $error = $status === SyncStatus::Success ? null : $this->firstCommandError($run);

        return $this->finish($run, $status, $message, $run->result ?? [], $error);
    }

    private function firstCommandError(BiometricSyncRun $run): ?string
    {
        $failed = $run->commands()
            ->whereIn('status', [CommandStatus::Failed->value, CommandStatus::Expired->value, CommandStatus::Cancelled->value])
            ->orderBy('id')
            ->first();

        if (!$failed) {
            return null;
        }

        return $failed->error ?: ('Command ' . $failed->status->value . '.');
    }
}
