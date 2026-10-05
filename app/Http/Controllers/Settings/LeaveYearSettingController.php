<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LeaveYearSettingController extends Controller
{
    public function update(Request $request)
    {
        if (! Auth::user()->can('manage-system-settings')
            && ! Auth::user()->can('manage-leave-policies')
            && Auth::user()->type !== 'company') {
            return redirect()->back()->with('error', __('Permission Denied.'));
        }

        $validated = $request->validate([
            'leave_year_type' => 'required|string|in:calendar,financial,custom',
            'leave_year_start_month' => 'required_if:leave_year_type,custom|nullable|integer|min:1|max:12',
        ]);

        updateSetting('leave_year_type', $validated['leave_year_type']);
        updateSetting('leave_year_start_month', $validated['leave_year_type'] === 'custom'
            ? (string) $validated['leave_year_start_month']
            : ($validated['leave_year_type'] === 'financial' ? '4' : '1'));

        return redirect()->back()->with('success', __('Leave year settings updated successfully.'));
    }
}
