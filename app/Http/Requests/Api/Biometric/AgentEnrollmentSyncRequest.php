<?php

namespace App\Http\Requests\Api\Biometric;

use Illuminate\Foundation\Http\FormRequest;

class AgentEnrollmentSyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Security: strip any client-supplied company_id / employee_id so employees
     * are ALWAYS resolved strictly server-side from device_user_id within the
     * authenticated agent's tenant.
     */
    protected function prepareForValidation(): void
    {
        $items = $this->input('enrollments');
        if (is_array($items)) {
            $sanitized = array_map(function ($row) {
                if (!is_array($row)) {
                    return $row;
                }
                unset($row['company_id'], $row['tenant_id'], $row['employee_id'], $row['user_id']);

                return $row;
            }, $items);

            $this->merge(['enrollments' => $sanitized]);
        }
    }

    public function rules(): array
    {
        return [
            'device_id' => ['required_without:device_serial', 'nullable', 'integer', 'min:1'],
            'device_serial' => ['required_without:device_id', 'nullable', 'string', 'max:100'],
            'enrollments' => ['required', 'array', 'min:1', 'max:500'],
            'enrollments.*.device_user_id' => ['required', 'string', 'max:100'],
            'enrollments.*.name' => ['nullable', 'string', 'max:150'],
            'enrollments.*.modality' => ['nullable', 'in:fingerprint,face,pin,card'],
            'enrollments.*.slot_index' => ['nullable', 'integer', 'between:0,9'],
            'enrollments.*.status' => ['nullable', 'in:enrolled,synced,failed,removed'],
            'enrollments.*.enrolled_at' => ['nullable', 'string', 'max:64'],
            'enrollments.*.error' => ['nullable', 'string', 'max:500'],
            // Optional template transfer (strictly validated & rejected if raw image or device_only mode!)
            'enrollments.*.template_data' => ['nullable', 'string', 'max:65536'],
            'enrollments.*.template_format' => ['nullable', 'string', 'max:80'],
            'enrollments.*.template_version' => ['nullable', 'string', 'max:40'],
            'enrollments.*.quality_score' => ['nullable', 'integer', 'between:0,100'],
        ];
    }
}
