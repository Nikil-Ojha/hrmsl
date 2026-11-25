<?php

namespace App\Biometric\Contracts;

use App\Biometric\Data\ConnectionResult;
use App\Biometric\Data\DeviceStatus;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\OperationContext;
use App\Biometric\Data\OperationResult;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\Operation;
use App\Biometric\Support\ConnectivityResult;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use Carbon\CarbonInterface;

/**
 * Vendor-independent contract every biometric hardware provider implements.
 *
 * A provider instance is bound to exactly one device (see
 * {@see \App\Biometric\BiometricDeviceFactory}). Operations either complete
 * synchronously (pull / simulated transports) or queue commands that the
 * device executes on its next poll (push transports); the returned
 * {@see OperationResult} tells the caller which happened.
 *
 * Operations a provider cannot perform return OperationResult::unsupported()
 * (or ::disabled() for unverified operations that are switched off) instead
 * of throwing, so callers never need vendor-specific branching.
 */
interface BiometricDeviceInterface
{
    /** Static description: identity, transport, auth options, operation support matrix. */
    public static function definition(): ProviderDefinition;

    public function device(): BiometricDevice;

    /** Return a copy that links queued commands to a sync run / user. */
    public function withContext(OperationContext $context): static;

    /** Whether the operation can be executed right now (support level + configuration). */
    public function supports(Operation $operation): bool;

    /** Explanation for why an operation is not available, or null if it is. */
    public function unavailableReason(Operation $operation): ?string;

    public function connect(): ConnectionResult;

    public function disconnect(): void;

    public function testConnection(): ConnectivityResult;

    /** @param bool $refresh Ask the device for fresh information instead of the last reported values. */
    public function getDeviceInformation(bool $refresh = false): OperationResult;

    public function getDeviceStatus(): DeviceStatus;

    public function getUsers(): OperationResult;

    public function enrollEmployee(DeviceUser $user): OperationResult;

    public function updateEmployee(DeviceUser $user): OperationResult;

    public function deleteEmployee(string $pin): OperationResult;

    public function getAttendanceLogs(?CarbonInterface $from = null, ?CarbonInterface $to = null): OperationResult;

    public function clearAttendanceLogs(): OperationResult;

    public function syncDeviceTime(?CarbonInterface $time = null): OperationResult;

    public function restartDevice(): OperationResult;

    /**
     * Values the installer must configure on the device (server URL, port, ...).
     *
     * @return array<int, array{label: string, value: string}>
     */
    public function setupHints(): array;

    /** Called when the device acknowledges a command queued by this provider. */
    public function onCommandAcknowledged(BiometricCommand $command, bool $success, array $result = []): void;
}
