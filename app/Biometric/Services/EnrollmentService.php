<?php

namespace App\Biometric\Services;

use App\Biometric\BiometricDeviceFactory;
use App\Biometric\Data\DeviceUser;
use App\Biometric\Data\ProviderDefinition;
use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\CommandType;
use App\Biometric\Enums\EnrollmentStatus;
use App\Biometric\Enums\EventLevel;
use App\Biometric\Enums\FingerSlot;
use App\Biometric\Enums\Operation;
use App\Biometric\Support\DeviceActionException;
use App\Biometric\Support\Tenant;
use App\Models\BiometricCommand;
use App\Models\BiometricDevice;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricEncryptedTemplate;
use App\Models\BiometricEnrollment;
use App\Models\BiometricEnrollmentCredential;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Orchestrates employee biometric enrollment, re-enrollment, removal, and
 * multi-device synchronization across vendor-independent providers and local
 * agents.
 */
class EnrollmentService
{
    public function __construct(
        private readonly BiometricDeviceFactory $factory,
        private readonly CommandQueue $commands,
        private readonly EmployeeSelector $employees,
        private readonly DeviceActivityRecorder $activity,
        private readonly BiometricTemplateVault $vault,
    ) {
    }

    /**
     * Ensure the employee has a unique device_user_id (PIN) within their company.
     */
    public function ensureDeviceUserId(Employee $employee, int $companyId, ?string $customPin = null): string
    {
        $companyUsers = $this->employees->companyUserIds($companyId);

        if ($customPin !== null && trim($customPin) !== '') {
            $pin = trim($customPin);
            if (!preg_match('/^[0-9]{1,9}$/', $pin)) {
                throw new DeviceActionException(__('Device user ID must be 1 to 9 numeric digits.'));
            }

            $collision = Employee::whereIn('created_by', $companyUsers)
                ->where('biometric_emp_id', $pin)
                ->where('id', '!=', $employee->id)
                ->exists();

            if ($collision) {
                throw new DeviceActionException(__('Device user ID :pin is already assigned to another employee in your company.', ['pin' => $pin]));
            }

            if ((string) $employee->biometric_emp_id !== $pin) {
                $employee->forceFill(['biometric_emp_id' => $pin])->saveQuietly();
            }

            return $pin;
        }

        if (!empty($employee->biometric_emp_id)) {
            return (string) $employee->biometric_emp_id;
        }

        $max = Employee::whereIn('created_by', $companyUsers)
            ->whereNotNull('biometric_emp_id')
            ->whereRaw('biometric_emp_id REGEXP "^[0-9]+$"')
            ->max(DB::raw('CAST(biometric_emp_id AS UNSIGNED)'));

        $next = $max ? max(1001, ((int) $max) + 1) : 1001;
        while (Employee::whereIn('created_by', $companyUsers)->where('biometric_emp_id', (string) $next)->exists()) {
            $next++;
        }

        $pin = (string) $next;
        $employee->forceFill(['biometric_emp_id' => $pin])->saveQuietly();

        return $pin;
    }

