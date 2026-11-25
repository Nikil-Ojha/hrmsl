<?php

namespace App\Http\Controllers\Api;

use App\Biometric\Enums\PunchSource;
use App\Biometric\Services\CommandQueue;
use App\Biometric\Services\DeviceActivityRecorder;
use App\Biometric\Services\PunchSynchronizationService;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Direct authenticated HTTPS/JSON endpoints for Own Brand ("native") biometric
 * devices that push directly to cloud HRMS without a local agent.
 */
class BiometricDevicePushApiController extends Controller
{
    public function __construct(
        private readonly PunchSynchronizationService $sync,
        private readonly CommandQueue $commands,
        private readonly DeviceActivityRecorder $activity,
    ) {
    }

    /**
     * Ingest single or batched attendance events from an authenticated device.
     */
    public function pushPunches(Request $request): JsonResponse
    {
        /** @var BiometricDevice $device */
        $device = $request->attributes->get('biometric_device');
        $this->activity->heartbeat($device, $request->ip());

        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:500'],
            'events.*.device_user_id' => ['required_without:events.*.employee_code', 'string', 'max:100'],
            'events.*.employee_code' => ['required_without:events.*.device_user_id', 'string', 'max:100'],
            'events.*.punched_at' => ['required', 'string', 'max:64'],
            'events.*.punch_type' => ['nullable', 'string', 'max:32'],
            'events.*.punch_state' => ['nullable', 'string', 'max:32'],
            'events.*.verification_method' => ['nullable', 'string', 'max:32'],
            'events.*.verify_mode' => ['nullable', 'string', 'max:32'],
            'events.*.original_device_log_id' => ['nullable', 'string', 'max:100'],
            'events.*.local_uuid' => ['nullable', 'string', 'max:100'],
            'events.*.raw_metadata' => ['nullable', 'array'],
        ]);

        $idempotencyKey = $request->header('X-Idempotency-Key');

        $out = $this->sync->withIdempotency(
            'device:' . $device->id,
            $idempotencyKey,
            $request->getContent(),
            fn () => [
                'status' => true,
                'data' => $this->sync->ingestBatchForDevice($device, $validated['events'], PunchSource::DirectDevice, null, 'device'),
            ]
        );

        return response()->json($out['body'], $out['status'])
            ->header('X-Idempotent-Replay', $out['replayed'] ? 'true' : 'false');
    }

    public function pollCommands(Request $request): JsonResponse
    {
        /** @var BiometricDevice $device */
        $device = $request->attributes->get('biometric_device');
        $this->activity->heartbeat($device, $request->ip());

        $items = $this->commands->nextFor($device)->map(fn ($row) => json_decode($row['encoded']->body, true))->values()->all();

        return response()->json(['status' => true, 'data' => ['commands' => $items]]);
    }

    public function acknowledgeCommand(Request $request, int $wireId): JsonResponse
    {
        /** @var BiometricDevice $device */
        $device = $request->attributes->get('biometric_device');
        $this->activity->heartbeat($device, $request->ip());

        $validated = $request->validate([
            'success' => ['required', 'boolean'],
            'return_code' => ['nullable', 'string', 'max:50'],
            'result' => ['nullable', 'array'],
        ]);

        $cmd = $this->commands->acknowledge($device, $wireId, (bool) $validated['success'], $validated['return_code'] ?? null, $validated['result'] ?? []);
        if (!$cmd) {
            return response()->json(['status' => false, 'message' => 'Command not found.'], 404);
        }

        return response()->json(['status' => true, 'data' => ['status' => $cmd->status->value]]);
    }
}
