<?php

namespace App\Http\Requests\Biometric;

use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Support\Tenant;
use App\Models\BiometricAgent;
use App\Models\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BiometricAgentRequest extends FormRequest
{
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_.]*$/';

    public function authorize(): bool
    {
        $agent = $this->route('agent');

        return $agent
            ? $this->user()->can('edit-biometric-agents')
            : $this->user()->can('create-biometric-agents');
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? trim($v) : $v;
        $cidrs = $this->input('discovery_cidrs');
        if (is_string($cidrs)) {
            $cidrs = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $cidrs))));
        }

        $this->merge([
            'name' => $trim($this->name),
            'agent_identifier' => $trim($this->agent_identifier) ?: null,
            'branch_id' => in_array($this->branch_id, ['', '_none_', null], true) ? null : $this->branch_id,
            'discovery_enabled' => filter_var($this->input('discovery_enabled', false), FILTER_VALIDATE_BOOLEAN),
            'discovery_cidrs' => is_array($cidrs) ? $cidrs : [],
        ]);
    }

    public function rules(): array
    {
        /** @var BiometricAgent|null $agent */
        $agent = $this->route('agent');

        return [
            'name' => ['required', 'string', 'max:255'],
            'agent_identifier' => [
                'nullable',
                'string',
                'max:100',
                'regex:' . self::CODE_PATTERN,
                Rule::unique('biometric_agents', 'agent_identifier')->ignore($agent?->id),
            ],
            'branch_id' => ['nullable', 'integer'],
            'heartbeat_interval_sec' => ['nullable', 'integer', 'between:15,600'],
            'command_poll_interval_sec' => ['nullable', 'integer', 'between:5,300'],
            'log_poll_interval_sec' => ['nullable', 'integer', 'between:15,3600'],
            'log_batch_size' => ['nullable', 'integer', 'between:10,500'],
            'discovery_enabled' => ['boolean'],
            'discovery_cidrs' => ['nullable', 'array', 'max:20'],
            'discovery_cidrs.*' => ['string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($this->branch_id) {
                $companyUsers = app(EmployeeSelector::class)->companyUserIds((int) Tenant::companyId($this->user()));
                if (!Branch::whereIn('created_by', $companyUsers)->whereKey($this->branch_id)->exists()) {
                    $v->errors()->add('branch_id', __('The selected branch is invalid.'));
                }
            }

            foreach ((array) $this->input('discovery_cidrs', []) as $idx => $cidr) {
                if (!preg_match('#^(\d{1,3}\.){3}\d{1,3}/([12]?\d|3[0-2])$#', $cidr)) {
                    $v->errors()->add("discovery_cidrs.{$idx}", __('Invalid IPv4 CIDR subnet (e.g. 192.168.1.0/24).'));
                }
            }
        });
    }
}