    /**
     * Start biometric enrollment (or re-enrollment) for an employee on a device.
     * Supports multiple finger slots (0..9) or facial recognition / card / PIN.
     *
     * @param int[] $fingerSlots
     */
    public function startEnrollment(
        Employee $employee,
        BiometricDevice $device,
        BiometricModality $modality,
        array $fingerSlots = [6],
        ?string $customPin = null,
        ?User $actor = null,
        bool $isReEnroll = false,
    ): BiometricEnrollment {
        $this->assertDeviceReadyForEnrollment($device, $modality);

        $slots = $this->normalizeSlots($modality, $fingerSlots);
        $employee->loadMissing('user:id,name');
        $pin = $this->ensureDeviceUserId($employee, (int) $device->company_id, $customPin);

        return DB::transaction(function () use ($employee, $device, $modality, $slots, $pin, $actor, $isReEnroll) {
            $enrollment = BiometricEnrollment::firstOrNew([
                'employee_id' => $employee->id,
                'device_id' => $device->id,
            ]);

            if (!$enrollment->exists) {
                $enrollment->company_id = $device->company_id;
                $enrollment->enrollment_reference = $this->generateReference();
            }

            $enrollment->device_user_id = $pin;
            $enrollment->enrolled_by = $actor?->id ?? $enrollment->enrolled_by;
            $enrollment->last_error = null;
            if ($isReEnroll) {
                $enrollment->re_enrollment_count = ($enrollment->re_enrollment_count ?? 0) + 1;
            }
            $enrollment->enrollment_status = EnrollmentStatus::Pending;
            $enrollment->save();

            // Maintain normalized employee <-> device user ID mapping
            BiometricEmployeeMapping::updateOrCreate(
                [
                    'company_id' => $device->company_id,
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                ],
                [
                    'device_user_id' => $pin,
                    'is_active' => true,
                    'source' => 'enrollment',
                    'created_by' => $actor?->id,
                ]
            );

            // Create/reset credential metadata rows for each requested slot
            foreach ($slots as $slot) {
                BiometricEnrollmentCredential::updateOrCreate(
                    [
                        'enrollment_id' => $enrollment->id,
                        'modality' => $modality->value,
                        'slot_index' => $slot,
                    ],
                    [
                        'company_id' => $device->company_id,
                        'employee_id' => $employee->id,
                        'device_id' => $device->id,
                        'slot_label' => $this->slotLabel($modality, $slot),
                        'status' => EnrollmentStatus::Pending,
                    ]
                );
            }

            $provider = $this->factory->make($device);
            $userDto = new DeviceUser(
                pin: $pin,
                name: (string) ($employee->user?->name ?? ('Emp ' . $pin)),
                employeeId: $employee->id,
                employeeCode: $employee->employee_id,
            );

            // 1. Mock provider completes synchronously against simulated hardware memory
            if ($provider::definition()->communication === ProviderDefinition::SIMULATED) {
                $res = $provider->enrollEmployee($userDto);
                if (!$res->isCompleted()) {
                    $enrollment->forceFill(['enrollment_status' => EnrollmentStatus::Failed, 'last_error' => $res->message])->save();
                    $enrollment->credentials()->where('modality', $modality->value)->whereIn('slot_index', $slots)->update(['status' => EnrollmentStatus::Failed->value]);

                    throw new DeviceActionException($res->message);
                }

                $now = now();
                foreach ($slots as $slot) {
                    $enrollment->credentials()
                        ->where('modality', $modality->value)
                        ->where('slot_index', $slot)
                        ->update([
                            'status' => EnrollmentStatus::Enrolled->value,
                            'device_credential_ref' => "mock-{$modality->value}-{$pin}-{$slot}",
                            'quality_score' => 95,
                            'enrolled_at' => $now,
                            'last_synced_at' => $now,
                        ]);
                }

                $enrollment->forceFill([
                    'enrollment_status' => EnrollmentStatus::Enrolled,
                    'enrolled_at' => $enrollment->enrolled_at ?? $now,
                    'last_synced_at' => $now,
                ])->save();

                $enrollment->recomputeSummary();
                $this->reconcileEmployeeFlags($employee);

                $this->activity->event($device, EventLevel::Info, $isReEnroll ? 'employee_reenrolled' : 'employee_enrolled', sprintf(
                    '%s (%s) %s for %s (%d slot(s)) on simulated device.',
                    $userDto->name,
                    $pin,
                    $isReEnroll ? 're-enrolled' : 'enrolled',
                    $modality->label(),
                    count($slots)
                ));

                app(BiometricAuditLogger::class)->log(
                    category: BiometricAuditLogger::CAT_ENROLLMENT,
                    action: $isReEnroll ? 'enrollment.re_enrolled' : 'enrollment.started',
                    description: sprintf('%s for %s (PIN %s) on %s (%s).', $isReEnroll ? 'Re-enrollment completed' : 'Enrollment completed', $userDto->name, $pin, $device->name, $modality->label()),
                    companyId: (int) $device->company_id,
                    targetType: 'BiometricEnrollment',
                    targetId: $enrollment->id,
                    targetLabel: $enrollment->enrollment_reference,
                    deviceId: $device->id,
                    employeeId: $employee->id,
                    after: ['device_user_id' => $pin, 'modality' => $modality->value, 'slots' => $slots, 'status' => $enrollment->enrollment_status->value],
                    actor: $actor,
                );

                return $enrollment->fresh(['device', 'credentials']);
            }

            // 2. Real hardware: register user profile on device first
            $userCmd = $this->commands->queue($device, CommandType::UserUpsert, $userDto->toPayload() + [
                'enrollment_id' => $enrollment->id,
                'enrollment_reference' => $enrollment->enrollment_reference,
            ], null, $employee->id, 20, $actor);

            $lastCommandId = $userCmd->id;

            // 3. Devices supporting remote capture (Own Brand native or Local Agent bridge)
            //    receive explicit vendor-neutral StartEnrollment commands per slot.
            //    Devices like ZKTeco ADMS enter `awaiting_capture` for physical terminal scan.
            if ($this->supportsRemoteCapture($device)) {
                foreach ($slots as $slot) {
                    $cmd = $this->commands->queue($device, CommandType::StartEnrollment, [
                        'enrollment_id' => $enrollment->id,
                        'enrollment_reference' => $enrollment->enrollment_reference,
                        'pin' => $pin,
                        'name' => $userDto->name,
                        'modality' => $modality->value,
                        'slot_index' => $slot,
                        'slot_label' => $this->slotLabel($modality, $slot),
                        'replace' => $isReEnroll,
                    ], null, $employee->id, 25, $actor);
                    $lastCommandId = $cmd->id;
                }
            } else {
                $enrollment->credentials()
                    ->where('modality', $modality->value)
                    ->whereIn('slot_index', $slots)
                    ->update(['status' => EnrollmentStatus::AwaitingCapture->value]);
                $enrollment->enrollment_status = EnrollmentStatus::AwaitingCapture;
            }

            $enrollment->forceFill(['last_command_id' => $lastCommandId])->save();
            $this->reconcileEmployeeFlags($employee);

            $this->activity->event($device, EventLevel::Info, $isReEnroll ? 'enrollment_restarted' : 'enrollment_started', sprintf(
                '%s started for %s (PIN %s, ref %s, %s, %d slot(s)).',
                $isReEnroll ? 'Re-enrollment' : 'Enrollment',
                $userDto->name,
                $pin,
                $enrollment->enrollment_reference,
                $modality->label(),
                count($slots)
            ), ['employee_id' => $employee->id, 'enrollment_id' => $enrollment->id]);

            app(BiometricAuditLogger::class)->log(
                category: BiometricAuditLogger::CAT_ENROLLMENT,
                action: $isReEnroll ? 'enrollment.re_enrolled' : 'enrollment.started',
                description: sprintf('%s for %s (PIN %s) on %s (%s).', $isReEnroll ? 'Re-enrollment started' : 'Enrollment started', $userDto->name, $pin, $device->name, $modality->label()),
                companyId: (int) $device->company_id,
                targetType: 'BiometricEnrollment',
                targetId: $enrollment->id,
                targetLabel: $enrollment->enrollment_reference,
                deviceId: $device->id,
                employeeId: $employee->id,
                after: ['device_user_id' => $pin, 'modality' => $modality->value, 'slots' => $slots, 'status' => $enrollment->enrollment_status->value],
                actor: $actor,
            );

            return $enrollment->fresh(['device', 'credentials']);
        });
    }

