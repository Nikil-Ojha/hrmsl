<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AttendancePolicy;
use App\Models\AttendanceRecord;
use App\Models\BiometricAttendanceLog;
use App\Models\BiometricDeviceCommand;
use App\Models\Employee;
use App\Models\Shift;
use App\Models\User;
use App\Services\BiometricDeviceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BiometricAttendanceApiController extends Controller
{
    /**
     * Handle incoming GET or POST biometric attendance requests:
     * 1. FKWeb / Realtime direct machine protocol (headers: request_code, dev_id, trans_id)
     * 2. ADMS / iClock protocol (?SN=RSS20220619704&table=ATTLOG)
     * 3. OnlineRealsoft Third-Party JSON/Form/Query API (employee_code, log_datetime, log_time, device_sn)
     */
    public function handle(Request $request)
    {
        // 1. Check if this is a direct Realtime FKWeb machine push (RSS20220619704 firmware)
        if ($request->hasHeader('request_code') || $request->hasHeader('dev_id')) {
            return $this->handleFkWebPush($request);
        }

        // 2. Check if this is an ADMS / iClock request (?SN=...)
        if ($request->has('SN') || $request->has('sn')) {
            return $this->handleIclockCdata($request);
        }

        // 3. Verify Authorization for Third-Party REST API (Supports None, Bearer Token, API Key, Basic Auth)
        $authError = $this->verifyAuthorization($request);
        if ($authError !== null) {
            return $authError;
        }

        // 4. If GET request without punch parameters, return attendance logs (GET — retrieving data)
        if ($request->isMethod('GET') && !$request->has('employee_code') && !$request->has('logs') && !$request->has('data')) {
            return $this->index($request);
        }

        // 5. Process incoming biometric punch data (POST or GET with Query String / Body)
        return $this->store($request);
    }

    /**
     * Handle Direct Realtime / FKWeb Protocol used by RSS-series devices (e.g. RSS20220619704, RS-9W / RS09W).
     * Headers:
     * - request_code: realtime_glog | receive_cmd | realtime_enroll_data | ...
     * - dev_id: RSS20220619704
     * - trans_id: <int>
     */
    public function handleFkWebPush(Request $request)
    {
        $requestCode = strtolower((string) $request->header('request_code', ''));
        $deviceSn = (string) ($request->header('dev_id') ?: $request->input('device_sn', 'RSS20220619704'));
        $transId = (string) $request->header('trans_id', '0');

        $rawBody = $request->getContent();

        Log::channel('single')->info('[Biometric FKWeb Push]', [
            'request_code' => $requestCode,
            'dev_id' => $deviceSn,
            'trans_id' => $transId,
            'ip' => $request->ip(),
            'raw_length' => strlen($rawBody),
        ]);

        // Heartbeat / command polling from machine
        if ($requestCode === 'receive_cmd') {
            $pendingCmd = BiometricDeviceCommand::whereIn('status', ['pending'])
                ->where(function ($q) use ($deviceSn) {
                    $q->where('device_sn', $deviceSn)
                        ->orWhere('device_sn', BiometricDeviceService::DEFAULT_DEVICE_SN);
                })
                ->orderBy('id', 'asc')
                ->first();

            if ($pendingCmd) {
                $pendingCmd->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);

                return response(json_encode($pendingCmd->fkweb_payload ?? [
                    'user_id' => $pendingCmd->employee_code,
                    'user_name' => $pendingCmd->employee_name,
                    'user_privilege' => 'USER',
                ]), 200)
                    ->header('response_code', 'OK')
                    ->header('trans_id', (string) $pendingCmd->id)
                    ->header('cmd_code', 'SET_USER_INFO')
                    ->header('Content-Type', 'application/octet-stream');
            }

            return response('', 200)
                ->header('response_code', 'OK')
                ->header('trans_id', $transId)
                ->header('cmd_code', 'null');
        }

        // Real-time punch log from machine (realtime_glog) or payload containing user_id & io_time
        $jsonData = $this->extractEmbeddedJson($rawBody);
        if (empty($jsonData) && $request->all()) {
            $jsonData = $request->all();
        }

        if ($requestCode === 'realtime_glog' || isset($jsonData['user_id']) || isset($jsonData['employee_code'])) {
            $empCode = (string) ($jsonData['user_id'] ?? $jsonData['employee_code'] ?? $jsonData['enroll_id'] ?? '');
            $ioTimeRaw = (string) ($jsonData['io_time'] ?? $jsonData['log_datetime'] ?? $jsonData['fk_time'] ?? '');

            $logDateTime = $this->parseMachineDateTime($ioTimeRaw);

            if (!empty($empCode) && $logDateTime) {
                $this->recordPunch($empCode, $logDateTime, $deviceSn, now()->format('Y-m-d H:i:s'));
            }
        }

        return response('OK', 200)
            ->header('response_code', 'OK')
            ->header('trans_id', $transId)
            ->header('Content-Type', 'application/octet-stream');
    }

    /**
     * Handle ADMS / iClock protocol (/iclock/cdata) for RS-9W / RS09W (SN=RSS20220619704).
     */
    public function handleIclockCdata(Request $request)
    {
        $deviceSn = (string) ($request->query('SN') ?? $request->query('sn') ?? 'RSS20220619704');
        $table = strtoupper((string) $request->query('table', ''));

        Log::channel('single')->info('[Biometric iClock cdata]', [
            'method' => $request->method(),
            'SN' => $deviceSn,
            'table' => $table,
            'query' => $request->query(),
            'ip' => $request->ip(),
        ]);

        // 1. Device Handshake / Initialization (GET /iclock/cdata?SN=RSS20220619704&options=all...)
        if ($request->isMethod('GET')) {
            $response = implode("\r\n", [
                "GET OPTION FROM: {$deviceSn}",
                "ATTLOGStamp=0",
                "OPERLOGStamp=9999",
                "ATTPHOTOStamp=None",
                "ErrorDelay=30",
                "Delay=10",
                "TransTimes=00:00;14:05",
                "TransInterval=1",
                "TransFlag=TransData AttLog\tOpLog",
                "TimeZone=330",
                "Realtime=1",
                "Encrypt=None",
            ]) . "\r\n";

            return response($response, 200)->header('Content-Type', 'text/plain');
        }

        // 2. Device Punch Log Upload (POST /iclock/cdata?SN=RSS20220619704&table=ATTLOG&Stamp=...)
        $rawBody = trim((string) $request->getContent());
        $processedCount = 0;

        if (!empty($rawBody) && ($table === 'ATTLOG' || $table === '')) {
            $lines = preg_split('/\r\n|\r|\n/', $rawBody);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || stripos($line, 'OPLOG') === 0 || stripos($line, 'USER') === 0) {
                    continue;
                }

                // Format 1: Tab-separated: PIN \t YYYY-MM-DD HH:MM:SS \t Status \t Verify ...
                $parts = preg_split('/\t+/', $line);
                if (count($parts) < 2) {
                    // Format 2: Space-separated: PIN YYYY-MM-DD HH:MM:SS Status Verify ...
                    if (preg_match('/^(\S+)\s+(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $matches)) {
                        $parts = [$matches[1], $matches[2]];
                    }
                }

                if (count($parts) >= 2) {
                    $empCode = trim($parts[0]);
                    $logDateTime = $this->parseMachineDateTime(trim($parts[1]));

                    if (!empty($empCode) && $logDateTime) {
                        $this->recordPunch($empCode, $logDateTime, $deviceSn, now()->format('Y-m-d H:i:s'));
                        $processedCount++;
                    }
                }
            }

            return response("OK: {$processedCount}", 200)->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Handle ADMS / iClock heartbeat & command poll (GET /iclock/getrequest?SN=RSS20220619704).
     * Sends queued employee enrollment commands (C:<id>:DATA UPDATE USERINFO PIN=...) to the device.
     */
    public function handleIclockGetRequest(Request $request)
    {
        $deviceSn = (string) ($request->query('SN') ?? $request->query('sn') ?? BiometricDeviceService::DEFAULT_DEVICE_SN);

        $pendingCommands = BiometricDeviceCommand::where('status', 'pending')
            ->where(function ($q) use ($deviceSn) {
                $q->where('device_sn', $deviceSn)
                    ->orWhere('device_sn', BiometricDeviceService::DEFAULT_DEVICE_SN);
            })
            ->orderBy('id', 'asc')
            ->limit(10)
            ->get();

        if ($pendingCommands->isNotEmpty()) {
            $lines = [];
            foreach ($pendingCommands as $cmd) {
                $lines[] = "C:{$cmd->id}:{$cmd->adms_command}";
                $cmd->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }

            return response(implode("\r\n", $lines) . "\r\n", 200)
                ->header('Content-Type', 'text/plain');
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Handle ADMS / iClock command acknowledgment (POST /iclock/devicecmd?SN=RSS20220619704).
     */
    public function handleIclockDeviceCmd(Request $request)
    {
        $rawBody = trim((string) $request->getContent());
        if (!empty($rawBody)) {
            $lines = preg_split('/\r\n|\r|\n/', $rawBody);
            foreach ($lines as $line) {
                parse_str(trim($line), $params);
                if (!empty($params['ID'])) {
                    $cmd = BiometricDeviceCommand::find((int) $params['ID']);
                    if ($cmd) {
                        $returnCode = (string) ($params['Return'] ?? '0');
                        $cmd->update([
                            'status' => $returnCode === '0' ? 'acknowledged' : 'failed',
                            'acknowledged_at' => now(),
                            'return_code' => $returnCode,
                        ]);

                        if ($cmd->employee_id && $returnCode === '0') {
                            Employee::where('id', $cmd->employee_id)->update([
                                'is_biometric_enrolled' => true,
                                'biometric_enrollment_status' => 'enrolled',
                                'biometric_enrolled_at' => now(),
                            ]);
                        }
                    }
                }
            }
        }

        return response('OK', 200)->header('Content-Type', 'text/plain');
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
     * - log_time      (Optional/Derived if log_datetime is present, HH:mm:ss)
     * - downloaded_at (Optional, YYYY-MM-DD HH:mm:ss)
     * - device_sn     (Required, String — defaults to RSS20220619704 if omitted by machine)
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
            // Support alternate machine field names if sent directly by Realtime firmware
            if (!isset($item['employee_code']) && isset($item['user_id'])) {
                $item['employee_code'] = $item['user_id'];
            }
            if (!isset($item['log_datetime']) && isset($item['io_time'])) {
                $parsed = $this->parseMachineDateTime((string) $item['io_time']);
                if ($parsed) {
                    $item['log_datetime'] = $parsed;
                }
            }
            if (empty($item['log_time']) && !empty($item['log_datetime'])) {
                $parsed = $this->parseMachineDateTime((string) $item['log_datetime']);
                if ($parsed) {
                    $item['log_datetime'] = $parsed;
                    $item['log_time'] = substr($parsed, 11, 8);
                }
            }

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
            $savedLogs[] = $this->recordPunch(
                $validated['employee_code'],
                $validated['log_datetime'],
                $validated['device_sn'],
                $validated['downloaded_at'] ?? null,
                $validated['log_time']
            );
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
    // 1. extractEmbeddedJson: decompress gzip and extract first balanced JSON object
    protected function extractEmbeddedJson(string $rawBody): array
    {
        if (empty($rawBody)) {
            return [];
        }
        // Handle gzip if device sends compressed payload
        if (substr($rawBody, 0, 2) === "\x1f\x8b") {
            $inflated = @gzdecode($rawBody);
            if ($inflated !== false) {
                $rawBody = $inflated;
            }
        }
        $decoded = json_decode($rawBody, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        // Balanced brace search to avoid being fooled by trailing binary image bytes
        $start = strpos($rawBody, '{');
        if ($start === false) {
            return [];
        }
        $depth = 0;
        $inStr = false;
        $esc = false;
        $len = strlen($rawBody);
        for ($i = $start; $i < $len; $i++) {
            $ch = $rawBody[$i];
            if ($inStr) {
                if ($esc) { $esc = false; }
                elseif ($ch === '\\') { $esc = true; }
                elseif ($ch === '"') { $inStr = false; }
                continue;
            }
            if ($ch === '"') { $inStr = true; }
            elseif ($ch === '{') { $depth++; }
            elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    $json = json_decode(substr($rawBody, $start, $i - $start + 1), true);
                    return is_array($json) ? $json : [];
                }
            }
        }
        return [];
    }

    /**
     * Save a single punch log and trigger attendance calculation for the employee.
     */
    protected function recordPunch(
        string $rawEmployeeCode,
        string $logDateTime,
        string $deviceSn,
        ?string $downloadedAt = null,
        ?string $logTime = null
    ): array {
        $carbonDateTime = Carbon::createFromFormat('Y-m-d H:i:s', $logDateTime);
        $logDate = $carbonDateTime->format('Y-m-d');
        $logTime = $logTime ?: $carbonDateTime->format('H:i:s');

        // Find matching employee by biometric_emp_id or employee_id
        $employee = $this->findEmployee($rawEmployeeCode);
        $companyUserId = $employee ? $employee->created_by : $this->getDefaultCreatorId();

        if ($employee && !$employee->is_biometric_enrolled) {
            $employee->is_biometric_enrolled = true;
            $employee->biometric_enrollment_status = 'enrolled';
            $employee->biometric_device_sn = $deviceSn;
            $employee->biometric_enrolled_at = $employee->biometric_enrolled_at ?? now();
            $employee->saveQuietly();
        }

        // Determine canonical employee_code to group neatly with Employee record if matched
        $canonicalEmpCode = $employee && !empty($employee->biometric_emp_id)
            ? (string) $employee->biometric_emp_id
            : (string) $rawEmployeeCode;

        $log = BiometricAttendanceLog::updateOrCreate(
            [
                'employee_code' => $canonicalEmpCode,
                'log_datetime' => $logDateTime,
                'device_sn' => $deviceSn,
            ],
            [
                'employee_id' => $employee?->user_id,
                'log_date' => $logDate,
                'log_time' => $logTime,
                'downloaded_at' => $downloadedAt,
                'created_by' => $companyUserId,
            ]
        );

        // Sync day's punches and update AttendanceRecord if employee exists
        $attendanceRecord = $this->syncDayPunchesForEmployee($canonicalEmpCode, $logDate, $employee, $companyUserId);

        return [
            'id' => $log->id,
            'employee_code' => $rawEmployeeCode,
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

    /**
     * Parse machine timestamps such as "20261114150500" (YYYYMMDDHHmmss) or "2026-11-14 15:05:00".
     */
    protected function parseMachineDateTime(string $value): ?string
    {
        $value = trim($value);
        if (empty($value)) {
            return null;
        }

        if (preg_match('/^\d{14}$/', $value)) {
            try {
                return Carbon::createFromFormat('YmdHis', $value)->format('Y-m-d H:i:s');
            } catch (\Throwable $e) {
                return null;
            }
        }

        try {
            return Carbon::parse($value)->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return null;
        }
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

        // First punch = Check In, subsequent punches = Check Out
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

    // 2. findEmployee: normalize leading zeros (e.g. "00000002" -> "2")
    protected function findEmployee(string $employeeCode): ?Employee
    {
        $trimmedCode = ltrim($employeeCode, '0') ?: $employeeCode;
        return Employee::with('user')
            ->where('biometric_emp_id', $employeeCode)
            ->orWhere('biometric_emp_id', $trimmedCode)
            ->orWhere('employee_id', $employeeCode)
            ->orWhere('employee_id', $trimmedCode)
            ->first();
    }
}
