<?php

namespace App\Biometric\Services;

use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\EventLevel;
use App\Biometric\Support\DeviceActionException;
use App\Models\BiometricDevice;
use App\Models\BiometricEncryptedTemplate;
use App\Models\Employee;
use App\Models\User;

/**
 * Strictly guarded vault for optional cross-device encrypted biometric
 * template transfer.
 *
 * SECURITY GUARANTEES:
 *  1. Raw fingerprint images and facial images are ALWAYS rejected (checked by
 *     data-URI prefix, binary magic bytes, base64-decoded magic bytes, and size).
 *  2. Default policy is `device_only`: templates stay on the hardware device
 *     and matching is performed on the device itself.
 *  3. Stored templates use Laravel authenticated encryption and are isolated
 *     in `biometric_encrypted_templates`, never exposed in employee APIs or logs.
 */
class BiometricTemplateVault
{
    /** Binary magic prefixes for common image formats that must NEVER be stored. */
    private const IMAGE_SIGNATURES = [
        "\xFF\xD8\xFF",         // JPEG
        "\x89PNG\r\n\x1A\n",    // PNG
        "BM",                   // BMP
        "GIF87a",               // GIF87a
        "GIF89a",               // GIF89a
        "II*\x00",              // TIFF LE
        "MM\x00*",              // TIFF BE
        "RIFF",                 // WebP / RIFF container
        "\x00\x00\x01\x00",     // ICO
        "<svg",                 // SVG
        "<?xml",                // XML/SVG
    ];

    public function __construct(private readonly DeviceActivityRecorder $activity)
    {
    }

    public function isTransferAllowed(): bool
    {
        return (bool) config('biometric.templates.allow_transfer', false);
    }

    public function storageMode(): string
    {
        return $this->isTransferAllowed() ? 'encrypted_transfer' : 'device_only';
    }

    /**
     * Reject raw fingerprint/facial images or oversized payloads.
     *
     * @throws DeviceActionException
     */
    public function assertNotRawImage(string $payload): void
    {
        $trimmed = trim($payload);
        if ($trimmed === '') {
            throw new DeviceActionException(__('Biometric template payload is empty.'));
        }

        if (preg_match('/^data:image\//i', $trimmed)) {
            throw new DeviceActionException(__('Raw biometric images are strictly prohibited and cannot be stored in HRMS.'));
        }

        $maxBytes = (int) config('biometric.templates.max_template_bytes', 32768);
        if (strlen($trimmed) > ($maxBytes * 2)) {
            throw new DeviceActionException(__('Biometric template exceeds maximum allowed size (:bytes bytes). Raw images are prohibited.', ['bytes' => $maxBytes]));
        }

        $this->checkSignatures($trimmed);

        // Also inspect decoded bytes if the template is base64-encoded.
        $decoded = base64_decode($trimmed, true);
        if ($decoded !== false && $decoded !== '') {
            if (strlen($decoded) > $maxBytes) {
                throw new DeviceActionException(__('Decoded biometric template exceeds maximum allowed size (:bytes bytes).', ['bytes' => $maxBytes]));
            }
            $this->checkSignatures($decoded);
        }
    }

    /**
     * Store a mathematical biometric template encrypted at rest for cross-device transfer.
     */
    public function store(
        Employee $employee,
        BiometricDevice $sourceDevice,
        BiometricModality $modality,
        int $slotIndex,
        string $templateFormat,
        string $templatePayload,
        ?User $actor = null,
    ): BiometricEncryptedTemplate {
        if (!$this->isTransferAllowed()) {
            throw new DeviceActionException(__('Server-side biometric template storage is disabled (device-only policy). Templates remain exclusively on hardware devices.'));
        }

        $this->assertNotRawImage($templatePayload);

        $hash = hash('sha256', $templatePayload);

        $existing = BiometricEncryptedTemplate::where('employee_id', $employee->id)
            ->where('modality', $modality->value)
            ->where('slot_index', $slotIndex)
            ->where('template_format', $templateFormat)
            ->first();

        if ($existing) {
            $existing->forceFill([
                'source_device_id' => $sourceDevice->id,
                'template_hash' => $hash,
                'template_data' => $templatePayload, // encrypted by model cast
                'version' => $existing->version + 1,
                'created_by' => $actor?->id,
            ])->save();
            $template = $existing;
        } else {
            $template = BiometricEncryptedTemplate::create([
                'company_id' => $sourceDevice->company_id,
                'employee_id' => $employee->id,
                'source_device_id' => $sourceDevice->id,
                'modality' => $modality,
                'slot_index' => $slotIndex,
                'template_format' => mb_substr(trim($templateFormat), 0, 64),
                'template_hash' => $hash,
                'template_data' => $templatePayload, // encrypted by model cast
                'version' => 1,
                'created_by' => $actor?->id,
            ]);
        }

        // Audit only the hash and metadata — NEVER log template bytes.
        $this->activity->event($sourceDevice, EventLevel::Info, 'template_stored_encrypted', sprintf(
            'Encrypted %s template (slot %d, format %s, hash %s...) stored for authorized cross-device transfer.',
            $modality->value,
            $slotIndex,
            $templateFormat,
            substr($hash, 0, 12)
        ), ['employee_id' => $employee->id, 'template_id' => $template->id, 'template_hash' => $hash]);

        return $template;
    }

    /**
     * Decrypt a stored template strictly in memory for transmission to an
     * authorized device in the same company.
     */
    public function decryptForAuthorizedTransfer(BiometricEncryptedTemplate $template, BiometricDevice $targetDevice): string
    {
        if (!$this->isTransferAllowed()) {
            throw new DeviceActionException(__('Biometric template transfer is disabled by security policy.'));
        }

        if ((int) $template->company_id !== (int) $targetDevice->company_id) {
            throw new DeviceActionException(__('Cross-tenant biometric template transfer is forbidden.'));
        }

        if (!$targetDevice->is_active) {
            throw new DeviceActionException(__('Cannot transfer biometric template to an inactive device.'));
        }

        $template->forceFill(['last_transferred_at' => now()])->saveQuietly();

        $this->activity->event($targetDevice, EventLevel::Info, 'template_transferred', sprintf(
            'Encrypted %s template (slot %d, hash %s...) dispatched to authorized device.',
            $template->modality->value,
            $template->slot_index,
            substr($template->template_hash, 0, 12)
        ), ['employee_id' => $template->employee_id, 'template_id' => $template->id]);

        return (string) $template->template_data;
    }

    public function purgeForEmployee(Employee $employee, ?int $sourceDeviceId = null, ?BiometricModality $modality = null, ?array $slots = null): int
    {
        return BiometricEncryptedTemplate::where('employee_id', $employee->id)
            ->when($sourceDeviceId !== null, fn ($q) => $q->where('source_device_id', $sourceDeviceId))
            ->when($modality !== null, fn ($q) => $q->where('modality', $modality->value))
            ->when(!empty($slots), fn ($q) => $q->whereIn('slot_index', $slots))
            ->delete();
    }

    private function checkSignatures(string $bytes): void
    {
        $head = substr($bytes, 0, 16);
        foreach (self::IMAGE_SIGNATURES as $sig) {
            if (str_starts_with($head, $sig) || stripos($head, $sig) === 0) {
                throw new DeviceActionException(__('Raw biometric images (JPEG/PNG/BMP/GIF/TIFF/SVG) are strictly prohibited and cannot be stored in HRMS.'));
            }
        }
    }
}