    /**
     * Re-enroll an employee on an existing device mapping.
     *
     * @param int[] $fingerSlots
     */
    public function reEnroll(
        BiometricEnrollment $enrollment,
        ?BiometricModality $modality = null,
        array $fingerSlots = [],
        ?User $actor = null,
    ): BiometricEnrollment {
        $enrollment->loadMissing(['employee.user', 'device', 'credentials']);

        $targetModality = $modality;
        if (!$targetModality) {
            $firstCred = $enrollment->credentials->first();
            $targetModality = $firstCred?->modality ?? BiometricModality::forDeviceType($enrollment->device->device_type)[0];
        }

        if (empty($fingerSlots) && $targetModality === BiometricModality::Fingerprint) {
            $existingSlots = $enrollment->credentials
                ->where('modality', BiometricModality::Fingerprint)
                ->pluck('slot_index')
                ->all();
            $fingerSlots = $existingSlots ?: [FingerSlot::RightIndex->value];
        }

        // Purge stale encrypted transfer templates for the re-enrolled slots.
        $this->vault->purgeForEmployee(
            $enrollment->employee,
            $enrollment->device_id,
            $targetModality,
            $targetModality === BiometricModality::Fingerprint ? $fingerSlots : [0]
        );

        return $this->startEnrollment(
            $enrollment->employee,
            $enrollment->device,
            $targetModality,
            $fingerSlots,
            $enrollment->device_user_id,
            $actor,
            isReEnroll: true
        );
    }

