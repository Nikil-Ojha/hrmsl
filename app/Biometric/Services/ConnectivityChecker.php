<?php

namespace App\Biometric\Services;

use App\Biometric\Support\ConnectivityResult;
use App\Biometric\Support\NetworkGuard;
use App\Models\BiometricDevice;

/**
 * Determines whether a device is reachable using two independent signals:
 *  1. Heartbeat freshness (works for devices behind NAT that push to us).
 *  2. A TCP probe to ip:port (only when the server can reach the device).
 */
class ConnectivityChecker
{
    public function __construct(private readonly NetworkGuard $guard)
    {
    }

    public function check(BiometricDevice $device, bool $probeAllowedByDriver = true): ConnectivityResult
    {
        $checks = [];

        // 1. Heartbeat
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

        // 2. TCP probe
        $probePassed = null;
        if ($probeAllowedByDriver && $device->ip_address && $device->port) {
            $blocked = $this->guard->validate($device->ip_address);
            if ($blocked) {
                $checks[] = ['name' => 'tcp', 'label' => 'TCP probe', 'passed' => null, 'detail' => $blocked];
            } else {
                [$probePassed, $detail] = $this->probe($device->ip_address, (int) $device->port);
                $checks[] = ['name' => 'tcp', 'label' => 'TCP probe', 'passed' => $probePassed, 'detail' => $detail];
            }
        }

        $online = $fresh || $probePassed === true;

        $message = match (true) {
            $fresh && $probePassed !== false => 'Device is online.',
            $fresh && $probePassed === false => 'Device is sending heartbeats but its TCP port is not reachable from the server.',
            $probePassed === true => 'Device port is reachable, but the device has not pushed a heartbeat recently. Check its server/ADMS settings.',
            default => 'Device is not reachable.',
        };

        return new ConnectivityResult($online, $message, $checks);
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
