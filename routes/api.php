<?php

use App\Http\Controllers\Api\BiometricAttendanceApiController;
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