    /**
     * Remove an employee's biometric registration from one device.
     */
    public function removeEnrollment(BiometricEnrollment $enrollment, ?User $actor = null): BiometricEnrollment
    {
        $enrollment->loadMissing(['employee', 'device']);
        $device = $enrollment->device;
        $employee = $enrollment->employee;

        // Always purge any encrypted transfer templates originating from this device.
        $this->vault->purgeForEmployee($employee, $device->id);

        if (!$device->is_active) {
            $this->finalizeRemoval($enrollment, $employee);

            return $enrollment->fresh(['device', 'credentials']);
        }

        $provider = $this->factory->make($device);

        if ($provider::definition()->communication === ProviderDefinition::SIMULATED) {
            $provider->deleteEmployee($enrollment->device_user_id);
            $this->finalizeRemoval($enrollment, $employee);
            app(BiometricAuditLogger::class)->log(
                category: BiometricAuditLogger::CAT_ENROLLMENT,
                action: 'enrollment.removed',
                description: "Removed biometric enrollment {$enrollment->enrollment_reference} (PIN {$enrollment->device_user_id}) from '{$device->name}'.",
                companyId: (int) $device->company_id,
                targetType: 'BiometricEnrollment',
                targetId: $enrollment->id,
                targetLabel: $enrollment->enrollment_reference,
                deviceId: $device->id,
                employeeId: $employee->id,
                actor: $actor,
            );

            return $enrollment->fresh(['device', 'credentials']);
        }

        if ($provider->supports(Operation::DeleteEmployee) || $device->isManagedByAgent()) {
            $cmd = $this->commands->queue($device, CommandType::UserDelete, [
                'pin' => $enrollment->device_user_id,
                'enrollment_id' => $enrollment->id,
                'enrollment_reference' => $enrollment->enrollment_reference,
            ], null, $employee->id, 20, $actor);

            $enrollment->forceFill([
                'enrollment_status' => EnrollmentStatus::Removing,
                'last_command_id' => $cmd->id,
                'last_error' => null,
            ])->save();

            $this->activity->event($device, EventLevel::Warning, 'enrollment_removal_queued', sprintf(
                'Removal of PIN %s (ref %s) queued for device.',
                $enrollment->device_user_id,
                $enrollment->enrollment_reference
            ), ['employee_id' => $employee->id, 'enrollment_id' => $enrollment->id]);
        } else {
            $this->finalizeRemoval($enrollment, $employee);
        }

        app(BiometricAuditLogger::class)->log(
            category: BiometricAuditLogger::CAT_ENROLLMENT,
            action: 'enrollment.removed',
            description: "Initiated removal of enrollment {$enrollment->enrollment_reference} (PIN {$enrollment->device_user_id}) on '{$device->name}'.",
            companyId: (int) $device->company_id,
            targetType: 'BiometricEnrollment',
            targetId: $enrollment->id,
            targetLabel: $enrollment->enrollment_reference,
            deviceId: $device->id,
            employeeId: $employee->id,
            actor: $actor,
        );

        return $enrollment->fresh(['device', 'credentials']);
    }

    /**
     * Remove biometric registrations for an employee across ALL devices (e.g. offboarding).
     */
    public function removeAllForEmployee(Employee $employee, ?User $actor = null): int
    {
        $enrollments = BiometricEnrollment::with(['device', 'employee'])
            ->where('employee_id', $employee->id)
            ->where('enrollment_status', '!=', EnrollmentStatus::Removed->value)
            ->get();

        foreach ($enrollments as $enrollment) {
            $this->removeEnrollment($enrollment, $actor);
        }

        // Guarantee zero residual encrypted templates remain for this employee.
        $this->vault->purgeForEmployee($employee);
        $this->reconcileEmployeeFlags($employee);

        return $enrollments->count();
    }

