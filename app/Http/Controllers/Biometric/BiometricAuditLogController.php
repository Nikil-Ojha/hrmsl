<?php

namespace App\Http\Controllers\Biometric;

use App\Biometric\Services\BiometricAuditLogger;
use App\Http\Controllers\Controller;
use App\Models\BiometricAuditLog;
use App\Models\BiometricDevice;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BiometricAuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $base = BiometricAuditLog::visibleTo();

        $query = (clone $base)
            ->with(['actor:id,name,email', 'device:id,name,serial_number', 'employee.user:id,name']);

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('target_label', 'like', "%{$search}%")
                    ->orWhere('actor_name', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category') && $request->input('category') !== '_empty_') {
            $query->where('category', $request->input('category'));
        }

        if ($request->filled('device_id') && $request->input('device_id') !== '_empty_') {
            $query->where('device_id', (int) $request->input('device_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('occurred_at', '>=', $request->input('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('occurred_at', '<=', $request->input('date_to'));
        }

        $logs = $query->orderByDesc('occurred_at')->orderByDesc('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (BiometricAuditLog $l) => [
                'id' => $l->id,
                'actor_name' => $l->actor?->name ?? $l->actor_name ?? 'System',
                'actor_email' => $l->actor?->email,
                'actor_type' => $l->actor_type,
                'category' => $l->category,
                'action' => $l->action,
                'target_type' => $l->target_type,
                'target_id' => $l->target_id,
                'target_label' => $l->target_label,
                'device_id' => $l->device_id,
                'device_name' => $l->device?->name,
                'employee_id' => $l->employee_id,
                'employee_name' => $l->employee?->user?->name,
                'description' => $l->description,
                'before_state' => $l->before_state,
                'after_state' => $l->after_state,
                'metadata' => $l->metadata,
                'ip_address' => $l->ip_address,
                'occurred_at' => $l->occurred_at?->toIso8601String(),
            ]);

        return Inertia::render('hr/biometric-audit-logs/index', [
            'logs' => $logs,
            'stats' => [
                'total' => (clone $base)->count(),
                'today' => (clone $base)->whereDate('occurred_at', today())->count(),
                'corrections' => (clone $base)->where('category', BiometricAuditLogger::CAT_ATTENDANCE_CORRECTION)->count(),
                'commands_and_devices' => (clone $base)->whereIn('category', [BiometricAuditLogger::CAT_DEVICE, BiometricAuditLogger::CAT_COMMAND])->count(),
            ],
            'options' => [
                'categories' => BiometricAuditLogger::categoryOptions(),
                'devices' => BiometricDevice::visibleTo()->orderBy('name')->get(['id', 'name', 'serial_number']),
            ],
            'filters' => $request->only(['search', 'category', 'device_id', 'date_from', 'date_to']),
        ]);
    }
}
