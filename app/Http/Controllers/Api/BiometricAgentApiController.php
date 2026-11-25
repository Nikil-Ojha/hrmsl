<?php

namespace App\Http\Controllers\Api;

use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\ConnectionStatus;
use App\Biometric\Enums\EnrollmentStatus;
use App\Biometric\Enums\EventLevel;
use App\Biometric\Services\AgentAuthorizationService;
use App\Biometric\Services\AgentCredentialManager;
use App\Biometric\Services\AgentLogIngestionService;
use App\Biometric\Services\BiometricTemplateVault;
use App\Biometric\Services\CommandQueue;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Services\PunchSynchronizationService;
use App\Biometric\Support\DeviceActionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Biometric\AgentAttendanceSyncRequest;
use App\Http\Requests\Api\Biometric\AgentCommandAckRequest;
use App\Http\Requests\Api\Biometric\AgentDeviceStatusRequest;
use App\Http\Requests\Api\Biometric\AgentEnrollmentSyncRequest;
use App\Http\Requests\Api\Biometric\AgentHeartbeatRequest;
use App\Http\Requests\Api\Biometric\AgentRegisterRequest;
use App\Models\BiometricAgent;
use App\Models\BiometricAgentDiscovery;
use App\Models\BiometricDevice;
use App\Models\BiometricDeviceUser;
use App\Models\BiometricEmployeeMapping;
use App\Models\BiometricEnrollment;
use App\Models\BiometricEnrollmentCredential;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class BiometricAgentApiController extends Controller
{
    public function __construct(
        private readonly AgentCredentialManager $credentials,
        private readonly AgentAuthorizationService $authz,
        private readonly CommandQueue $commands,
        private readonly DeviceActivityRecorder $activity,
        private readonly EmployeeSelector $employees,
        private readonly AgentLogIngestionService $ingestion,
        private readonly PunchSynchronizationService $sync,
        private readonly BiometricTemplateVault $vault,
    ) {
    }

    /**
     * 1. POST /api/biometric/agent/register (and /v1/provision)
     * Exchange a one-time provisioning token for permanent per-agent Bearer + HMAC credentials.
     */
    public function provision(AgentRegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $result = $this->credentials->provision(
            $validated['agent_identifier'],
            $validated['provisioning_token'],
            [
                'agent_version' => $validated['agent_version'] ?? null,
                'os_info' => $validated['os_info'] ?? null,
                'hostname' => $validated['hostname'] ?? null,
                'capabilities' => $validated['capabilities'] ?? null,
                'ip' => $request->ip(),
            ]
        );

        if (!$result) {
            $request->attributes->set('biometric_audit_outcome', 'auth_failed');
            $request->attributes->set('biometric_audit_summary', 'Invalid, expired or already used provisioning token.');

            return response()->json([
                'status' => false,
                'code' => 'invalid_provisioning_token',
                'message' => 'Invalid, expired or already used provisioning token.',
            ], 401);
        }

        /** @var BiometricAgent $agent */
        $agent = $result['agent'];
        $request->attributes->set('biometric_audit_agent', $agent);
        $request->attributes->set('biometric_audit_summary', 'Agent provisioned and runtime credentials issued.');

        return response()->json([
            'status' => true,
            'message' => 'Agent registered and provisioned successfully.',
            'data' => [
                'agent_identifier' => $agent->agent_identifier,
                'agent_name' => $agent->name,
                'access_token' => $result['access_token'],
                'hmac_secret' => $result['hmac_secret'],
                'settings' => $agent->mergedSettings(),
            ],
        ], 200);
    }

    /**
     * POST /api/biometric/agent/token/rotate (authenticated)
     * Rotate the authenticated agent's Bearer token & HMAC secret, immediately
     * invalidating the previous credentials.
     */
    public function rotateToken(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $rotated = $this->credentials->rotateCredentials($agent);

        $request->attributes->set('biometric_audit_summary', 'Agent rotated runtime access token and HMAC secret.');

        return response()->json([
            'status' => true,
            'message' => 'Agent credentials rotated successfully.',
            'data' => [
                'agent_identifier' => $agent->agent_identifier,
                'access_token' => $rotated['access_token'],
                'hmac_secret' => $rotated['hmac_secret'],
                'rotated_at' => $agent->credentials_rotated_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * GET /api/biometric/agent/v1/config
     * Pull the agent's settings and all assigned active biometric devices.
     */
    public function config(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $devices = BiometricDevice::where('agent_id', $agent->id)
            ->where('company_id', $agent->company_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->map(fn (BiometricDevice $d) => $this->serializeDeviceConfig($d))
            ->values()
            ->all();

        $payload = [
            'agent_identifier' => $agent->agent_identifier,
            'agent_name' => $agent->name,
            'branch_id' => $agent->branch_id,
            'server_time' => now()->toIso8601String(),
            'settings' => $agent->mergedSettings(),
            'devices' => $devices,
        ];

        $version = hash('sha256', (string) json_encode($payload));

        return response()->json([
            'status' => true,
            'config_version' => $version,
            'data' => $payload,
        ]);
    }

    /**
     * 8. GET /api/biometric/devices/{id}/configuration
     * Return full configuration and authorized employee PIN list for ONE device
     * strictly authorized to this agent & company.
     */
    public function deviceConfiguration(Request $request, int $id): JsonResponse
    {
        $agent = $this->agent($request);
        $auth = $this->authz->authorizeDevice($agent, $id, null, false);

        if (!$auth['ok']) {
            $request->attributes->set('biometric_audit_outcome', 'forbidden_device');
            $request->attributes->set('biometric_audit_summary', "Unauthorized configuration request for device #{$id}.");

            return response()->json([
                'status' => false,
                'code' => $auth['code'],
                'message' => $auth['message'],
            ], $auth['status'] ?? 403);
        }

        /** @var BiometricDevice $device */
        $device = $auth['device'];
        $request->attributes->set('biometric_audit_device_id', $device->id);

        $eligibleEmployees = $this->employees->eligible($device)
            ->map(fn ($e) => [
                'employee_code' => $e->employee_id,
                'device_user_id' => (string) $e->biometric_emp_id,
                'name' => $e->user?->name ?? ('Emp ' . $e->biometric_emp_id),
                'updated_at' => $e->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        $payload = [
            'device' => $this->serializeDeviceConfig($device),
            'is_active' => (bool) $device->is_active,
            'agent_settings' => $agent->mergedSettings(),
            'template_storage_mode' => $this->vault->storageMode(),
            'eligible_employees_count' => count($eligibleEmployees),
            'eligible_employees' => $eligibleEmployees,
            'server_time' => now()->toIso8601String(),
        ];

        $version = hash('sha256', (string) json_encode($payload));

        return response()->json([
            'status' => true,
            'config_version' => $version,
            'data' => $payload,
        ]);
    }

    /**
     * 2. POST /api/biometric/agent/heartbeat (and /v1/heartbeat)
     * Receive agent health telemetry and optional per-device connectivity snapshots.
     */
    public function heartbeat(AgentHeartbeatRequest $request): JsonResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validated();

        $agent->forceFill([
            'connection_status' => ConnectionStatus::Online,
            'last_seen_at' => now(),
            'last_seen_ip' => $request->ip(),
            'agent_version' => $validated['agent_version'] ?? $agent->agent_version,
            'os_info' => $validated['os_info'] ?? $agent->os_info,
            'hostname' => $validated['hostname'] ?? $agent->hostname,
            'local_queue_depth' => $validated['local_queue_depth'] ?? $agent->local_queue_depth,
            'local_error_count' => $validated['local_error_count'] ?? $agent->local_error_count,
            'capabilities' => $validated['capabilities'] ?? $agent->capabilities,
        ])->saveQuietly();

        $updatedDevices = 0;
        $rejectedDevices = 0;

        if (!empty($validated['devices'])) {
            $res = $this->applyDeviceStatusSnapshots($agent, $validated['devices'], (string) $request->ip());
            $updatedDevices = $res['updated'];
            $rejectedDevices = $res['rejected'];
        }

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'devices_updated' => $updatedDevices,
            'devices_rejected' => $rejectedDevices,
            'pending_commands' => $agent->devices()->where('is_active', true)
                ->join('biometric_commands', 'biometric_commands.device_id', '=', 'biometric_devices.id')
                ->where('biometric_commands.status', 'queued')
                ->count(),
        ]);
    }

    /**
     * 6. POST /api/biometric/devices/status
     * Dedicated endpoint to report live device status/health telemetry with
     * strict per-device authorization.
     */
    public function updateDeviceStatus(AgentDeviceStatusRequest $request): JsonResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validated();

        $agent->forceFill([
            'connection_status' => ConnectionStatus::Online,
            'last_seen_at' => now(),
            'last_seen_ip' => $request->ip(),
        ])->saveQuietly();

        $res = $this->applyDeviceStatusSnapshots($agent, $validated['devices'], (string) $request->ip());

        if ($res['updated'] === 0 && $res['rejected'] > 0) {
            $request->attributes->set('biometric_audit_outcome', 'forbidden_device');
            $request->attributes->set('biometric_audit_summary', 'All device status updates rejected: unauthorized device(s).');

            return response()->json([
                'status' => false,
                'code' => 'unauthorized_device',
                'message' => 'None of the specified devices are authorized for this agent.',
                'data' => $res,
            ], 403);
        }

        $request->attributes->set('biometric_audit_meta', [
            'updated' => $res['updated'],
            'rejected' => $res['rejected'],
        ]);

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'data' => $res,
        ]);
    }

    /**
     * POST /api/biometric/agent/v1/discovery
     * Store devices discovered on the local office network / USB bus.
     */
    public function discovery(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'devices' => ['required', 'array', 'max:250'],
            'devices.*.fingerprint' => ['required', 'string', 'max:120'],
            'devices.*.connection_type' => ['required', 'in:lan,wifi,usb'],
            'devices.*.ip_address' => ['nullable', 'ip'],
            'devices.*.port' => ['nullable', 'integer', 'between:1,65535'],
            'devices.*.usb_path' => ['nullable', 'string', 'max:255'],
            'devices.*.serial_number' => ['nullable', 'string', 'max:100'],
            'devices.*.manufacturer_hint' => ['nullable', 'string', 'max:100'],
            'devices.*.model_hint' => ['nullable', 'string', 'max:100'],
            'devices.*.protocol_hint' => ['nullable', 'string', 'max:50'],
            'devices.*.metadata' => ['nullable', 'array'],
        ]);

        $existingDevices = BiometricDevice::where('company_id', $agent->company_id)->get();
        $recorded = 0;

        foreach ($validated['devices'] as $item) {
            $matched = $existingDevices->first(function (BiometricDevice $d) use ($item) {
                if (!empty($item['serial_number']) && strcasecmp($d->serial_number, $item['serial_number']) === 0) {
                    return true;
                }
                if (!empty($item['ip_address']) && $d->ip_address === $item['ip_address'] && (int) $d->port === (int) ($item['port'] ?? 0)) {
                    return true;
                }

                return false;
            });

            $discovery = BiometricAgentDiscovery::firstOrNew([
                'agent_id' => $agent->id,
                'fingerprint' => $item['fingerprint'],
            ]);

            $discovery->fill([
                'company_id' => $agent->company_id,
                'connection_type' => $item['connection_type'],
                'ip_address' => $item['ip_address'] ?? null,
                'port' => $item['port'] ?? null,
                'usb_path' => $item['usb_path'] ?? null,
                'serial_number' => $item['serial_number'] ?? null,
                'manufacturer_hint' => $item['manufacturer_hint'] ?? null,
                'model_hint' => $item['model_hint'] ?? null,
                'protocol_hint' => $item['protocol_hint'] ?? null,
                'metadata' => $item['metadata'] ?? null,
                'last_seen_at' => now(),
            ]);

            if (!$discovery->exists) {
                $discovery->first_seen_at = now();
                $discovery->status = $matched ? 'registered' : 'discovered';
            }

            if ($matched) {
                $discovery->registered_device_id = $matched->id;
                $discovery->status = 'registered';
            }

            $discovery->save();
            $recorded++;
        }

        return response()->json([
            'status' => true,
            'recorded' => $recorded,
        ]);
    }

    /**
     * 5. POST /api/biometric/attendance/sync (and /v1/logs)
     * Ingest batched attendance events from the agent's local queue with
     * idempotency, deduplication, device authorization & server-side employee resolution.
     */
    public function uploadLogs(AgentAttendanceSyncRequest $request): JsonResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validated();

        $out = $this->sync->withIdempotency(
            'agent:' . $agent->id,
            $request->header('X-Idempotency-Key'),
            $request->getContent(),
            fn () => [
                'status' => true,
                'data' => $this->ingestion->ingestBatch($agent, $validated['logs']),
            ]
        );

        $request->attributes->set('biometric_audit_meta', [
            'batch_size' => count($validated['logs']),
            'replayed' => $out['replayed'],
            'accepted' => $out['body']['data']['accepted'] ?? null,
            'duplicate' => $out['body']['data']['duplicate'] ?? null,
            'rejected' => $out['body']['data']['rejected'] ?? null,
        ]);

        return response()->json($out['body'], $out['status'])
            ->header('X-Idempotent-Replay', $out['replayed'] ? 'true' : 'false');
    }

    /**
     * 7. POST /api/biometric/enrollment/sync
     * Synchronize employee biometric enrollment status & optional encrypted templates
     * reported by an authorized local agent for an authorized device.
     *
     * Security:
     *  - Authorizes target device strictly to ($agent->company_id, $agent->id).
     *  - Resolves every employee strictly server-side from device_user_id.
     *  - Routes any template_data through BiometricTemplateVault (rejects raw images
     *    and blocks server storage when device_only mode is active).
     */
    public function syncEnrollments(AgentEnrollmentSyncRequest $request): JsonResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validated();

        $auth = $this->authz->authorizeDevice(
            $agent,
            isset($validated['device_id']) ? (int) $validated['device_id'] : null,
            $validated['device_serial'] ?? null,
            true
        );

        if (!$auth['ok']) {
            $request->attributes->set('biometric_audit_outcome', 'forbidden_device');
            $request->attributes->set('biometric_audit_summary', $auth['message']);

            return response()->json([
                'status' => false,
                'code' => $auth['code'],
                'message' => $auth['message'],
            ], $auth['status'] ?? 403);
        }

        /** @var BiometricDevice $device */
        $device = $auth['device'];
        $request->attributes->set('biometric_audit_device_id', $device->id);

        $synced = 0;
        $unmatched = 0;
        $rejected = 0;
        $results = [];

        foreach ($validated['enrollments'] as $item) {
            $pin = trim((string) $item['device_user_id']);
            $employee = $this->authz->resolveEmployeeForDevice($agent, $device, $pin);

            // Keep device user directory fresh regardless of match
            BiometricDeviceUser::updateOrCreate(
                ['device_id' => $device->id, 'pin' => $pin],
                [
                    'company_id' => $agent->company_id,
                    'employee_id' => $employee?->id,
                    'name' => $item['name'] ?? ($employee?->user?->name ?? null),
                    'last_synced_at' => now(),
                ]
            );

            if (!$employee) {
                $unmatched++;
                $results[] = [
                    'device_user_id' => $pin,
                    'status' => 'unmatched_employee',
                    'message' => 'No employee in company matched this device_user_id.',
                ];
                continue;
            }

            $modality = BiometricModality::tryFrom((string) ($item['modality'] ?? 'fingerprint')) ?? BiometricModality::Fingerprint;
            $slotIndex = isset($item['slot_index']) ? (int) $item['slot_index'] : ($modality === BiometricModality::Fingerprint ? 6 : 0);
            $statusEnum = match ($item['status'] ?? 'enrolled') {
                'failed' => EnrollmentStatus::Failed,
                'removed' => EnrollmentStatus::Removed,
                default => EnrollmentStatus::Enrolled,
            };

            // Enforce strict template security BEFORE mutating enrollment state
            $templateStored = false;
            if (!empty($item['template_data'])) {
                try {
                    $this->vault->store(
                        $employee,
                        $device,
                        $modality,
                        $slotIndex,
                        (string) ($item['template_format'] ?? ($device->protocol . '_v1')),
                        (string) $item['template_data'],
                        null
                    );
                    $templateStored = true;
                } catch (DeviceActionException $e) {
                    $rejected++;
                    $results[] = [
                        'device_user_id' => $pin,
                        'status' => 'rejected',
                        'code' => 'template_policy_violation',
                        'message' => $e->getMessage(),
                    ];
                    continue;
                }
            }

            $enrollment = BiometricEnrollment::firstOrNew([
                'employee_id' => $employee->id,
                'device_id' => $device->id,
            ]);

            if (!$enrollment->exists) {
                $enrollment->company_id = $agent->company_id;
                $enrollment->enrollment_reference = 'BIO-' . strtoupper(Str::random(10));
            }

            $modalities = is_array($enrollment->enrolled_modalities) ? $enrollment->enrolled_modalities : [];
            if ($statusEnum !== EnrollmentStatus::Removed && $statusEnum !== EnrollmentStatus::Failed) {
                $modalities = array_values(array_unique(array_merge($modalities, [$modality->value])));
            }

            $enrolledAt = now();
            if (!empty($item['enrolled_at'])) {
                try {
                    $enrolledAt = Carbon::parse($item['enrolled_at']);
                } catch (\Throwable) {
                    $enrolledAt = now();
                }
            }

            $enrollment->fill([
                'device_user_id' => $pin,
                'enrollment_status' => $statusEnum,
                'enrolled_modalities' => $modalities,
                'face_enrolled' => $enrollment->face_enrolled || ($modality === BiometricModality::Face && $statusEnum !== EnrollmentStatus::Removed),
                'enrolled_at' => $enrollment->enrolled_at ?? $enrolledAt,
                'last_synced_at' => now(),
                'last_error' => $statusEnum === EnrollmentStatus::Failed ? ($item['error'] ?? 'Enrollment failed on device.') : null,
            ]);
            $enrollment->save();

            // Keep normalized employee <-> device user mapping in sync
            BiometricEmployeeMapping::updateOrCreate(
                [
                    'company_id' => $agent->company_id,
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                ],
                [
                    'device_user_id' => $pin,
                    'is_active' => $statusEnum !== EnrollmentStatus::Removed,
                    'source' => 'agent_sync',
                    'synced_at' => now(),
                ]
            );

            BiometricEnrollmentCredential::updateOrCreate(
                [
                    'enrollment_id' => $enrollment->id,
                    'modality' => $modality->value,
                    'slot_index' => $slotIndex,
                ],
                [
                    'company_id' => $agent->company_id,
                    'employee_id' => $employee->id,
                    'device_id' => $device->id,
                    'slot_label' => $modality === BiometricModality::Fingerprint
                        ? (\App\Biometric\Enums\FingerSlot::tryFrom($slotIndex)?->label() ?? ('Finger ' . $slotIndex))
                        : $modality->label(),
                    'status' => $statusEnum,
                    'device_credential_ref' => $item['template_version'] ?? null,
                    'quality_score' => $item['quality_score'] ?? null,
                    'enrolled_at' => $enrolledAt,
                    'last_synced_at' => now(),
                ]
            );

            // Recompute accurate fingerprint slot count
            $fpCount = BiometricEnrollmentCredential::where('enrollment_id', $enrollment->id)
                ->where('modality', BiometricModality::Fingerprint->value)
                ->where('status', EnrollmentStatus::Enrolled->value)
                ->count();
            $enrollment->forceFill(['fingerprint_count' => $fpCount])->saveQuietly();

            $synced++;
            $results[] = [
                'device_user_id' => $pin,
                'enrollment_reference' => $enrollment->enrollment_reference,
                'status' => $statusEnum->value,
                'template_stored' => $templateStored,
            ];
        }

        $device->forceFill([
            'last_sync_at' => now(),
            'last_successful_sync_at' => $synced > 0 ? now() : $device->last_successful_sync_at,
        ])->saveQuietly();

        $request->attributes->set('biometric_audit_meta', [
            'device_id' => $device->id,
            'synced' => $synced,
            'unmatched' => $unmatched,
            'rejected' => $rejected,
        ]);

        return response()->json([
            'status' => true,
            'data' => [
                'device_id' => $device->id,
                'synced' => $synced,
                'unmatched' => $unmatched,
                'rejected' => $rejected,
                'results' => $results,
            ],
        ]);
    }

    /**
     * GET /api/biometric/agent/v1/employees
     * Return eligible employees for devices assigned to this agent.
     */
    public function employees(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $deviceQuery = BiometricDevice::where('agent_id', $agent->id)
            ->where('company_id', $agent->company_id)
            ->where('is_active', true);

        if ($request->filled('device_id')) {
            $requestedId = (int) $request->input('device_id');
            $auth = $this->authz->authorizeDevice($agent, $requestedId, null, true);
            if (!$auth['ok']) {
                $request->attributes->set('biometric_audit_outcome', 'forbidden_device');

                return response()->json([
                    'status' => false,
                    'code' => $auth['code'],
                    'message' => $auth['message'],
                ], $auth['status'] ?? 403);
            }
            $deviceQuery->where('id', $requestedId);
        }

        $since = null;
        if ($request->filled('since')) {
            try {
                $since = Carbon::parse((string) $request->input('since'));
            } catch (\Throwable) {
                return response()->json(['status' => false, 'message' => 'Invalid since timestamp.'], 422);
            }
        }

        $devices = $deviceQuery->orderBy('id')->get();
        $out = [];

        foreach ($devices as $device) {
            $list = $this->employees->eligible($device);
            if ($since) {
                $list = $list->filter(fn ($e) => $e->updated_at && $e->updated_at->gte($since));
            }

            $out[] = [
                'device_id' => $device->id,
                'device_serial' => $device->serial_number,
                'employees' => $list->map(fn ($e) => [
                    'employee_code' => $e->employee_id,
                    'pin' => (string) $e->biometric_emp_id,
                    'name' => $e->user?->name ?? ('Emp ' . $e->biometric_emp_id),
                    'privilege' => 0,
                    'updated_at' => $e->updated_at?->toIso8601String(),
                ])->values()->all(),
            ];
        }

        return response()->json([
            'status' => true,
            'server_time' => now()->toIso8601String(),
            'data' => $out,
        ]);
    }

    /**
     * 3. GET /api/biometric/agent/commands (and /v1/commands)
     * Poll authorized pending commands for this agent's assigned devices.
     */
    public function pollCommands(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        $limit = $request->filled('limit') ? min(100, max(1, (int) $request->input('limit'))) : null;

        $agent->forceFill([
            'connection_status' => ConnectionStatus::Online,
            'last_seen_at' => now(),
            'last_seen_ip' => $request->ip(),
        ])->saveQuietly();

        $commands = $this->commands->nextForAgent($agent, $limit)->values()->all();

        $request->attributes->set('biometric_audit_meta', ['commands_returned' => count($commands)]);

        return response()->json([
            'status' => true,
            'data' => [
                'commands' => $commands,
            ],
        ]);
    }

    /**
     * 4. POST /api/biometric/agent/commands/{id}/acknowledge (and /v1/commands/{commandId}/ack)
     * Acknowledge execution of a polled command strictly authorized to this agent.
     */
    public function acknowledgeCommand(AgentCommandAckRequest $request, int $commandId): JsonResponse
    {
        $agent = $this->agent($request);
        $validated = $request->validated();

        $command = $this->commands->acknowledgeFromAgent(
            $agent,
            $commandId,
            (bool) $validated['success'],
            $validated['return_code'] ?? null,
            $validated['result'] ?? [],
            $validated['error'] ?? null
        );

        if (!$command) {
            $request->attributes->set('biometric_audit_outcome', 'forbidden_device');
            $request->attributes->set('biometric_audit_summary', "Command #{$commandId} not found or not authorized for this agent.");

            return response()->json([
                'status' => false,
                'code' => 'command_not_authorized',
                'message' => 'Command not found for this agent.',
            ], 404);
        }

        $request->attributes->set('biometric_audit_device_id', $command->device_id);
        $request->attributes->set('biometric_audit_meta', [
            'command_id' => $command->id,
            'command_status' => $command->status->value,
        ]);

        return response()->json([
            'status' => true,
            'data' => [
                'command_id' => $command->id,
                'status' => $command->status->value,
            ],
        ]);
    }

    /**
     * POST /api/biometric/agent/v1/errors
     * Upload local agent / hardware communication errors for monitoring in HRMS.
     */
    public function reportErrors(Request $request): JsonResponse
    {
        $agent = $this->agent($request);

        $validated = $request->validate([
            'errors' => ['required', 'array', 'min:1', 'max:100'],
            'errors.*.device_id' => ['nullable', 'integer'],
            'errors.*.device_serial' => ['nullable', 'string', 'max:100'],
            'errors.*.level' => ['nullable', 'in:info,warning,error'],
            'errors.*.type' => ['required', 'string', 'max:50'],
            'errors.*.message' => ['required', 'string', 'max:500'],
            'errors.*.context' => ['nullable', 'array'],
            'errors.*.occurred_at' => ['nullable', 'string', 'max:64'],
        ]);

        $devices = BiometricDevice::where('agent_id', $agent->id)
            ->where('company_id', $agent->company_id)
            ->get();
        $byId = $devices->keyBy('id');
        $bySerial = $devices->keyBy('serial_number');

        $recorded = 0;
        $lastError = null;

        foreach ($validated['errors'] as $entry) {
            $device = !empty($entry['device_id'])
                ? $byId->get((int) $entry['device_id'])
                : (!empty($entry['device_serial']) ? $bySerial->get($entry['device_serial']) : null);

            $level = EventLevel::tryFrom((string) ($entry['level'] ?? 'error')) ?? EventLevel::Error;
            $context = array_merge($entry['context'] ?? [], [
                'agent_id' => $agent->id,
                'agent_identifier' => $agent->agent_identifier,
                'reported_occurred_at' => $entry['occurred_at'] ?? null,
            ]);

            if ($device) {
                if ($level === EventLevel::Error) {
                    $this->activity->error($device, $entry['type'], $entry['message'], $context);
                } else {
                    $this->activity->event($device, $level, $entry['type'], $entry['message'], $context);
                }
            }

            if ($level === EventLevel::Error) {
                $lastError = $entry['message'];
            }
            $recorded++;
        }

        if ($lastError !== null) {
            $agent->forceFill([
                'last_error' => mb_substr($lastError, 0, 2000),
                'last_error_at' => now(),
                'local_error_count' => $agent->local_error_count + $recorded,
            ])->saveQuietly();
        }

        return response()->json([
            'status' => true,
            'recorded' => $recorded,
        ]);
    }

    /**
     * Apply per-device health snapshots with strict agent/company authorization.
     *
     * @param array<int, array<string, mixed>> $snapshots
     * @return array{updated: int, rejected: int, results: list<array<string, mixed>>}
     */
    private function applyDeviceStatusSnapshots(BiometricAgent $agent, array $snapshots, string $ip): array
    {
        $updated = 0;
        $rejected = 0;
        $results = [];

        foreach ($snapshots as $snap) {
            $auth = $this->authz->authorizeDevice(
                $agent,
                isset($snap['device_id']) ? (int) $snap['device_id'] : null,
                $snap['device_serial'] ?? null,
                false
            );

            if (!$auth['ok']) {
                $rejected++;
                $results[] = [
                    'device_id' => $snap['device_id'] ?? null,
                    'device_serial' => $snap['device_serial'] ?? null,
                    'status' => 'rejected',
                    'code' => $auth['code'],
                    'message' => $auth['message'],
                ];
                continue;
            }

            /** @var BiometricDevice $device */
            $device = $auth['device'];
            $isOnline = isset($snap['status'])
                ? $snap['status'] === 'online'
                : (bool) ($snap['online'] ?? false);
            $reportedStatus = $snap['status'] ?? ($isOnline ? 'online' : 'offline');

            if ($isOnline) {
                $this->activity->heartbeat($device, $ip, [
                    'firmware_version' => $snap['firmware_version'] ?? null,
                ]);

                $infoPatch = array_filter([
                    'firmware_version' => $snap['firmware_version'] ?? null,
                    'user_count' => $snap['user_count'] ?? null,
                    'log_count' => $snap['log_count'] ?? null,
                    'latency_ms' => $snap['latency_ms'] ?? null,
                ], fn ($v) => $v !== null);

                if ($infoPatch) {
                    $device->forceFill([
                        'device_info' => array_merge($device->device_info ?? [], $infoPatch),
                        'device_info_updated_at' => now(),
                    ])->saveQuietly();
                }
            } else {
                $reason = !empty($snap['error'])
                    ? 'Local agent reported device ' . $reportedStatus . ': ' . $snap['error']
                    : 'Local agent reported device ' . $reportedStatus . '.';

                if ($reportedStatus === 'error') {
                    $device->forceFill([
                        'connection_status' => ConnectionStatus::Error,
                        'last_error' => Str::limit($reason, 2000),
                        'last_error_at' => now(),
                    ])->saveQuietly();
                } else {
                    $this->activity->markOffline($device, $reason);
                }

                if (!empty($snap['error'])) {
                    $this->activity->error($device, 'agent_health_error', (string) $snap['error'], [
                        'agent_id' => $agent->id,
                    ]);
                }
            }

            $updated++;
            $results[] = [
                'device_id' => $device->id,
                'device_serial' => $device->serial_number,
                'status' => 'updated',
                'effective_connection_status' => $device->fresh()->effective_connection_status,
            ];
        }

        return [
            'updated' => $updated,
            'rejected' => $rejected,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeDeviceConfig(BiometricDevice $d): array
    {
        return [
            'id' => $d->id,
            'name' => $d->name,
            'serial_number' => $d->serial_number,
            'device_identifier' => $d->device_identifier,
            'manufacturer' => $d->manufacturer,
            'model' => $d->model,
            'device_type' => $d->device_type?->value,
            'protocol' => $d->protocol,
            'connection_type' => $d->connection_type?->value,
            'ip_address' => $d->ip_address,
            'port' => $d->port,
            'timezone' => $d->timezoneName(),
            'auth_type' => $d->auth_type?->value,
            'credentials' => (object) ($d->credentials ?? []),
            'settings' => (object) ($d->settings ?? []),
        ];
    }

    private function agent(Request $request): BiometricAgent
    {
        return $request->attributes->get('biometric_agent');
    }
}
