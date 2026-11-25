<?php

namespace App\Biometric\Services;

use App\Models\BiometricAgent;

/**
 * Facade delegating Local Agent punch uploads to the canonical
 * PunchSynchronizationService pipeline.
 */
class AgentLogIngestionService
{
    public function __construct(private readonly PunchSynchronizationService $sync)
    {
    }

    /**
     * @param array<int, array<string, mixed>> $logs
     */
    public function ingestBatch(BiometricAgent $agent, array $logs): array
    {
        return $this->sync->ingestAgentBatch($agent, $logs);
    }
}