    /**
     * Synchronize an employee's biometric registration across multiple authorized devices.
     *
     * @param int[] $targetDeviceIds
     * @return array{synced: int, templates_queued: int, skipped: array<int, array{device_id: int, reason: string}>}
     */
    public function syncAcrossDevices(
        Employee $employee,
        array $targetDeviceIds,
        ?int $sourceDeviceId = null,
        ?User $actor = null,
    ): array {
        $employee->loadMissing('user:id,name');
        $companyId = (int) Tenant::companyId($actor ?: $employee->creator);

        $devices = BiometricDevice::where('company_id', $companyId)
            ->whereIn('id', array_unique(array_map('intval', $targetDeviceIds)))
            ->get();

        if ($devices->isEmpty()) {
            throw new DeviceActionException(__('Select at least one authorized biometric device.'));
        }

        $pin = $this->ensureDeviceUserId($employee, $companyId);
        $userDto = new DeviceUser(
            pin: $pin,
            name: (string) ($employee->user?->name ?? ('Emp ' . $pin)),
            employeeId: $employee->id,
            employeeCode: $employee->employee_id,
        );

        // Optional encrypted templates (only when transfer policy allows it!)
        $templates = $this->vault->isTransferAllowed()
            ? BiometricEncryptedTemplate::where('company_id', $companyId)
                ->where('employee_id', $employee->id)
                ->when($sourceDeviceId, fn ($q) => $q->where('source_device_id', $sourceDeviceId))
                ->get()
            : collect();

        $synced = 0;
        $templatesQueued = 0;
        $skipped = [];

        foreach ($devices as $device) {
            if (!$device->is_active) {
                $skipped[] = ['device_id' => $device->id, 'reason' => __('Device is inactive.')];
                continue;
            }

            $provider = $this->factory->make($device);
            if (!$provider->supports(Operation::EnrollEmployee) && !$device->isManagedByAgent()) {
                $skipped[] = ['device_id' => $device->id, 'reason' => $provider->unavailableReason(Operation::EnrollEmployee) ?? __('Device does not support enrollment sync.')];
                continue;
            }

            DB::transaction(function () use ($employee, $device, $pin, $userDto, $sourceDeviceId, $actor, $provider, $templates, &$synced, &$templatesQueued) {
                $enrollment = BiometricEnrollment::firstOrNew([
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                ]);

                if (!$enrollment->exists) {
                    $enrollment->company_id = $device->company_id;
                    $enrollment->enrollment_reference = $this->generateReference();
                    $enrollment->enrolled_by = $actor?->id;
                }

                $enrollment->device_user_id = $pin;
                $enrollment->source_device_id = $sourceDeviceId ?: $enrollment->source_device_id;
                $enrollment->last_error = null;

                if ($provider::definition()->communication === ProviderDefinition::SIMULATED) {
                    $res = $provider->enrollEmployee($userDto);
                    if (!$res->isCompleted()) {
                        $enrollment->enrollment_status = EnrollmentStatus::Failed;
                        $enrollment->last_error = $res->message;
                        $enrollment->save();

                        return;
                    }

                    $now = now();
                    $enrollment->enrollment_status = EnrollmentStatus::Enrolled;
                    $enrollment->enrolled_at = $enrollment->enrolled_at ?? $now;
                    $enrollment->last_synced_at = $now;
                    $enrollment->save();

                    $this->replicateCredentialSlots($employee, $enrollment, $sourceDeviceId, EnrollmentStatus::Enrolled, $now);
                    $enrollment->recomputeSummary();
                    $synced++;

                    return;
                }

                $enrollment->enrollment_status = EnrollmentStatus::Syncing;
                $enrollment->save();

                $cmd = $this->commands->queue($device, CommandType::UserUpsert, $userDto->toPayload() + [
                    'enrollment_id' => $enrollment->id,
                    'enrollment_reference' => $enrollment->enrollment_reference,
                ], null, $employee->id, 30, $actor);

                $lastCmdId = $cmd->id;

                // Queue encrypted template transfer commands ONLY when enabled and supported
                $supportedModalities = array_map(fn ($m) => $m->value, BiometricModality::forDeviceType($device->device_type));
                foreach ($templates as $tpl) {
                    if (!in_array($tpl->modality->value, $supportedModalities, true)) {
                        continue;
                    }
                    // Store ONLY template_id in command payload — NEVER plaintext template bytes!
                    $tCmd = $this->commands->queue($device, CommandType::TemplatePush, [
                        'enrollment_id' => $enrollment->id,
                        'enrollment_reference' => $enrollment->enrollment_reference,
                        'pin' => $pin,
                        'modality' => $tpl->modality->value,
                        'slot_index' => $tpl->slot_index,
                        'template_format' => $tpl->template_format,
                        'template_id' => $tpl->id,
                    ], null, $employee->id, 35, $actor);
                    $lastCmdId = $tCmd->id;
                    $templatesQueued++;
                }

                $this->replicateCredentialSlots(
                    $employee,
                    $enrollment,
                    $sourceDeviceId,
                    $templates->isNotEmpty() ? EnrollmentStatus::Syncing : EnrollmentStatus::AwaitingCapture,
                    null
                );

                $enrollment->forceFill(['last_command_id' => $lastCmdId])->save();
                $synced++;
            });
        }

        $this->reconcileEmployeeFlags($employee);

        app(BiometricAuditLogger::class)->log(
            category: BiometricAuditLogger::CAT_ENROLLMENT,
            action: 'enrollment.synced_devices',
            description: "Synchronized employee enrollment across {$synced} authorized device(s) ({$templatesQueued} encrypted template(s) queued, {$skipped} skipped).",
            companyId: (int) $companyId,
            targetType: 'Employee',
            targetId: $employee->id,
            targetLabel: $employee->user?->name ?? $employee->employee_id,
            employeeId: $employee->id,
            metadata: ['synced' => $synced, 'templates_queued' => $templatesQueued, 'skipped' => $skipped, 'device_ids' => $targetDeviceIds],
            actor: $actor,
        );

        return [
            'synced' => $synced,
            'templates_queued' => $templatesQueued,
            'skipped' => $skipped,
        ];
    }

