<?php

use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\SyncAction;
use App\Biometric\Enums\SyncStatus;
use App\Biometric\ProviderRegistry;
use App\Biometric\Services\DashboardService;
use App\Biometric\Services\EmployeeSelector;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\BiometricSyncRun;

uses(Tests\TestCase::class);

function dashboardService(): DashboardService
{
    return new DashboardService(app(ProviderRegistry::class), new EmployeeSelector());
}

it('reports zero attention reasons for a healthy online active device', function () {
    $device = new BiometricDevice([
        'name' => 'Lobby Terminal',
        'is_active' => true,
        'connection_status' => ConnectionStatus::Online,
        'last_seen_at' => now()->subSeconds(30),
        'last_successful_sync_at' => now()->subMinutes(5),
        'last_error' => null,
    ]);

    $reasons = dashboardService()->evaluateAttention($device, 0, null);

    expect($reasons)->toBeEmpty();
});

it('does not flag inactive devices as requiring attention', function () {
    $device = new BiometricDevice([
        'name' => 'Decommissioned Terminal',
        'is_active' => false,
        'connection_status' => ConnectionStatus::Offline,
        'last_error' => 'Old error',
    ]);

    $reasons = dashboardService()->evaluateAttention($device, 5, null);

    expect($reasons)->toBeEmpty();
});

it('flags active offline devices, stale heartbeats, pending failures, failed sync runs and offline agents', function () {
    $agent = new BiometricAgent([
        'name' => 'HQ Agent',
        'connection_status' => ConnectionStatus::Offline,
        'last_seen_at' => now()->subHours(2),
    ]);

    $device = new BiometricDevice([
        'name' => 'Warehouse Terminal',
        'is_active' => true,
        'connection_status' => ConnectionStatus::Online, // heartbeat stale -> effective offline!
        'last_seen_at' => now()->subHours(1),
        'last_error' => 'Connection timed out',
        'last_error_at' => now()->subMinutes(10),
        'last_successful_sync_at' => now()->subHours(2),
    ]);
    $device->setRelation('agent', $agent);

    $failedRun = new BiometricSyncRun([
        'action' => SyncAction::LogSync,
        'status' => SyncStatus::Failed,
    ]);

    $reasons = dashboardService()->evaluateAttention($device, 3, $failedRun);

    expect($reasons)->toHaveCount(5)
        ->and($reasons[0])->toContain('offline')
        ->and($reasons[1])->toContain('3 failed sync event(s)')
        ->and($reasons[2])->toContain('failed')
        ->and($reasons[3])->toContain('Connection timed out')
        ->and($reasons[4])->toContain('HQ Agent');
});
