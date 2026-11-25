<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'agent_identifier' => ['required', 'string', 'max:100'],
            'provisioning_token' => ['required', 'string', 'min:16', 'max:120'],
            'agent_version' => ['nullable', 'string', 'max:50'],
            'os_info' => ['nullable', 'string', 'max:150'],
            'hostname' => ['nullable', 'string', 'max:150'],
            'capabilities' => ['nullable', 'array', 'max:30'],
            'capabilities.*' => ['string', 'max:64'],
        ];
    }
}