    /**
     * Confirm enrollment manually when a terminal doesn't push enrollment acks
     * (e.g. admin completed physical finger/face scan directly on a ZKTeco device).
     */
    public function confirmCapturedOnDevice(BiometricEnrollment $enrollment, ?User $actor = null): BiometricEnrollment
    {
        $now = now();
        $enrollment->credentials()
            ->whereIn('status', [EnrollmentStatus::Pending->value, EnrollmentStatus::AwaitingCapture->value, EnrollmentStatus::Syncing->value])
            ->update([
                'status' => EnrollmentStatus::Enrolled->value,
                'enrolled_at' => $now,
                'last_synced_at' => $now,
            ]);

        $enrollment->forceFill([
            'enrollment_status' => EnrollmentStatus::Enrolled,
            'enrolled_at' => $enrollment->enrolled_at ?? $now,
            'last_synced_at' => $now,
            'last_error' => null,
        ])->save();

        $enrollment->recomputeSummary();
        $this->reconcileEmployeeFlags($enrollment->employee);

        $this->activity->event($enrollment->device, EventLevel::Info, 'enrollment_confirmed', sprintf(
            'Enrollment %s (PIN %s) confirmed as captured on device by %s.',
            $enrollment->enrollment_reference,
            $enrollment->device_user_id,
            $actor?->name ?? 'admin'
        ));

        app(BiometricAuditLogger::class)->log(
            category: BiometricAuditLogger::CAT_ENROLLMENT,
            action: 'enrollment.confirmed',
            description: "Confirmed biometric capture for enrollment {$enrollment->enrollment_reference} (PIN {$enrollment->device_user_id}) on '{$enrollment->device?->name}'.",
            companyId: (int) $enrollment->company_id,
            targetType: 'BiometricEnrollment',
            targetId: $enrollment->id,
            targetLabel: $enrollment->enrollment_reference,
            deviceId: $enrollment->device_id,
            employeeId: $enrollment->employee_id,
            actor: $actor,
        );

        return $enrollment->fresh(['device', 'credentials']);
    }

