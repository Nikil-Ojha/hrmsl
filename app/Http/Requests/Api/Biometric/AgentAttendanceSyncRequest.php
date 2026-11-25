<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentAttendanceSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize `events` alias into `logs` so both /api/biometric/attendance/sync
     * and /api/biometric/agent/v1/logs share identical strict validation.
     *
     * Security: strips any client-supplied `company_id` or `employee_id` so
     * employee resolution ALWAYS happens server-side within the agent's tenant.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('logs');
        if ($items === null && is_array($this->input('events'))) {
            $items = $this->input('events');
        }

        if (is_array($items)) {
            $sanitized = array_map(function ($row) {
                if (!is_array($row)) {
                    return $row;
                }
                unset($row['company_id'], $row['tenant_id'], $row['employee_id'], $row['user_id']);

                return $row;
            }, $items);

            $this->merge(['logs' => $sanitized]);
        }
    }

    public function rules(): array
    {
        return [
            'logs' => ['required', 'array', 'min:1', 'max:500'],
            'logs.*.local_uuid' => ['required', 'string', 'max:100'],
            'logs.*.device_id' => ['required_without:logs.*.device_serial', 'nullable', 'integer', 'min:1'],
            'logs.*.device_serial' => ['required_without:logs.*.device_id', 'nullable', 'string', 'max:100'],
            'logs.*.employee_code' => ['required_without:logs.*.device_user_id', 'string', 'max:100'],
            'logs.*.device_user_id' => ['required_without:logs.*.employee_code', 'string', 'max:100'],
            'logs.*.punched_at' => ['required', 'string', 'max:64'],
            'logs.*.punch_type' => ['nullable', 'string', 'max:32'],
            'logs.*.punch_state' => ['nullable', 'string', 'max:32'],
            'logs.*.verification_method' => ['nullable', 'string', 'max:32'],
            'logs.*.verify_mode' => ['nullable', 'string', 'max:32'],
            'logs.*.original_device_log_id' => ['nullable', 'string', 'max:100'],
            'logs.*.device_log_id' => ['nullable', 'string', 'max:100'],
            'logs.*.raw_metadata' => ['nullable', 'array'],
        ];
    }
}
