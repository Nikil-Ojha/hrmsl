<?php

use App\Http\Controllers\Api\BiometricAgentApiController;
use App\Http\Controllers\Api\BiometricAttendanceApiController;
use App\Http\Controllers\Api\BiometricDevicePushApiController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Biometric Attendance Third-Party Integration API Routes
|--------------------------------------------------------------------------
| Supports both GET and POST (Query String or JSON/Form Body) as per
| OnlineRealsoft Third-Party API Integration Setup documentation.
*/

Route::match(['get', 'post'], '/attendance-log', [BiometricAttendanceApiController::class, 'handle'])
    ->name('api.biometric.attendance-log');

Route::match(['get', 'post'], '/attendance', [BiometricAttendanceApiController::class, 'handle'])
    ->name('api.biometric.attendance');

Route::match(['get', 'post'], '/biometric/attendance-log', [BiometricAttendanceApiController::class, 'handle'])
    ->name('api.biometric.logs');

/*
|--------------------------------------------------------------------------
| Biometric Local Agent Secure REST API (Canonical Endpoints)
|--------------------------------------------------------------------------
| Security stack:
|  1. biometric.agent.audit  -> Immutable secret-scrubbed API audit logs
|  2. biometric.https        -> HTTPS-only enforcement (426 on plain HTTP)
|  3. biometric.agent        -> Per-agent Bearer + HMAC-SHA256 + dual-layer replay protection
|  4. throttle:*             -> Per-agent / per-IP named rate limiters
*/
Route::prefix('biometric')
    ->middleware(['biometric.agent.audit', 'biometric.https'])
    ->name('api.biometric.')
    ->group(function () {
        // 1. Agent registration / one-time token provisioning
        Route::post('agent/register', [BiometricAgentApiController::class, 'provision'])
            ->middleware('throttle:biometric-agent-register')
            ->name('agent.register');

        // Authenticated per-agent endpoints
        Route::middleware(['biometric.agent'])->group(function () {
            Route::middleware('throttle:biometric-agent-api')->group(function () {
                // 2. Agent heartbeat & telemetry
                Route::post('agent/heartbeat', [BiometricAgentApiController::class, 'heartbeat'])
                    ->name('agent.heartbeat');

                // 3. Poll authorized pending commands
                Route::get('agent/commands', [BiometricAgentApiController::class, 'pollCommands'])
                    ->name('agent.commands');

                // 4. Acknowledge executed command
                Route::post('agent/commands/{commandId}/acknowledge', [BiometricAgentApiController::class, 'acknowledgeCommand'])
                    ->whereNumber('commandId')
                    ->name('agent.commands.acknowledge');

                // 6. Push device health & connectivity status
                Route::post('devices/status', [BiometricAgentApiController::class, 'updateDeviceStatus'])
                    ->name('devices.status');

                // 8. Fetch single authorized device configuration & eligible PIN directory
                Route::get('devices/{id}/configuration', [BiometricAgentApiController::class, 'deviceConfiguration'])
                    ->whereNumber('id')
                    ->name('devices.configuration');

                // Authenticated credential rotation (invalidates previous token immediately)
                Route::post('agent/token/rotate', [BiometricAgentApiController::class, 'rotateToken'])
                    ->name('agent.token.rotate');
            });

            Route::middleware('throttle:biometric-agent-sync')->group(function () {
                // 5. Synchronize batched attendance punches (idempotent & deduplicated)
                Route::post('attendance/sync', [BiometricAgentApiController::class, 'uploadLogs'])
                    ->name('attendance.sync');

                // 7. Synchronize employee biometric enrollments (template-vault protected)
                Route::post('enrollment/sync', [BiometricAgentApiController::class, 'syncEnrollments'])
                    ->name('enrollment.sync');
            });
        });

        /*
        |----------------------------------------------------------------------
        | Versioned Local Agent API (v1 — Backward-Compatible Aliases)
        |----------------------------------------------------------------------
        */
        Route::prefix('agent/v1')->name('agent.v1.')->group(function () {
            Route::post('provision', [BiometricAgentApiController::class, 'provision'])
                ->middleware('throttle:biometric-agent-register')
                ->name('provision');

            Route::middleware(['biometric.agent'])->group(function () {
                Route::middleware('throttle:biometric-agent-api')->group(function () {
                    Route::get('config', [BiometricAgentApiController::class, 'config'])->name('config');
                    Route::post('heartbeat', [BiometricAgentApiController::class, 'heartbeat'])->name('heartbeat');
                    Route::post('discovery', [BiometricAgentApiController::class, 'discovery'])->name('discovery');
                    Route::get('employees', [BiometricAgentApiController::class, 'employees'])->name('employees');
                    Route::get('commands', [BiometricAgentApiController::class, 'pollCommands'])->name('commands');
                    Route::post('commands/{commandId}/ack', [BiometricAgentApiController::class, 'acknowledgeCommand'])->whereNumber('commandId')->name('commands.ack');
                    Route::post('errors', [BiometricAgentApiController::class, 'reportErrors'])->name('errors');
                    Route::post('devices/status', [BiometricAgentApiController::class, 'updateDeviceStatus'])->name('devices.status');
                    Route::get('devices/{id}/configuration', [BiometricAgentApiController::class, 'deviceConfiguration'])->whereNumber('id')->name('devices.configuration');
                    Route::post('token/rotate', [BiometricAgentApiController::class, 'rotateToken'])->name('token.rotate');
                });

                Route::middleware('throttle:biometric-agent-sync')->group(function () {
                    Route::post('logs', [BiometricAgentApiController::class, 'uploadLogs'])->name('logs');
                    Route::post('enrollment/sync', [BiometricAgentApiController::class, 'syncEnrollments'])->name('enrollment.sync');
                });
            });
        });
    });

/*
|--------------------------------------------------------------------------
| Direct Biometric Device Cloud API (v1 — Own Brand / Native Terminals)
|--------------------------------------------------------------------------
| Authenticated via per-device API key + optional HMAC-SHA256 signature.
*/
Route::prefix('biometric/device/v1')
    ->middleware(['biometric.device', 'throttle:120,1'])
    ->name('api.biometric.device.')
    ->group(function () {
        Route::post('punches', [BiometricDevicePushApiController::class, 'pushPunches'])->name('punches');
        Route::get('commands', [BiometricDevicePushApiController::class, 'pollCommands'])->name('commands');
        Route::post('commands/{wireId}/ack', [BiometricDevicePushApiController::class, 'acknowledgeCommand'])->whereNumber('wireId')->name('commands.ack');
    });
