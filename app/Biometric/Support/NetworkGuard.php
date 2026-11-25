<?php

namespace App\Biometric\Support;

/**
 * Decides whether the server may open a TCP connection to an admin-supplied
 * address. Prevents the connectivity check from being abused to scan the
 * server's own network (SSRF).
 */
class NetworkGuard
{
    public function validate(string $host): ?string
    {
        $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : null;

        if (!$ip) {
            $resolved = gethostbyname($host);
            if ($resolved === $host || !filter_var($resolved, FILTER_VALIDATE_IP)) {
                return 'The device address could not be resolved.';
            }
            $ip = $resolved;
        }

        if ($this->isAlwaysBlocked($ip)) {
            return 'Probing this address is not permitted.';
        }

        $isPrivate = !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE);
        if ($isPrivate && !$this->privateAllowed()) {
            return 'This device is on a private network that the server cannot reach directly. Connectivity is determined from the device heartbeat instead.';
        }

        return null;
    }

    public function privateAllowed(): bool
    {
        $configured = config('biometric.connectivity.allow_private_probe');
        if ($configured !== null && $configured !== '') {
            return filter_var($configured, FILTER_VALIDATE_BOOLEAN);
        }

        // On-premise (non-SaaS) installs sit on the same LAN as the devices.
        return !isSaas();
    }

    private function isAlwaysBlocked(string $ip): bool
    {
        // Loopback, link-local (incl. cloud metadata 169.254.169.254), unspecified, multicast.
        $blockedCidrs = [
            '0.0.0.0/8', '127.0.0.0/8', '169.254.0.0/16', '224.0.0.0/4', '240.0.0.0/4', '100.64.0.0/10',
            '::/128', '::1/128', 'fe80::/10', 'ff00::/8',
        ];

        foreach ($blockedCidrs as $cidr) {
            if ($this->inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    private function inCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton($subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if (strncmp($ipBin, $subnetBin, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (ord($ipBin[$bytes]) & ord($mask)) === (ord($subnetBin[$bytes]) & ord($mask));
    }
}