    /**
     * Handle device or Local Agent acknowledgement for enrollment-related commands.
     */
    public function handleCommandAck(BiometricCommand $command, bool $success, array $result = []): void
    {
        $payload = $command->payload ?? [];
        $enrollmentId = $payload['enrollment_id'] ?? null;

        $enrollment = $enrollmentId
            ? BiometricEnrollment::with(['employee', 'device'])->find($enrollmentId)
            : ($command->employee_id
                ? BiometricEnrollment::with(['employee', 'device'])
                    ->where('employee_id', $command->employee_id)
                    ->where('device_id', $command->device_id)
                    ->first()
                : null);

        if (!$enrollment) {
            return;
        }

        $now = now();

        if (!$success) {
            $enrollment->forceFill([
                'enrollment_status' => EnrollmentStatus::Failed,
                'last_error' => $command->error ?: 'Enrollment command failed on device.',
            ])->save();

            if ($command->type === CommandType::StartEnrollment && isset($payload['modality'], $payload['slot_index'])) {
                $enrollment->credentials()
                    ->where('modality', $payload['modality'])
                    ->where('slot_index', (int) $payload['slot_index'])
                    ->update(['status' => EnrollmentStatus::Failed->value]);
            }

            return;
        }

        switch ($command->type) {
            case CommandType::UserUpsert:
                $nextStatus = $enrollment->enrollment_status === EnrollmentStatus::Enrolled
                    ? EnrollmentStatus::Enrolled
                    : ($this->supportsRemoteCapture($enrollment->device) ? $enrollment->enrollment_status : EnrollmentStatus::AwaitingCapture);

                $enrollment->forceFill([
                    'enrollment_status' => $nextStatus === EnrollmentStatus::Syncing ? EnrollmentStatus::Enrolled : $nextStatus,
                    'last_synced_at' => $now,
                    'last_error' => null,
                ])->save();
                break;

            case CommandType::StartEnrollment:
                $modality = BiometricModality::tryFrom((string) ($payload['modality'] ?? 'fingerprint')) ?? BiometricModality::Fingerprint;
                $slot = (int) ($payload['slot_index'] ?? 0);
                $captured = (bool) ($result['captured'] ?? true);

                $credStatus = $captured ? EnrollmentStatus::Enrolled : EnrollmentStatus::AwaitingCapture;

                $enrollment->credentials()
                    ->where('modality', $modality->value)
                    ->where('slot_index', $slot)
                    ->update([
                        'status' => $credStatus->value,
                        'device_credential_ref' => !empty($result['credential_ref']) ? mb_substr((string) $result['credential_ref'], 0, 120) : null,
                        'quality_score' => isset($result['quality_score']) ? min(100, max(0, (int) $result['quality_score'])) : null,
                        'enrolled_at' => $captured ? $now : null,
                        'last_synced_at' => $now,
                    ]);

                // Store mathematical template ONLY if transfer policy allows it (raw images always rejected!).
                if (!empty($result['template_data']) && !empty($result['template_format']) && $this->vault->isTransferAllowed()) {
                    $this->vault->store(
                        $enrollment->employee,
                        $enrollment->device,
                        $modality,
                        $slot,
                        (string) $result['template_format'],
                        (string) $result['template_data']
                    );
                }

                $enrollment->forceFill([
                    'enrollment_status' => $captured ? EnrollmentStatus::Enrolled : EnrollmentStatus::AwaitingCapture,
                    'enrolled_at' => $captured ? ($enrollment->enrolled_at ?? $now) : $enrollment->enrolled_at,
                    'last_synced_at' => $now,
                    'last_error' => null,
                ])->save();

                $enrollment->recomputeSummary();
                $this->reconcileEmployeeFlags($enrollment->employee);
                break;

            case CommandType::TemplatePush:
                if (isset($payload['modality'], $payload['slot_index'])) {
                    $enrollment->credentials()
                        ->where('modality', $payload['modality'])
                        ->where('slot_index', (int) $payload['slot_index'])
                        ->update([
                            'status' => EnrollmentStatus::Enrolled->value,
                            'enrolled_at' => $now,
                            'last_synced_at' => $now,
                        ]);
                }
                $enrollment->forceFill([
                    'enrollment_status' => EnrollmentStatus::Enrolled,
                    'enrolled_at' => $enrollment->enrolled_at ?? $now,
                    'last_synced_at' => $now,
                    'last_error' => null,
                ])->save();
                $enrollment->recomputeSummary();
                $this->reconcileEmployeeFlags($enrollment->employee);
                break;

            case CommandType::UserDelete:
                $this->finalizeRemoval($enrollment, $enrollment->employee);
                break;

            default:
                break;
        }
    }

    /**
     * Keep legacy summary columns on `employees` synchronized with active enrollments.
     */
    public function reconcileEmployeeFlags(Employee $employee): void
    {
        $active = BiometricEnrollment::with('device:id,serial_number')
            ->where('employee_id', $employee->id)
            ->whereNotIn('enrollment_status', [EnrollmentStatus::Removed->value, EnrollmentStatus::Failed->value])
            ->orderByDesc('enrolled_at')
            ->orderByDesc('id')
            ->get();

        if ($active->isEmpty()) {
            $employee->forceFill([
                'is_biometric_enrolled' => false,
                'biometric_enrollment_status' => 'not_enrolled',
                'biometric_device_sn' => null,
            ])->saveQuietly();

            return;
        }

        $enrolled = $active->firstWhere('enrollment_status', EnrollmentStatus::Enrolled);
        $primary = $enrolled ?? $active->first();

        $employee->forceFill([
            'is_biometric_enrolled' => $enrolled !== null,
            'biometric_enrollment_status' => $primary->enrollment_status->value,
            'biometric_device_sn' => $primary->device?->serial_number,
            'biometric_enrolled_at' => $enrolled?->enrolled_at ?? $employee->biometric_enrolled_at,
        ])->saveQuietly();
    }

