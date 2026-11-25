<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentHeartbeatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_version' => ['nullable', 'string', 'max:50'],
            'os_info' => ['nullable', 'string', 'max:150'],
            'hostname' => ['nullable', 'string', 'max:150'],
            'local_queue_depth' => ['nullable', 'integer', 'min:0'],
            'local_error_count' => ['nullable', 'integer', 'min:0'],
            'capabilities' => ['nullable', 'array', 'max:30'],
            'devices' => ['nullable', 'array', 'max:200'],
            'devices.*.device_id' => ['nullable', 'integer', 'min:1'],
            'devices.*.device_serial' => ['nullable', 'string', 'max:100'],
            'devices.*.online' => ['required', 'boolean'],
            'devices.*.latency_ms' => ['nullable', 'integer', 'min:0'],
            'devices.*.firmware_version' => ['nullable', 'string', 'max:100'],
            'devices.*.user_count' => ['nullable', 'integer', 'min:0'],
            'devices.*.log_count' => ['nullable', 'integer', 'min:0'],
            'devices.*.error' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
