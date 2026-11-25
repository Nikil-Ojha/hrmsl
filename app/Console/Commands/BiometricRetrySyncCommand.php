<?php

namespace App\Console\Commands;

use App\Biometric\Services\SyncFailureRetryService;
use Illuminate\Console\Command;

class BiometricRetrySyncCommand extends Command
{
    protected $signature = 'biometric:retry-sync {--limit=100 : Maximum failed entries to retry in this pass}';

    protected $description = 'Retry due biometric attendance synchronization failures and prune expired idempotency keys';

    public function handle(SyncFailureRetryService $retries): int
    {
        $summary = $retries->retryDue((int) $this->option('limit'));

        $this->info(sprintf(
            'Biometric sync retry: %d attempted, %d resolved, %d remaining, %d expired idempotency key(s) pruned.',
            $summary['attempted'],
            $summary['resolved'],
            $summary['remaining'],
            $summary['pruned_keys']
        ));

        return self::SUCCESS;
    }
}