    private function finalizeRemoval(BiometricEnrollment $enrollment, Employee $employee): void
    {
        $enrollment->credentials()->update(['status' => EnrollmentStatus::Removed->value]);
        $enrollment->forceFill([
            'enrollment_status' => EnrollmentStatus::Removed,
            'enrolled_modalities' => [],
            'fingerprint_count' => 0,
            'face_enrolled' => false,
            'last_error' => null,
        ])->save();

        $this->reconcileEmployeeFlags($employee);
    }

    private function assertDeviceReadyForEnrollment(BiometricDevice $device, BiometricModality $modality): void
    {
        if (!$device->is_active) {
            throw new DeviceActionException(__('The selected biometric device is inactive. Activate it before enrolling employees.'));
        }

        $provider = $this->factory->make($device);
        $isSimulated = $provider::definition()->communication === ProviderDefinition::SIMULATED;

        if (!$isSimulated && !$device->isNetworked() && !$device->isManagedByAgent()) {
            throw new DeviceActionException(__('USB devices require an assigned Local Communication Agent for enrollment.'));
        }

        if (!$provider->supports(Operation::EnrollEmployee) && !$device->isManagedByAgent()) {
            throw new DeviceActionException($provider->unavailableReason(Operation::EnrollEmployee) ?? __('This device does not support employee enrollment.'));
        }

        $supported = BiometricModality::forDeviceType($device->device_type);
        if (!in_array($modality, $supported, true)) {
            throw new DeviceActionException(__(':device (:type) does not support :modality enrollment.', [
                'device' => $device->name,
                'type' => $device->device_type?->label() ?? 'unknown',
                'modality' => $modality->label(),
            ]));
        }
    }

    private function supportsRemoteCapture(BiometricDevice $device): bool
    {
        return $device->isManagedByAgent() || in_array($device->protocol, ['native', 'local_agent'], true);
    }

    /** @return int[] */
    private function normalizeSlots(BiometricModality $modality, array $fingerSlots): array
    {
        if ($modality !== BiometricModality::Fingerprint) {
            return [0];
        }

        $slots = array_values(array_unique(array_map('intval', $fingerSlots ?: [FingerSlot::RightIndex->value])));
        foreach ($slots as $slot) {
            if ($slot < 0 || $slot > 9) {
                throw new DeviceActionException(__('Invalid finger slot index :slot. Must be between 0 and 9.', ['slot' => $slot]));
            }
        }
        sort($slots);

        return $slots;
    }

    private function slotLabel(BiometricModality $modality, int $slot): string
    {
        if ($modality === BiometricModality::Fingerprint) {
            return FingerSlot::tryFrom($slot)?->label() ?? "Finger {$slot}";
        }

        return $modality->label();
    }

    private function generateReference(): string
    {
        do {
            $ref = 'ENR-' . Str::upper(Str::random(10));
        } while (BiometricEnrollment::where('enrollment_reference', $ref)->exists());

        return $ref;
    }

    private function replicateCredentialSlots(
        Employee $employee,
        BiometricEnrollment $target,
        ?int $sourceDeviceId,
        EnrollmentStatus $status,
        ?\Carbon\CarbonInterface $syncedAt,
    ): void {
        $sourceCreds = BiometricEnrollmentCredential::where('employee_id', $employee->id)
            ->when($sourceDeviceId, fn ($q) => $q->where('device_id', $sourceDeviceId))
            ->where('status', EnrollmentStatus::Enrolled->value)
            ->get();

        $supported = array_map(fn ($m) => $m->value, BiometricModality::forDeviceType($target->device->device_type));

        foreach ($sourceCreds as $cred) {
            if (!in_array($cred->modality->value, $supported, true)) {
                continue;
            }
            BiometricEnrollmentCredential::updateOrCreate(
                [
                    'enrollment_id' => $target->id,
                    'modality' => $cred->modality->value,
                    'slot_index' => $cred->slot_index,
                ],
                [
                    'company_id' => $target->company_id,
                    'employee_id' => $employee->id,
                    'device_id' => $target->device_id,
                    'slot_label' => $cred->slot_label,
                    'status' => $status,
                    'enrolled_at' => $status === EnrollmentStatus::Enrolled ? ($syncedAt ?? now()) : null,
                    'last_synced_at' => $syncedAt,
                ]
            );
        }
    }
}
