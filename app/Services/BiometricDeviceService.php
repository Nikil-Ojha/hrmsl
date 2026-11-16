<?php

namespace App\Services;

use App\Models\BiometricAttendanceLog;
use App\Models\BiometricDeviceCommand;
use App\Models\Employee;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BiometricDeviceService
{
    public const DEFAULT_DEVICE_SN = 'RSS20220619704';

    /**
     * Enroll / sync an Employee to the biometric device (RS-9W / RS09W / ZKTeco / OnlineRealsoft).
     */
    public static function enrollEmployee(Employee $employee, ?string $deviceSn = null, ?string $customEmpCode = null): array
    {
        $employee->loadMissing(['user', 'department', 'designation']);

        $deviceSn = $deviceSn ?: ($employee->biometric_device_sn ?: env('BIOMETRIC_DEVICE_SN', self::DEFAULT_DEVICE_SN));

        // Ensure employee has a valid biometric_emp_id
        if (!empty($customEmpCode)) {
            $employee->biometric_emp_id = $customEmpCode;
        } elseif (empty($employee->biometric_emp_id)) {
            $employee->biometric_emp_id = self::generateUniqueBiometricCode();
        }

        $empCode = (string) $employee->biometric_emp_id;
        $empName = $employee->user ? $employee->user->name : ('Emp ' . $empCode);
        $cleanName = preg_replace('/[\t\r\n]+/', ' ', substr($empName, 0, 24));

        // 1. Build ADMS / iClock USERINFO command for RS-9W / RS09W
        $admsCommand = "DATA UPDATE USERINFO PIN={$empCode}\tName={$cleanName}\tPri=0\tPasswd=\tCard=\tGrp=1\tTZ=0000000100000000";

        // 2. Build FKWeb / Realtime JSON payload for RS-9W (RSS20220619704)
        $fkwebPayload = [
            'user_id' => $empCode,
            'user_name' => $cleanName,
            'user_privilege' => 'USER',
            'privilege' => 0,
            'enable' => 1,
            'device_sn' => $deviceSn,
        ];

        // 3. Queue command for the physical machine to pick up via /iclock/getrequest or FKWeb receive_cmd
        $command = BiometricDeviceCommand::updateOrCreate(
            [
                'employee_code' => $empCode,
                'device_sn' => $deviceSn,
                'command_type' => 'SET_USER',
                'status' => 'pending',
            ],
            [
                'employee_id' => $employee->id,
                'employee_name' => $cleanName,
                'adms_command' => $admsCommand,
                'fkweb_payload' => $fkwebPayload,
                'created_by' => $employee->created_by,
            ]
        );

        // 4. Also push immediately to external ZKTeco BioTime / OnlineRealsoft API if configured
        $remoteApiSynced = self::pushEmployeeToExternalApi($employee, $empCode, $cleanName, $deviceSn);

        // 5. Update Employee biometric enrollment status
        $employee->is_biometric_enrolled = true;
        $employee->biometric_enrollment_status = 'enrolled';
        $employee->biometric_device_sn = $deviceSn;
        $employee->biometric_enrolled_at = now();
        $employee->saveQuietly();

        Log::channel('single')->info('[Biometric Employee Enrolled]', [
            'employee_id' => $employee->id,
            'biometric_emp_id' => $empCode,
            'name' => $cleanName,
            'device_sn' => $deviceSn,
            'command_id' => $command->id,
            'remote_api_synced' => $remoteApiSynced,
        ]);

        return [
            'success' => true,
            'employee_id' => $employee->id,
            'biometric_emp_id' => $empCode,
            'device_sn' => $deviceSn,
            'command_id' => $command->id,
            'status' => $employee->biometric_enrollment_status,
        ];
    }

    /**
     * Push employee creation to external BioTime / OnlineRealsoft webhook if configured.
     */
    protected static function pushEmployeeToExternalApi(Employee $employee, string $empCode, string $empName, string $deviceSn): bool
    {
        try {
            $companySettings = settings($employee->created_by);
            $apiUrl = !empty($companySettings['zkteco_api_url']) ? rtrim($companySettings['zkteco_api_url'], '/') : '';
            $token = $companySettings['zkteco_auth_token'] ?? '';

            if (!empty($apiUrl) && !empty($token)) {
                $response = Http::timeout(10)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'Authorization' => 'Token ' . $token,
                    ])
                    ->post($apiUrl . '/personnel/api/employees/', [
                        'emp_code' => $empCode,
                        'first_name' => $empName,
                        'department' => 1,
                        'area' => [1],
                        'device_sn' => $deviceSn,
                    ]);

                return $response->successful();
            }

            $customPushUrl = env('BIOMETRIC_EMPLOYEE_PUSH_URL');
            if (!empty($customPushUrl)) {
                $headers = ['Content-Type' => 'application/json'];
                if ($apiToken = env('BIOMETRIC_API_TOKEN')) {
                    $headers['Authorization'] = 'Bearer ' . $apiToken;
                }
                $response = Http::timeout(10)
                    ->withHeaders($headers)
                    ->post($customPushUrl, [
                        'employee_code' => $empCode,
                        'employee_name' => $empName,
                        'device_sn' => $deviceSn,
                    ]);

                return $response->successful();
            }
        } catch (\Throwable $e) {
            Log::warning('[Biometric External API Push Failed] ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Generate a unique 4-to-9 digit biometric employee code if missing.
     */
    public static function generateUniqueBiometricCode(): string
    {
        $maxCode = Employee::whereNotNull('biometric_emp_id')
            ->whereRaw('biometric_emp_id REGEXP "^[0-9]+$"')
            ->max(\DB::raw('CAST(biometric_emp_id AS UNSIGNED)'));

        $next = $maxCode ? max(1001, ((int) $maxCode) + 1) : 1001;

        while (Employee::where('biometric_emp_id', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    /**
     * Sync enrollment flags for employees who already have punches in biometric_attendance_logs.
     */
    public static function reconcileEnrolledFromLogs(?array $companyUserIds = null): void
    {
        $punchedCodes = BiometricAttendanceLog::query()
            ->select('employee_code', 'device_sn')
            ->distinct()
            ->get();

        foreach ($punchedCodes as $row) {
            $query = Employee::where('biometric_emp_id', $row->employee_code)
                ->where('is_biometric_enrolled', false);

            if ($companyUserIds) {
                $query->whereIn('created_by', $companyUserIds);
            }

            $query->update([
                'is_biometric_enrolled' => true,
                'biometric_enrollment_status' => 'enrolled',
                'biometric_device_sn' => $row->device_sn ?: self::DEFAULT_DEVICE_SN,
                'biometric_enrolled_at' => now(),
            ]);
        }
    }
}
