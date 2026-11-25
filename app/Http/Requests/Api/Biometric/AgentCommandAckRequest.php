<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentCommandAckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'success' => ['required', 'boolean'],
            'return_code' => ['nullable', 'string', 'max:50'],
            'result' => ['nullable', 'array'],
            'error' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
