<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class BiometricDeviceCommand extends BaseModel
{
    use HasFactory;

    protected $table = 'biometric_device_commands';

    protected $fillable = [
        'employee_id',
        'employee_code',
        'employee_name',
        'device_sn',
        'command_type',
        'adms_command',
        'fkweb_payload',
        'status',
        'sent_at',
        'acknowledged_at',
        'return_code',
        'created_by',
    ];

    protected $casts = [
        'fkweb_payload' => 'array',
        'sent_at' => 'datetime',
        'acknowledged_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class, 'employee_id');
    }
}
