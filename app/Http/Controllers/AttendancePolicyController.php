<?php

namespace App\Http\Controllers;

use App\Models\AttendancePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class AttendancePolicyController extends Controller
{
    public function index(Request $request)
    {
        if (Auth::user()->can('manage-attendance-policies')) {
            $query = AttendancePolicy::with(['creator'])->where(function ($q) {
                if (Auth::user()->can('manage-any-attendance-policies')) {
                    $q->whereIn('created_by',  getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-attendance-policies')) {
                    $q->where('created_by', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            // Handle search
            if ($request->has('search') && !empty($request->search)) {
                $query->where(function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%')
                        ->orWhere('description', 'like', '%' . $request->search . '%');
                });
            }

            // Handle overtime calculation filter
            if ($request->has('overtime_calculation') && !empty($request->overtime_calculation) && $request->overtime_calculation !== 'all') {
                $query->where('overtime_calculation', $request->overtime_calculation);
            }

            $statusCounts = [
                'all'      => (clone $query)->count(),
                'active'   => (clone $query)->where('status', 'active')->count(),
                'inactive' => (clone $query)->where('status', 'inactive')->count(),
            ];

            // Handle status filter
            if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Handle sorting
            if ($request->has('sort_field') && !empty($request->sort_field)) {
                $sortField = $request->sort_field;
                $sortDirection = $request->sort_direction ?? 'asc';

                if ($sortField === 'name') {
                    $query->orderBy('name', $sortDirection);
                } else {
                    $query->orderBy('created_at', 'desc');
                }
            } else {
                $query->orderBy('created_at', 'desc');
            }

            $attendancePolicies = $query->paginate($request->per_page ?? 9);

            // Stats always calculated from ALL records — never affected by filters or pagination
            $allPolicies = AttendancePolicy::where(function ($q) {
                if (Auth::user()->can('manage-any-attendance-policies')) {
                    $q->whereIn('created_by', getCompanyAndUsersId());
                } elseif (Auth::user()->can('manage-own-attendance-policies')) {
                    $q->where('created_by', Auth::id());
                } else {
                    $q->whereRaw('1 = 0');
                }
            });

            $stats = [
                'total'            => (clone $allPolicies)->count(),
                'active'           => (clone $allPolicies)->where('status', 'active')->count(),
                'avg_late_grace'   => (int) round((clone $allPolicies)->avg('late_arrival_grace') ?? 0),
                'avg_overtime_rate'=> (float) ((clone $allPolicies)->avg('overtime_rate_per_hour') ?? 0),
            ];

            return Inertia::render('hr/attendance-policies/index', [
                'attendancePolicies' => $attendancePolicies,
                'stats'              => $stats,
                'statusCounts'       => $statusCounts,
                'filters'            => $request->all(['search', 'status', 'overtime_calculation', 'sort_field', 'sort_direction', 'per_page']),
            ]);
        } else {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'late_arrival_grace' => 'required|integer|min:0',
            'early_departure_grace' => 'required|integer|min:0',
            'overtime_rate_per_hour' => 'required|numeric|min:0',
            'punch_pairing_mode' => 'nullable|in:first_last,in_out_pairs,device_state',
            'duplicate_punch_window_sec' => 'nullable|integer|min:0|max:3600',
            'missing_clock_out_grace_hours' => 'nullable|integer|min:1|max:24',
            'auto_detect_shift' => 'nullable|boolean',
            'status' => 'nullable|in:active,inactive',
        ]);

        $validated['created_by'] = creatorId();
        $validated['status'] = $validated['status'] ?? 'active';

        // Check if policy with same name already exists
        $exists = AttendancePolicy::where('name', $validated['name'])
            ->whereIn('created_by', getCompanyAndUsersId())
            ->exists();

        if ($exists) {
            return redirect()->back()->with('error', __('Attendance policy with this name already exists.'));
        }

        $policy = AttendancePolicy::create($validated);

        app(\App\Biometric\Services\BiometricAuditLogger::class)->log(
            category: \App\Biometric\Services\BiometricAuditLogger::CAT_SETTINGS,
            action: 'settings.policy_created',
            description: "Created attendance & biometric policy '{$policy->name}'.",
            targetType: 'AttendancePolicy',
            targetId: $policy->id,
            targetLabel: $policy->name,
            after: $policy->only(['name', 'late_arrival_grace', 'early_departure_grace', 'overtime_rate_per_hour', 'punch_pairing_mode', 'duplicate_punch_window_sec', 'missing_clock_out_grace_hours', 'auto_detect_shift', 'status']),
        );

        return redirect()->back()->with('success', __('Attendance policy created successfully.'));
    }

    public function update(Request $request, $attendancePolicyId)
    {
        $attendancePolicy = AttendancePolicy::where('id', $attendancePolicyId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if ($attendancePolicy) {
            try {
                $validated = $request->validate([
                    'name' => 'required|string|max:255',
                    'description' => 'nullable|string',
                    'late_arrival_grace' => 'required|integer|min:0',
                    'early_departure_grace' => 'required|integer|min:0',
                    'overtime_rate_per_hour' => 'required|numeric|min:0',
            'punch_pairing_mode' => 'nullable|in:first_last,in_out_pairs,device_state',
            'duplicate_punch_window_sec' => 'nullable|integer|min:0|max:3600',
            'missing_clock_out_grace_hours' => 'nullable|integer|min:1|max:24',
            'auto_detect_shift' => 'nullable|boolean',
                    'status' => 'nullable|in:active,inactive',
                ]);

                // Check if policy with same name already exists (excluding current)
                $exists = AttendancePolicy::where('name', $validated['name'])
                    ->whereIn('created_by', getCompanyAndUsersId())
                    ->where('id', '!=', $attendancePolicyId)
                    ->exists();

                if ($exists) {
                    return redirect()->back()->with('error', __('Attendance policy with this name already exists.'));
                }

                $before = $attendancePolicy->only(['name', 'late_arrival_grace', 'early_departure_grace', 'overtime_rate_per_hour', 'punch_pairing_mode', 'duplicate_punch_window_sec', 'missing_clock_out_grace_hours', 'auto_detect_shift', 'status']);
                $attendancePolicy->update($validated);

                app(\App\Biometric\Services\BiometricAuditLogger::class)->log(
                    category: \App\Biometric\Services\BiometricAuditLogger::CAT_SETTINGS,
                    action: 'settings.policy_updated',
                    description: "Updated attendance & biometric policy '{$attendancePolicy->name}'.",
                    targetType: 'AttendancePolicy',
                    targetId: $attendancePolicy->id,
                    targetLabel: $attendancePolicy->name,
                    before: $before,
                    after: $attendancePolicy->only(['name', 'late_arrival_grace', 'early_departure_grace', 'overtime_rate_per_hour', 'punch_pairing_mode', 'duplicate_punch_window_sec', 'missing_clock_out_grace_hours', 'auto_detect_shift', 'status']),
                );

                return redirect()->back()->with('success', __('Attendance policy updated successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update attendance policy'));
            }
        } else {
            return redirect()->back()->with('error', __('Attendance policy Not Found.'));
        }
    }

    public function destroy($attendancePolicyId)
    {
        $attendancePolicy = AttendancePolicy::where('id', $attendancePolicyId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if ($attendancePolicy) {
            try {
                $attendancePolicy->delete();
                return redirect()->back()->with('success', __('Attendance policy deleted successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to delete attendance policy'));
            }
        } else {
            return redirect()->back()->with('error', __('Attendance policy Not Found.'));
        }
    }

    public function toggleStatus($attendancePolicyId)
    {
        $attendancePolicy = AttendancePolicy::where('id', $attendancePolicyId)
            ->whereIn('created_by', getCompanyAndUsersId())
            ->first();

        if ($attendancePolicy) {
            try {
                $attendancePolicy->status = $attendancePolicy->status === 'active' ? 'inactive' : 'active';
                $attendancePolicy->save();

                return redirect()->back()->with('success', __('Attendance policy status updated successfully'));
            } catch (\Exception $e) {
                return redirect()->back()->with('error', $e->getMessage() ?: __('Failed to update attendance policy status'));
            }
        } else {
            return redirect()->back()->with('error', __('Attendance policy Not Found.'));
        }
    }
}
