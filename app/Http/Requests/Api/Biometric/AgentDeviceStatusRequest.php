<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentDeviceStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Allow either a single device status object or a `devices` array.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->has('devices') && ($this->has('device_id') || $this->has('device_serial'))) {
            $this->merge([
                'devices' => [$this->only([
                    'device_id',
                    'device_serial',
                    'online',
                    'status',
                    'latency_ms',
                    'firmware_version',
                    'user_count',
                    'log_count',
                    'error',
                    'checked_at',
                ])],
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'devices' => ['required', 'array', 'min:1', 'max:200'],
            'devices.*.device_id' => ['required_without:devices.*.device_serial', 'nullable', 'integer', 'min:1'],
            'devices.*.device_serial' => ['required_without:devices.*.device_id', 'nullable', 'string', 'max:100'],
            'devices.*.online' => ['required_without:devices.*.status', 'boolean'],
            'devices.*.status' => ['required_without:devices.*.online', 'in:online,offline,error'],
            'devices.*.latency_ms' => ['nullable', 'integer', 'min:0'],
            'devices.*.firmware_version' => ['nullable', 'string', 'max:100'],
            'devices.*.user_count' => ['nullable', 'integer', 'min:0'],
            'devices.*.log_count' => ['nullable', 'integer', 'min:0'],
            'devices.*.error' => ['nullable', 'string', 'max:1000'],
            'devices.*.checked_at' => ['nullable', 'string', 'max:64'],
        ];
    }
}
