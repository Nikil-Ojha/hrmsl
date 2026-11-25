<?php

use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\DeviceType;
use App\Biometric\Services\BiometricTemplateVault;
use App\Biometric\Support\DeviceActionException;
use App\Models\BiometricEncryptedTemplate;

uses(Tests\TestCase::class);

it('defaults to device_only mode and blocks template storage unless enabled', function () {
    config(['biometric.templates.allow_transfer' => false]);
    $vault = app(BiometricTemplateVault::class);

    expect($vault->isTransferAllowed())->toBeFalse()
        ->and($vault->storageMode())->toBe('device_only');
});

it('rejects raw fingerprint or facial images in every common image format', function (string $payload) {
    $vault = app(BiometricTemplateVault::class);
    $vault->assertNotRawImage($payload);
})->with([
    'jpeg binary' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF"],
    'png binary' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR"],
    'bmp binary' => ["BM\x36\x00\x00\x00"],
    'gif binary' => ['GIF89a...'],
    'data uri' => ['data:image/jpeg;base64,/9j/4AAQSkZJRg=='],
    'base64 png' => [base64_encode("\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDRfakeimage")],
    'base64 jpeg' => [base64_encode("\xFF\xD8\xFF\xE0\x00\x10JFIFfakeface")],
    'svg markup' => ['<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
    'oversized payload' => [str_repeat('A', 70000)],
])->throws(DeviceActionException::class);

it('accepts valid compact mathematical biometric templates', function () {
    $vault = app(BiometricTemplateVault::class);
    // Non-image binary minutiae bytes encoded as base64
    $validTemplate = base64_encode("MINUTIAE_V2\x01\x02\x03\x04" . str_repeat("\x5A", 256));

    $vault->assertNotRawImage($validTemplate);
    expect(true)->toBeTrue();
});

it('never serializes template_data from BiometricEncryptedTemplate', function () {
    $tpl = new BiometricEncryptedTemplate();
    $tpl->forceFill([
        'id' => 1,
        'modality' => BiometricModality::Fingerprint,
        'slot_index' => 6,
        'template_format' => 'iso-19794-2',
        'template_hash' => str_repeat('a', 64),
        'template_data' => 'SUPER_SECRET_BIOMETRIC_TEMPLATE_BYTES',
        'version' => 1,
    ]);

    $json = $tpl->toJson();
    expect($json)->not->toContain('SUPER_SECRET_BIOMETRIC_TEMPLATE_BYTES')
        ->and($json)->not->toContain('template_data')
        ->and($tpl->toArray())->not->toHaveKey('template_data');
});

it('maps supported biometric modalities per device hardware type', function () {
    expect(BiometricModality::forDeviceType(DeviceType::FaceFingerprint))
        ->toContain(BiometricModality::Fingerprint, BiometricModality::Face)
        ->and(BiometricModality::forDeviceType(DeviceType::Fingerprint))
        ->not->toContain(BiometricModality::Face);
});
