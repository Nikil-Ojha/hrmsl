<?php

namespace App\Http\Requests\Biometric;

use App\Biometric\ProviderRegistry;
use App\Biometric\Enums\AuthType;
use App\Biometric\Enums\ConnectionType;
use App\Biometric\Enums\DeviceType;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Support\Tenant;
use App\Models\BiometricAgent;
use App\Models\BiometricDevice;
use App\Models\Branch;
use App\Models\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BiometricDeviceRequest extends FormRequest
{
    private const CODE_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9\-_.:]*$/';

    public function authorize(): bool
    {
        $device = $this->route('device');

        return $device
            ? $this->user()->can('edit-biometric-devices')
            : $this->user()->can('create-biometric-devices');
    }

    protected function prepareForValidation(): void
    {
        $trim = fn ($v) => is_string($v) ? trim($v) : $v;

        $this->merge([
            'serial_number' => $trim($this->serial_number),
            'device_identifier' => $trim($this->device_identifier) ?: null,
            'ip_address' => $this->normalizeIp($trim($this->ip_address)),
            'port' => $this->port === '' ? null : $this->port,
            'branch_id' => in_array($this->branch_id, ['', '_none_', null], true) ? null : $this->branch_id,
            'department_id' => in_array($this->department_id, ['', '_none_', null], true) ? null : $this->department_id,
            'agent_id' => in_array($this->agent_id, ['', '_none_', null], true) ? null : $this->agent_id,
            'timezone' => $trim($this->timezone) ?: null,
            'is_active' => filter_var($this->input('is_active', true), FILTER_VALIDATE_BOOLEAN),
        ]);
    }

    /**
     * Normalize zero-padded IPv4 addresses shown on biometric device screens
     * (e.g. "192.168.001.224" -> "192.168.1.224") while still rejecting invalid octets.
     */
    private function normalizeIp(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $value)) {
            $octets = explode('.', $value);
            $normalized = [];
            foreach ($octets as $octet) {
                $intVal = (int) ltrim($octet, '0');
                if ($intVal > 255) {
                    return $value; // Let Laravel's 'ip' rule report invalid IP
                }
                $normalized[] = (string) $intVal;
            }

            return implode('.', $normalized);
        }

        return $value;
    }

    public function rules(): array
    {
        $companyId = Tenant::companyId($this->user());
        /** @var BiometricDevice|null $device */
        $device = $this->route('device');
        $registry = app(ProviderRegistry::class);

        $uniqueInCompany = fn (string $column) => Rule::unique('biometric_devices', $column)
            ->where('company_id', $companyId)
            ->ignore($device?->id);

        $authTypes = AuthType::values();
        if ($registry->has((string) $this->protocol)) {
            $authTypes = array_map(fn (AuthType $a) => $a->value, $registry->definition($this->protocol)->authTypes);
        }

        // Existing devices may keep a provider that is no longer selectable (e.g. mock in production).
        $allowedProviders = $registry->selectableKeys();
        if ($device && !in_array($device->protocol, $allowedProviders, true)) {
            $allowedProviders[] = $device->protocol;
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'serial_number' => ['required', 'string', 'max:100', 'regex:' . self::CODE_PATTERN, $uniqueInCompany('serial_number')],
            'device_identifier' => ['nullable', 'string', 'max:100', 'regex:' . self::CODE_PATTERN, $uniqueInCompany('device_identifier')],
            'manufacturer' => ['nullable', 'string', 'max:100'],
            'model' => ['nullable', 'string', 'max:100'],
            'device_type' => ['required', Rule::in(DeviceType::values())],
            'protocol' => ['required', Rule::in($allowedProviders)],
            'connection_type' => ['required', Rule::in(ConnectionType::values())],
            'ip_address' => ['nullable', 'ip'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'branch_id' => ['nullable', 'integer'],
            'department_id' => ['nullable', 'integer'],
            'agent_id' => ['nullable', 'integer'],
            'timezone' => ['nullable', 'timezone:all'],
            'auth_type' => ['required', Rule::in($authTypes)],
            'credentials' => ['nullable', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.unique' => __('A device with this serial number is already registered in your company.'),
            'device_identifier.unique' => __('A device with this identifier is already registered in your company.'),
            'serial_number.regex' => __('The serial number may only contain letters, numbers, dashes, underscores, dots and colons.'),
            'device_identifier.regex' => __('The identifier may only contain letters, numbers, dashes, underscores, dots and colons.'),
            'auth_type.in' => __('The selected authentication method is not supported by this protocol.'),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $companyUsers = app(EmployeeSelector::class)->companyUserIds((int) Tenant::companyId($this->user()));

            $branch = null;
            if ($this->branch_id) {
                $branch = Branch::whereIn('created_by', $companyUsers)->find($this->branch_id);
                if (!$branch) {
                    $v->errors()->add('branch_id', __('The selected branch is invalid.'));
                }
            }

            if ($this->department_id) {
                $department = Department::whereIn('created_by', $companyUsers)->find($this->department_id);
                if (!$department) {
                    $v->errors()->add('department_id', __('The selected department is invalid.'));
                } elseif ($branch && $department->branch_id && (int) $department->branch_id !== (int) $branch->id) {
                    $v->errors()->add('department_id', __('The selected department does not belong to the selected branch.'));
                }
            }

            if ($this->agent_id) {
                $agent = BiometricAgent::where('company_id', Tenant::companyId($this->user()))->find($this->agent_id);
                if (!$agent) {
                    $v->errors()->add('agent_id', __('The selected local agent is invalid.'));
                }
            }

            $isNetworkedLocal = in_array($this->connection_type, [ConnectionType::Lan->value, ConnectionType::Wifi->value], true);
            if ($this->port && !$this->ip_address && $isNetworkedLocal) {
                $v->errors()->add('ip_address', __('Enter the IP address when a port is specified.'));
            }

            // Only accept credential keys the driver declares.
            $registry = app(ProviderRegistry::class);
            if ($registry->has((string) $this->protocol) && is_array($this->credentials)) {
                $allowed = array_column($registry->definition($this->protocol)->credentialFields, 'name');
                foreach (array_keys($this->credentials) as $key) {
                    if (!in_array($key, $allowed, true)) {
                        $v->errors()->add("credentials.{$key}", __('Unknown credential field.'));
                    }
                }
            }
        });
    }
}
