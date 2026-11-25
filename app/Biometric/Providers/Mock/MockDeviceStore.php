<?php

namespace App\Biometric\Providers\Mock;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

/**
 * Persistent state of a simulated device (users, punches, clock, boots),
 * kept in the cache so it survives between requests during development.
 */
class MockDeviceStore
{
    private const TTL_DAYS = 30;

    public function __construct(private readonly ?Repository $cache = null)
    {
    }

    public function get(int $deviceId): array
    {
        return $this->cache()->get($this->key($deviceId), $this->fresh());
    }

    public function put(int $deviceId, array $state): void
    {
        $this->cache()->put($this->key($deviceId), $state, now()->addDays(self::TTL_DAYS));
    }

    public function update(int $deviceId, callable $mutator): array
    {
        $state = $mutator($this->get($deviceId));
        $this->put($deviceId, $state);

        return $state;
    }

    public function forget(int $deviceId): void
    {
        $this->cache()->forget($this->key($deviceId));
    }

    private function fresh(): array
    {
        return [
            'users' => [],          // pin => [pin, name, card, privilege]
            'logs' => [],           // [pin, punched_at (ISO), state, verify]
            'clock_offset' => 0,    // seconds the simulated clock is off from server time
            'booted_at' => now()->toIso8601String(),
            'offline' => false,
        ];
    }

    private function key(int $deviceId): string
    {
        return "biometric:mock-device:{$deviceId}";
    }

    private function cache(): Repository
    {
        return $this->cache ?? Cache::store();
    }
}
