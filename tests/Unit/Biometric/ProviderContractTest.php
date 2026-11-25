<?php

use App\Biometric\BiometricDeviceFactory;
use App\Biometric\Contracts\BiometricDeviceInterface;
use App\Biometric\Contracts\QueuesDeviceCommands;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationResult;
use App\Biometric\Enums\Operation;
use App\Biometric\Enums\SupportLevel;
use App\Biometric\ProviderRegistry;
use App\Biometric\Providers\FkWebProvider;
use App\Biometric\Providers\GenericBiometricProvider;
use App\Biometric\Providers\ZKTecoProvider;
use App\Models\BiometricDevice;

uses(Tests\TestCase::class);

function providerFor(string $key): BiometricDeviceInterface
{
    $device = new BiometricDevice();
    $device->forceFill(['id' => 99, 'company_id' => 1, 'protocol' => $key, 'timezone' => 'UTC', 'serial_number' => 'SN99', 'device_identifier' => 'D99', 'is_active' => true]);

    return app(BiometricDeviceFactory::class)->make($device);
}

it('resolves a provider implementing the common interface for every configured key', function (string $key) {
    $provider = providerFor($key);

    expect($provider)->toBeInstanceOf(BiometricDeviceInterface::class)
        ->and($provider::definition()->key)->toBe($key);

    foreach (Operation::cases() as $op) {
        expect($provider::definition()->support($op))->toBeInstanceOf(SupportLevel::class);
    }
})->with(['local_agent', 'native', 'zk_adms', 'fkweb', 'generic_http', 'mock']);

it('maps keys to the expected provider classes', function () {
    expect(providerFor('zk_adms'))->toBeInstanceOf(ZKTecoProvider::class)->toBeInstanceOf(QueuesDeviceCommands::class)
        ->and(providerFor('generic_http'))->toBeInstanceOf(GenericBiometricProvider::class)->not->toBeInstanceOf(QueuesDeviceCommands::class)
        ->and(providerFor('fkweb'))->toBeInstanceOf(FkWebProvider::class);
});

it('returns unsupported instead of throwing for operations a protocol lacks', function () {
    $generic = providerFor('generic_http');

    expect($generic->restartDevice()->status)->toBe(OperationResult::UNSUPPORTED)
        ->and($generic->enrollEmployee(new DeviceUser('1', 'A'))->status)->toBe(OperationResult::UNSUPPORTED)
        ->and($generic->supports(Operation::DeviceStatus))->toBeTrue();
});

it('keeps unverified operations disabled until explicitly allowed', function () {
    config(['biometric.providers.zk_adms.allow_unverified' => false, 'biometric.providers.fkweb.allow_unverified' => false]);

    expect(providerFor('zk_adms')->syncDeviceTime()->status)->toBe(OperationResult::DISABLED)
        ->and(providerFor('zk_adms')->unavailableReason(Operation::SyncDeviceTime))->toContain('not been verified')
        ->and(providerFor('fkweb')->enrollEmployee(new DeviceUser('1', 'A'))->status)->toBe(OperationResult::DISABLED)
        ->and(providerFor('zk_adms')->supports(Operation::RestartDevice))->toBeTrue();

    config(['biometric.providers.zk_adms.allow_unverified' => true]);
    expect(providerFor('zk_adms')->supports(Operation::SyncDeviceTime))->toBeTrue();
});

it('describes selectable providers for the UI and hides disabled ones', function () {
    config(['biometric.providers.mock.enabled' => false]);
    $registry = app(ProviderRegistry::class);

    expect($registry->selectableKeys())->toBe(['local_agent', 'native', 'zk_adms', 'fkweb', 'generic_http'])
        ->and($registry->describe('zk_adms')['operations']['sync_device_time'])->toBe(['level' => 'unverified', 'available' => false])
        ->and($registry->describe('generic_http')['capabilities']['employee_sync'])->toBeFalse();

    config(['biometric.providers.mock.enabled' => true]);
    expect($registry->selectableKeys())->toContain('mock');
});
