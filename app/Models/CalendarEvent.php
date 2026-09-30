<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;

class CalendarEvent extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'visibility',
        'created_by',
    ];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
    ];
}
