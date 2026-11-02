<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class BiometricAttendanceLog extends BaseModel
{
    use HasFactory;

    protected $table = 'biometric_attendance_logs';

    protected $fillable = [
        'employee_id',
        'employee_code',
        'log_datetime',
        'log_date',
        'log_time',
        'downloaded_at',
        'device_sn',
        'punch_state',
        'is_synced',
        'created_by',
    ];

    protected $casts = [
        'log_datetime' => 'datetime',
        'log_date' => 'date',
        'downloaded_at' => 'datetime',
        'is_synced' => 'boolean',
    ];

    /**
     * Get the user (employee) associated with the log.
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'employee_id');
    }

    /**
     * Get the employee profile by biometric_emp_id.
     */
    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_code', 'biometric_emp_id');
    }
}
