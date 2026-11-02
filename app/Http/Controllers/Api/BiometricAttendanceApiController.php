<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\BiometricAttendanceLog;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class BiometricAttendanceApiController extends Controller
{
    /**
     * Handle incoming GET or POST biometric attendance requests
     * as specified in OnlineRealsoft Third-Party API Integration Setup.
     */
    public function handle(Request $request)
    {
        // 1. Verify Authorization (Supports None, Bearer Token, API Key, Basic Auth)
        $authError = $this->verifyAuthorization($request);
        if ($authError !== null) {
            return $authError;
        }

        // 2. If GET request without punch parameters, return attendance logs (GET — retrieving data)
        if ($request->isMethod('GET') && !$request->has('employee_code') && !$request->has('logs') && !$request->has('data')) {
            return $this->index($request);
        }

        // 3. Otherwise, process incoming biometric punch data (POST or GET with Query String / Body)
        return $this->store($request);
    }

    /**
     * Retrieve biometric attendance logs (GET).
     */
    public function index(Request $request)
    {
        $authError = $this->verifyAuthorization($request);
        if ($authError !== null) {
            return $authError;
        }

        $query = BiometricAttendanceLog::query()->orderBy('log_datetime', 'desc');

        if ($request->filled('employee_code')) {
            $query->where('employee_code', (string) $request->employee_code);
        }

        if ($request->filled('device_sn')) {
            $query->where('device_sn', (string) $request->device_sn);
        }

        if ($request->filled('date')) {
            $query->whereDate('log_date', $request->date);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('log_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('log_date', '<=', $request->end_date);
        }

        $perPage = min((int) ($request->per_page ?? 50), 500);
        $logs = $query->paginate($perPage);

        return response()->json([
            'status' => true,
            'message' => 'Biometric attendance logs retrieved successfully.',
            'data' => $logs->items(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
                'last_page' => $logs->lastPage(),
            ],
        ], 200);
    }

    /**
     * Store biometric attendance log(s) pushed from Realtime Biometrics / OnlineRealsoft.
     *
     * Expected Parameters:
     * - employee_code (Required, String or Int)
     * - log_datetime  (Required, YYYY-MM-DD HH:mm:ss)
     * - log_time      (Required, HH:mm:ss)
     * - downloaded_at (Optional, YYYY-MM-DD HH:mm:ss)
     * - device_sn     (Required, String)
     */
    public function store(Request $request)
    {
        $authError = $this->verifyAuthorization($request);
        if ($authError !== null) {
            return $authError;
        }

        $payload = $this->extractRecordsFromRequest($request);

        if (empty($payload)) {
            return response()->json([
                'status' => false,
                'message' => 'No attendance log data provided.',
                'errors' => [
                    'employee_code' => ['The employee_code field is required.'],
                    'log_datetime' => ['The log_datetime field is required.'],
                    'log_time' => ['The log_time field is required.'],
                    'device_sn' => ['The device_sn field is required.'],
                ],
            ], 422);
        }

        $isBatch = $this->isBatchPayload($request);
        $savedLogs = [];
        $errors = [];

        foreach ($payload as $index => $item) {
            // Normalize integer employee_code to string if sent as int
            if (isset($item['employee_code']) && (is_int($item['employee_code']) || is_numeric($item['employee_code']))) {
                $item['employee_code'] = (string) $item['employee_code'];
            }

            $validator = Validator::make($item, [
                'employee_code' => ['required', 'string', 'max:100'],
                'log_datetime' => ['required', 'date_format:Y-m-d H:i:s'],
                'log_time' => ['required', 'date_format:H:i:s'],
                'downloaded_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
                'device_sn' => ['required', 'string', 'max:100'],
            ], [
                'employee_code.required' => 'employee_code is required (String or Int).',
                'log_datetime.required' => 'log_datetime is required in YYYY-MM-DD HH:mm:ss format.',
                'log_datetime.date_format' => 'log_datetime must match format YYYY-MM-DD HH:mm:ss.',
                'log_time.required' => 'log_time is required in HH:mm:ss format.',
                'log_time.date_format' => 'log_time must match format HH:mm:ss.',
                'downloaded_at.date_format' => 'downloaded_at must match format YYYY-MM-DD HH:mm:ss.',
                'device_sn.required' => 'device_sn is required.',
            ]);

            if ($validator->fails()) {
                if (!$isBatch) {
                    return response()->json([
                        'status' => false,
                        'message' => 'Validation failed.',
                        'errors' => $validator->errors(),
                    ], 422);
                }

                $errors[$index] = $validator->errors();
                continue;
            }

            $validated = $validator->validated();
            $logDate = Carbon::createFromFormat('Y-m-d H:i:s', $validated['log_datetime'])->format('Y-m-d');
            $logTime = $validated['log_time'];

            // Find matching employee by biometric_emp_id or employee_id
            $employee = $this->findEmployee($validated['employee_code']);
            $companyUserId = $employee ? $employee->created_by : $this->getDefaultCreatorId();

            // Determine canonical employee_code to group neatly with Employee record if matched
            $canonicalEmpCode = $employee && !empty($employee->biometric_emp_id)
                ? (string) $employee->biometric_emp_id
                : (string) $validated['employee_code'];

            $log = BiometricAttendanceLog::updateOrCreate(
                [
                    'employee_code' => $canonicalEmpCode,
                    'log_datetime' => $validated['log_datetime'],
                    'device_sn' => $validated['device_sn'],
                ],
                [
                    'employee_id' => $employee?->user_id,
                    'log_date' => $logDate,
                    'log_time' => $logTime,
                    'downloaded_at' => $validated['downloaded_at'] ?? null,
                    'created_by' => $companyUserId,
                ]
            );

            // Sync day's punches and update AttendanceRecord if employee exists
            $attendanceRecord = $this->syncDayPunchesForEmployee($canonicalEmpCode, $logDate, $employee, $companyUserId);

            $savedLogs[] = [
                'id' => $log->id,
                'employee_code' => $validated['employee_code'],
                'matched_employee_id' => $employee?->user_id,
                'employee_name' => $employee?->user?->name,
                'log_datetime' => $log->log_datetime->format('Y-m-d H:i:s'),
                'log_time' => $log->log_time,
                'downloaded_at' => $log->downloaded_at ? $log->downloaded_at->format('Y-m-d H:i:s') : null,
                'device_sn' => $log->device_sn,
                'punch_state' => $log->fresh()->punch_state,
                'is_synced' => $log->fresh()->is_synced,
                'attendance_record_id' => $attendanceRecord?->id,
            ];
        }

        if (!$isBatch) {
            return response()->json([
                'status' => true,
                'message' => 'Attendance log recorded successfully.',
                'data' => $savedLogs[0] ?? null,
            ], 200);
        }

        return response()->json([
            'status' => count($savedLogs) > 0,
            'message' => count($savedLogs) . ' attendance log(s) processed.',
            'data' => $savedLogs,
            'errors' => $errors,
        ], count($savedLogs) > 0 ? 200 : 422);
    }

    /**
     * Match Employee by biometric_emp_id, employee_id, or numeric suffix (e.g. "E1023" -> "1023").
     */
    protected function findEmployee(string $employeeCode): ?Employee
    {
        $employee = Employee::with('user')
            ->where('biometric_emp_id', $employeeCode)
            ->orWhere('employee_id', $employeeCode)
            ->first();

        if (!$employee) {
            $numericCode = preg_replace('/\D/', '', $employeeCode);
            if (!empty($numericCode) && $numericCode !== $employeeCode) {
                $employee = Employee::with('user')
                    ->where('biometric_emp_id', $numericCode)
                    ->first();
            }
        }

        return $employee;
    }

    /**
     * Update punch states (Check In / Check Out) for all logs on the given date
     * and automatically create/update the employee's AttendanceRecord.
     */
    protected function syncDayPunchesForEmployee(string $employeeCode, string $logDate, ?Employee $employee, ?int $companyUserId): ?AttendanceRecord
    {
        $dayLogs = BiometricAttendanceLog::where('employee_code', $employeeCode)
            ->whereDate('log_date', $logDate)
            ->orderBy('log_datetime', 'asc')
            ->get();

        if ($dayLogs->isEmpty()) {
            return null;
        }

        // First punch = Check In, subsequent punches = Check Out (or alternating)
        foreach ($dayLogs as $idx => $dayLog) {
            $state = ($idx === 0) ? 'Check In' : 'Check Out';
            if ($dayLog->punch_state !== $state) {
                $dayLog->punch_state = $state;
                $dayLog->save();
            }
        }

        if (!$employee || !$employee->user_id) {
            return null;
        }

        $firstLog = $dayLogs->first();
        $lastLog = $dayLogs->last();

        $clockIn = date('H:i:s', strtotime($firstLog->log_datetime));
        $clockOut = $dayLogs->count() > 1 && $lastLog->id !== $firstLog->id
            ? date('H:i:s', strtotime($lastLog->log_datetime))
            : null;

        $creatorIds = $companyUserId ? [$companyUserId] : [];

        $shift = Shift::where('id', $employee->shift_id)
            ->where('status', 'active')
            ->first();

        if (!$shift && !empty($creatorIds)) {
            $shift = Shift::whereIn('created_by', $creatorIds)
                ->where('status', 'active')
                ->first();
        }

        $policy = AttendancePolicy::where('id', $employee->attendance_policy_id)
            ->where('status', 'active')
            ->first();

        if (!$policy && !empty($creatorIds)) {
            $policy = AttendancePolicy::whereIn('created_by', $creatorIds)
                ->where('status', 'active')
                ->first();
        }

        $attendance = AttendanceRecord::where('employee_id', $employee->user_id)
            ->whereDate('date', $logDate)
            ->first();

        if (!$attendance) {
            $attendance = new AttendanceRecord();
            $attendance->employee_id = $employee->user_id;
            $attendance->date = $logDate;
            $attendance->created_by = $companyUserId ?? $employee->user_id;
        }

        $attendance->biometric_id = (string) $firstLog->id;
        $attendance->shift_id = $attendance->shift_id ?? $shift?->id;
        $attendance->attendance_policy_id = $attendance->attendance_policy_id ?? $policy?->id;
        $attendance->clock_in = $clockIn;

        if ($clockOut !== null) {
            $attendance->clock_out = $clockOut;
        }

        $attendance->save();

        if ($attendance->clock_in && $attendance->clock_out) {
            $attendance->refresh();
            $attendance->processAttendance();

            BiometricAttendanceLog::where('employee_code', $employeeCode)
                ->whereDate('log_date', $logDate)
                ->update(['is_synced' => true]);
        } else {
            // Check late arrival on clock-in even before clock-out
            $attendance->refresh();
            $attendance->checkLateArrival();
            $attendance->status = 'present';
            $attendance->save();
        }

        return $attendance;
    }

    /**
     * Verify Authorization header/token if configured.
     * Supports: None, Bearer Token, API Key, Basic Auth.
     */
    protected function verifyAuthorization(Request $request)
    {
        $companySettings = settings();
        $configuredToken = env('BIOMETRIC_API_TOKEN')
            ?: ($companySettings['biometric_api_token'] ?? ($companySettings['zkteco_auth_token'] ?? null));
        $requireAuth = filter_var(
            env('BIOMETRIC_API_AUTH_REQUIRED', $companySettings['biometric_api_auth_required'] ?? false),
            FILTER_VALIDATE_BOOLEAN
        );

        $authHeader = $request->header('Authorization', '');
        $apiKeyHeader = $request->header('X-API-KEY') ?? $request->header('x-api-key') ?? $request->input('api_key');

        // If client sent Basic Auth: validate against biometric username/password or User table
        if (stripos($authHeader, 'Basic ') === 0) {
            $username = $request->getUser();
            $password = $request->getPassword();

            $cfgUser = $companySettings['zkteco_username'] ?? null;
            $cfgPass = $companySettings['zkteco_password'] ?? null;

            if ($cfgUser && $cfgPass && $username === $cfgUser && $password === $cfgPass) {
                return null;
            }

            $user = User::where('email', $username)->orWhere('name', $username)->first();
            if ($user && Hash::check($password, $user->password)) {
                return null;
            }

            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Invalid Basic Auth credentials.',
            ], 401);
        }

        // Extract Bearer Token or Token or API Key if provided
        $providedToken = null;
        if (stripos($authHeader, 'Bearer ') === 0) {
            $providedToken = trim(substr($authHeader, 7));
        } elseif (stripos($authHeader, 'Token ') === 0) {
            $providedToken = trim(substr($authHeader, 6));
        } elseif (!empty($authHeader)) {
            $providedToken = trim($authHeader);
        } elseif (!empty($apiKeyHeader)) {
            $providedToken = trim($apiKeyHeader);
        }

        // If auth is strictly required, or if both a configured token and a provided token exist, validate match
        if ($requireAuth && empty($providedToken)) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized: Authorization token or API key is required.',
            ], 401);
        }

        if (!empty($configuredToken) && !empty($providedToken) && !hash_equals((string) $configuredToken, (string) $providedToken)) {
            // Only reject if BIOMETRIC_API_TOKEN or biometric_api_token was explicitly configured
            $explicitApiToken = env('BIOMETRIC_API_TOKEN') ?: ($companySettings['biometric_api_token'] ?? null);
            if (!empty($explicitApiToken)) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized: Invalid Bearer Token or API Key.',
                ], 401);
            }
        }

        return null;
    }

    /**
     * Extract single or multiple attendance log items from JSON body, form data, or query string.
     */
    protected function extractRecordsFromRequest(Request $request): array
    {
        $all = $request->all();

        if (empty($all)) {
            $rawContent = $request->getContent();
            if (!empty($rawContent)) {
                $decoded = json_decode($rawContent, true);
                if (is_array($decoded)) {
                    $all = $decoded;
                }
            }
        }

        if (empty($all)) {
            return [];
        }

        // Case 1: { "logs": [ ... ] } or { "data": [ ... ] }
        if (isset($all['logs']) && is_array($all['logs'])) {
            return array_values($all['logs']);
        }
        if (isset($all['data']) && is_array($all['data']) && isset($all['data'][0])) {
            return array_values($all['data']);
        }

        // Case 2: Top-level JSON array [ { "employee_code": ... }, ... ]
        if (array_is_list($all) && isset($all[0]) && is_array($all[0])) {
            return $all;
        }

        // Case 3: Single record { "employee_code": "E1023", ... }
        return [$all];
    }

    protected function isBatchPayload(Request $request): bool
    {
        $all = $request->all();
        if (isset($all['logs']) && is_array($all['logs'])) {
            return true;
        }
        if (isset($all['data']) && is_array($all['data']) && isset($all['data'][0])) {
            return true;
        }
        return array_is_list($all) && isset($all[0]) && is_array($all[0]);
    }

    protected function getDefaultCreatorId(): ?int
    {
        $companyUser = User::where('type', 'company')->first() ?? User::first();
        return $companyUser?->id;
    }
}
