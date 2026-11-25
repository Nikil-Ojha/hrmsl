<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\Enums\BiometricModality;
use App\Biometric\Enums\EnrollmentStatus;
use App\Biometric\Enums\FingerSlot;
use App\Biometric\Services\BiometricTemplateVault;
use App\Biometric\Services\EmployeeSelector;
use App\Biometric\Services\EnrollmentService;
use App\Biometric\Support\DeviceActionException;
use App\Biometric\Support\Tenant;
use App\Http\Controllers\Controller;
use App\Models\BiometricDevice;
use App\Models\BiometricEncryptedTemplate;
use App\Models\BiometricEnrollment;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class BiometricEnrollmentController extends Controller
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly EmployeeSelector $employees,
        private readonly BiometricTemplateVault $vault,
    ) {
    }

    /**
     * Company-wide Biometric Enrollments hub (select employee + device, view mappings).
     */
    public function index(Request $request): Response
    {
        $base = BiometricEnrollment::visibleTo();
        $query = (clone $base)
            ->with([
                'employee.user:id,name,email',
                'employee.department:id,name',
                'employee.branch:id,name',
                'device:id,name,serial_number,device_type,protocol,connection_status,is_active,agent_id',
                'device.agent:id,name',
                'credentials',
            ]);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('enrollment_reference', 'like', "%{$search}%")
                    ->orWhere('device_user_id', 'like', "%{$search}%")
                    ->orWhereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('employee', fn ($e) => $e->where('employee_id', 'like', "%{$search}%"))
                    ->orWhereHas('device', fn ($d) => $d->where('name', 'like', "%{$search}%")->orWhere('serial_number', 'like', "%{$search}%"));
            });
        }

        if ($request->filled('status') && $request->input('status') !== '_empty_') {
            $query->where('enrollment_status', $request->input('status'));
        }

        if ($request->filled('device_id') && $request->input('device_id') !== '_empty_') {
            $query->where('device_id', (int) $request->input('device_id'));
        }

        if ($request->filled('employee_id') && $request->input('employee_id') !== '_empty_') {
            $query->where('employee_id', (int) $request->input('employee_id'));
        }

        $perPage = in_array((int) $request->input('per_page', 15), [10, 15, 25, 50], true) ? (int) $request->input('per_page', 15) : 15;
        $records = $query->latest('updated_at')->paginate($perPage)->withQueryString();
        $records->getCollection()->transform(fn (BiometricEnrollment $e) => $this->presentEnrollment($e));

        $companyId = (int) Tenant::companyId();
        $companyUsers = $this->employees->companyUserIds($companyId);

        $employeeList = Employee::with(['user:id,name', 'branch:id,name', 'department:id,name'])
            ->whereIn('created_by', $companyUsers)
            ->whereIn('employee_status', ['active', 'probation'])
            ->orderBy('id')
            ->get()
            ->map(fn (Employee $e) => [
                'id' => $e->id,
                'name' => $e->user?->name ?? $e->employee_id,
                'employee_code' => $e->employee_id,
                'device_user_id' => $e->biometric_emp_id,
                'branch' => $e->branch?->name,
                'department' => $e->department?->name,
            ]);

        return Inertia::render('hr/biometric-enrollments/index', [
            'enrollments' => $records,
            'stats' => [
                'total' => (clone $base)->where('enrollment_status', '!=', EnrollmentStatus::Removed->value)->count(),
                'enrolled' => (clone $base)->where('enrollment_status', EnrollmentStatus::Enrolled->value)->count(),
                'awaiting' => (clone $base)->whereIn('enrollment_status', [EnrollmentStatus::Pending->value, EnrollmentStatus::AwaitingCapture->value, EnrollmentStatus::Syncing->value])->count(),
                'failed' => (clone $base)->where('enrollment_status', EnrollmentStatus::Failed->value)->count(),
            ],
            'employees' => $employeeList,
            'devices' => $this->availableDevicesPayload($companyId),
            'fingerSlots' => FingerSlot::options(),
            'statuses' => EnrollmentStatus::options(),
            'storageMode' => $this->vault->storageMode(),
            'filters' => $request->only(['search', 'status', 'device_id', 'employee_id', 'per_page']),
        ]);
    }

    /**
     * Start biometric enrollment for an employee on a selected device.
     */
    public function start(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer'],
            'device_id' => ['required', 'integer'],
            'modality' => ['required', Rule::in(BiometricModality::values())],
            'finger_slots' => ['nullable', 'array', 'max:10'],
            'finger_slots.*' => ['integer', 'between:0,9'],
            'device_user_id' => ['nullable', 'string', 'max:20'],
        ]);

        $employee = $this->findCompanyEmployee((int) $validated['employee_id']);
        $device = $this->findCompanyDevice((int) $validated['device_id']);

        try {
            $enrollment = $this->enrollments->startEnrollment(
                $employee,
                $device,
                BiometricModality::from($validated['modality']),
                $validated['finger_slots'] ?? [FingerSlot::RightIndex->value],
                $validated['device_user_id'] ?? null,
                $request->user()
            );

            $msg = match ($enrollment->enrollment_status) {
                EnrollmentStatus::Enrolled => __('Biometric enrollment completed (Ref: :ref).', ['ref' => $enrollment->enrollment_reference]),
                EnrollmentStatus::AwaitingCapture => __('Employee profile synced to :device (Ref: :ref). Complete fingerprint/face capture on the device.', ['device' => $device->name, 'ref' => $enrollment->enrollment_reference]),
                default => __('Biometric enrollment queued for :device (Ref: :ref).', ['device' => $device->name, 'ref' => $enrollment->enrollment_reference]),
            };

            return redirect()->back()->with('success', $msg);
        } catch (DeviceActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Re-enroll an employee on an existing device mapping.
     */
    public function reEnroll(Request $request, BiometricEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeEnrollment($enrollment, 'enroll-biometric-employees');

        $validated = $request->validate([
            'modality' => ['nullable', Rule::in(BiometricModality::values())],
            'finger_slots' => ['nullable', 'array', 'max:10'],
            'finger_slots.*' => ['integer', 'between:0,9'],
        ]);

        try {
            $updated = $this->enrollments->reEnroll(
                $enrollment,
                !empty($validated['modality']) ? BiometricModality::from($validated['modality']) : null,
                $validated['finger_slots'] ?? [],
                $request->user()
            );

            return redirect()->back()->with('success', __('Re-enrollment initiated (Ref: :ref, Status: :status).', [
                'ref' => $updated->enrollment_reference,
                'status' => $updated->enrollment_status->label(),
            ]));
        } catch (DeviceActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Confirm that biometric capture has been completed on the physical terminal.
     */
    public function confirm(Request $request, BiometricEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeEnrollment($enrollment, 'enroll-biometric-employees');

        $this->enrollments->confirmCapturedOnDevice($enrollment, $request->user());

        return redirect()->back()->with('success', __('Biometric enrollment confirmed as captured on device.'));
    }

    /**
     * Synchronize an employee's enrollment across multiple authorized devices.
     */
    public function syncDevices(Request $request, Employee $employee): RedirectResponse
    {
        $employee = $this->findCompanyEmployee($employee->id);

        $validated = $request->validate([
            'device_ids' => ['required', 'array', 'min:1', 'max:100'],
            'device_ids.*' => ['integer'],
            'source_device_id' => ['nullable', 'integer'],
        ]);

        try {
            $summary = $this->enrollments->syncAcrossDevices(
                $employee,
                $validated['device_ids'],
                $validated['source_device_id'] ?? null,
                $request->user()
            );

            $msg = __('Synchronization queued for :count device(s).', ['count' => $summary['synced']]);
            if ($summary['templates_queued'] > 0) {
                $msg .= ' ' . __(':tpl encrypted template transfer(s) queued.', ['tpl' => $summary['templates_queued']]);
            }
            if (!empty($summary['skipped'])) {
                $msg .= ' ' . __(':skip device(s) skipped.', ['skip' => count($summary['skipped'])]);
            }

            return redirect()->back()->with($summary['synced'] > 0 ? 'success' : 'error', $msg);
        } catch (DeviceActionException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    /**
     * Remove an employee's biometric registration from one device.
     */
    public function remove(Request $request, BiometricEnrollment $enrollment): RedirectResponse
    {
        $this->authorizeEnrollment($enrollment, 'remove-biometric-enrollments');

        $updated = $this->enrollments->removeEnrollment($enrollment, $request->user());

        return redirect()->back()->with('success', $updated->enrollment_status === EnrollmentStatus::Removed
            ? __('Biometric registration removed from device.')
            : __('Removal command queued for the biometric device.'));
    }

    /**
     * Remove ALL biometric registrations for an employee across all devices.
     */
    public function removeAll(Request $request, Employee $employee): RedirectResponse
    {
        $employee = $this->findCompanyEmployee($employee->id);
        $count = $this->enrollments->removeAllForEmployee($employee, $request->user());

        return redirect()->back()->with('success', __('Biometric removal processed across :count device(s).', ['count' => $count]));
    }

    /**
     * Build the safe, template-free Biometric Enrollment prop for Employee Profile.
     */
    public function payloadForEmployee(Employee $employee): array
    {
        $companyId = (int) Tenant::companyId();

        $enrollments = BiometricEnrollment::with([
            'device:id,name,serial_number,device_type,protocol,connection_status,is_active,branch_id,agent_id',
            'device.branch:id,name',
            'device.agent:id,name',
            'sourceDevice:id,name',
            'enrolledBy:id,name',
            'credentials',
        ])
            ->where('employee_id', $employee->id)
            ->where('company_id', $companyId)
            ->orderByRaw("CASE WHEN enrollment_status = 'removed' THEN 1 ELSE 0 END")
            ->latest('updated_at')
            ->get()
            ->map(fn (BiometricEnrollment $e) => $this->presentEnrollment($e))
            ->values()
            ->all();

        // Count only — NEVER serialize template rows or template bytes into employee props!
        $encryptedTemplateCount = $this->vault->isTransferAllowed()
            ? BiometricEncryptedTemplate::where('employee_id', $employee->id)->where('company_id', $companyId)->count()
            : 0;

        return [
            'employee_id' => $employee->id,
            'device_user_id' => $employee->biometric_emp_id,
            'is_enrolled' => (bool) $employee->is_biometric_enrolled,
            'status' => $employee->biometric_enrollment_status ?: 'not_enrolled',
            'storage_mode' => $this->vault->storageMode(),
            'encrypted_template_count' => $encryptedTemplateCount,
            'enrollments' => $enrollments,
            'available_devices' => $this->availableDevicesPayload($companyId),
            'finger_slots' => FingerSlot::options(),
        ];
    }

    /* ================================================================= */
    /* Helpers                                                            */
    /* ================================================================= */

    private function availableDevicesPayload(int $companyId): array
    {
        return BiometricDevice::with(['branch:id,name', 'agent:id,name'])
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn (BiometricDevice $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'serial_number' => $d->serial_number,
                'device_type' => $d->device_type?->value,
                'device_type_label' => $d->device_type?->label(),
                'protocol' => $d->protocol,
                'connection_status' => $d->effective_connection_status,
                'branch' => $d->branch?->name,
                'agent_name' => $d->agent?->name,
                'supported_modalities' => array_map(
                    fn (BiometricModality $m) => ['value' => $m->value, 'label' => $m->label()],
                    BiometricModality::forDeviceType($d->device_type)
                ),
            ])
            ->values()
            ->all();
    }

    private function presentEnrollment(BiometricEnrollment $e): array
    {
        return [
            'id' => $e->id,
            'employee_id' => $e->employee_id,
            'employee_name' => $e->employee?->user?->name,
            'employee_code' => $e->employee?->employee_id,
            'device_id' => $e->device_id,
            'device_name' => $e->device?->name,
            'device_serial' => $e->device?->serial_number,
            'device_type' => $e->device?->device_type?->value,
            'device_type_label' => $e->device?->device_type?->label(),
            'device_connection_status' => $e->device?->effective_connection_status,
            'device_active' => (bool) $e->device?->is_active,
            'agent_name' => $e->device?->agent?->name,
            'device_user_id' => $e->device_user_id,
            'enrollment_reference' => $e->enrollment_reference,
            'enrollment_status' => $e->enrollment_status->value,
            'enrollment_status_label' => $e->enrollment_status->label(),
            'enrolled_modalities' => $e->enrolled_modalities ?? [],
            'fingerprint_count' => $e->fingerprint_count,
            'face_enrolled' => (bool) $e->face_enrolled,
            're_enrollment_count' => $e->re_enrollment_count,
            'source_device_name' => $e->sourceDevice?->name,
            'enrolled_by' => $e->enrolledBy?->name,
            'enrolled_at' => $e->enrolled_at?->toIso8601String(),
            'last_synced_at' => $e->last_synced_at?->toIso8601String(),
            'last_error' => $e->last_error,
            'supported_modalities' => $e->device
                ? array_map(fn (BiometricModality $m) => ['value' => $m->value, 'label' => $m->label()], BiometricModality::forDeviceType($e->device->device_type))
                : [],
            'credentials' => $e->credentials->map(fn ($c) => [
                'id' => $c->id,
                'modality' => $c->modality->value,
                'modality_label' => $c->modality->label(),
                'slot_index' => $c->slot_index,
                'slot_label' => $c->slot_label,
                'status' => $c->status->value,
                'quality_score' => $c->quality_score,
                'enrolled_at' => $c->enrolled_at?->toIso8601String(),
                'last_synced_at' => $c->last_synced_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    private function findCompanyEmployee(int $employeeId): Employee
    {
        $companyUsers = $this->employees->companyUserIds((int) Tenant::companyId());
        $employee = Employee::with('user')->whereIn('created_by', $companyUsers)->find($employeeId);
        abort_unless($employee !== null, 404);

        return $employee;
    }

    private function findCompanyDevice(int $deviceId): BiometricDevice
    {
        $device = BiometricDevice::visibleTo()->find($deviceId);
        abort_unless($device !== null, 404);

        return $device;
    }

    private function authorizeEnrollment(BiometricEnrollment $enrollment, string $permission): void
    {
        $user = Auth::user();
        abort_unless($user->can($permission), 403, __('Permission Denied.'));
        abort_unless(BiometricEnrollment::visibleTo($user)->whereKey($enrollment->id)->exists(), 404);
    }
}
