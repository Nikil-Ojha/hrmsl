<?php

namespace App\Biometric\Services;

use App\Biometric\Support\ConnectivityResult;
use App\Biometric\Support\NetworkGuard;
use App\Models\BiometricAttendanceLog;
use App\Models\BiometricDevice;
use App\Models\BiometricPunch;
use Illuminate\Support\Carbon;

/**
 * Determines whether a device is reachable using two independent signals:
 *  1. Heartbeat / recent push activity (works for devices behind NAT that push to us).
 *  2. A TCP probe to ip:port (only when the server can reach the device).
 */
class ConnectivityChecker
{
    public function __construct(private readonly NetworkGuard $guard)
    {
    }

    public function check(BiometricDevice $device, bool $probeAllowedByDriver = true, ?int $defaultPort = null): ConnectivityResult
    {
        $checks = [];

        // Reconcile recent contact from attendance logs / punches in case the device was
        // already pushing to the server before it was registered (or via another intake route).
        $this->reconcileRecentContact($device);

        // 1. Heartbeat / recent contact
        $threshold = (int) config('biometric.connectivity.online_threshold_seconds', 300);
        $fresh = $device->isHeartbeatFresh();
        $checks[] = [
            'name' => 'heartbeat',
            'label' => 'Device heartbeat',
            'passed' => $fresh,
            'detail' => $device->last_seen_at
                ? sprintf('Last contact %s (threshold %ds).', $device->last_seen_at->diffForHumans(), $threshold)
                : 'The device has never contacted the server.',
        ];

        // 2. TCP probe (uses explicit port or falls back to the provider's default port)
        $probePassed = null;
        $probeDetail = null;
        $blockedReason = null;
        $isPrivateBlocked = false;
        $port = $device->port ?: $defaultPort;

        if ($probeAllowedByDriver && $device->ip_address && $port) {
            $blockedReason = $this->guard->validate($device->ip_address);
            if ($blockedReason) {
                $isPrivateBlocked = str_contains(strtolower($blockedReason), 'private network');
                $checks[] = ['name' => 'tcp', 'label' => 'TCP probe', 'passed' => null, 'detail' => $blockedReason];
            } else {
                [$probePassed, $probeDetail] = $this->probe($device->ip_address, (int) $port);
                $checks[] = ['name' => 'tcp', 'label' => 'TCP probe', 'passed' => $probePassed, 'detail' => $probeDetail];
            }
        }

        $online = $fresh || $probePassed === true;

        $message = match (true) {
            $fresh && $probePassed !== false => 'Device is online.',
            $fresh && $probePassed === false => 'Device is sending heartbeats, but its TCP port is not reachable from the server.',
            $probePassed === true => 'Device TCP port is reachable (online), though it has not pushed a heartbeat recently. Check its server/push settings if push sync is expected.',
            $isPrivateBlocked && $device->last_seen_at !== null => sprintf(
                'Device heartbeat is stale (last contact %s; threshold %ds). %s is a private LAN IP that this server cannot probe directly — check the device push settings (Setup tab) or connect it via a Local Agent.',
                $device->last_seen_at->diffForHumans(),
                $threshold,
                $device->ip_address
            ),
            $isPrivateBlocked => sprintf(
                'No heartbeat received from this device yet. %s is a private LAN IP that this server cannot reach directly — configure the device to push to this server (see Setup tab) or connect it via a Local Agent.',
                $device->ip_address
            ),
            $probePassed === false => sprintf('%s No recent heartbeat was received from the device either.', $probeDetail),
            $blockedReason !== null => sprintf('%s No recent heartbeat was received from the device.', $blockedReason),
            default => 'No heartbeat received from this device yet. Configure the device push URL (see Setup tab) or assign a Local Agent.',
        };

        return new ConnectivityResult($online, $message, $checks);
    }

    /**
     * If the device has already pushed logs into biometric_punches or
     * biometric_attendance_logs more recently than biometric_devices.last_seen_at,
     * sync last_seen_at / last_log_received_at so already-online devices are recognized.
     */
    private function reconcileRecentContact(BiometricDevice $device): void
    {
        $latest = null;

        if ($device->exists && $device->id) {
            $punchLatest = BiometricPunch::where('device_id', $device->id)->max('created_at');
            if ($punchLatest) {
                $latest = Carbon::parse($punchLatest);
            }
        }

        $identifiers = array_values(array_unique(array_filter([
            trim((string) $device->serial_number),
            trim((string) $device->device_identifier),
        ])));

        if (!empty($identifiers)) {
            $logQuery = BiometricAttendanceLog::where(function ($q) use ($device, $identifiers) {
                if ($device->exists && $device->id) {
                    $q->where('device_id', $device->id)->orWhereIn('device_sn', $identifiers);
                } else {
                    $q->whereIn('device_sn', $identifiers);
                }
            });

            if ($device->company_id) {
                $logQuery->where(function ($q) use ($device) {
                    $q->where('company_id', $device->company_id)
                        ->orWhere(function ($legacy) use ($device) {
                            $legacy->whereNull('company_id')
                                ->where('created_by', $device->company_id);
                        });
                });
            }

            $logLatest = $logQuery->max('created_at');
            if ($logLatest) {
                $logCarbon = Carbon::parse($logLatest);
                if (!$latest || $logCarbon->gt($latest)) {
                    $latest = $logCarbon;
                }
            }
        }

        if (!$latest) {
            return;
        }

        $updates = [];
        if (!$device->last_seen_at || $latest->gt($device->last_seen_at)) {
            $updates['last_seen_at'] = $latest;
        }
        if (!$device->last_log_received_at || $latest->gt($device->last_log_received_at)) {
            $updates['last_log_received_at'] = $latest;
        }

        if ($updates) {
            $device->forceFill($updates);
            if ($device->exists) {
                $device->saveQuietly();
            }
        }
    }

    /**
     * @return array{0: bool, 1: string}
     */
    private function probe(string $host, int $port): array
    {
        $timeout = (float) config('biometric.connectivity.probe_timeout_seconds', 3);
        $started = microtime(true);
        $errno = 0;
        $errstr = '';

        $socket = @fsockopen($host, $port, $errno, $errstr, $timeout);
        $ms = (int) round((microtime(true) - $started) * 1000);

        if ($socket) {
            fclose($socket);

            return [true, sprintf('Connected to %s:%d in %d ms.', $host, $port, $ms)];
        }

        return [false, sprintf('Could not connect to %s:%d (%s).', $host, $port, $errstr ?: 'timeout')];
    }
}
