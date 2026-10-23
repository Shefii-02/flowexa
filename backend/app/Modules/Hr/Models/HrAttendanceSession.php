<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrAttendanceSession extends Model
{
    protected $table = 'hr_attendance_sessions';

    protected $fillable = [
        'company_id', 'user_id', 'attendance_id',
        'clock_in_at', 'clock_out_at',
        'clock_in_lat', 'clock_in_lng', 'clock_out_lat', 'clock_out_lng',
        'clock_in_distance_m', 'clock_out_distance_m',
        'clock_in_photo_url', 'clock_out_photo_url',
        'clock_in_radius_reason', 'clock_out_radius_reason',
        'clock_in_note', 'clock_out_note', 'source',
    ];

    protected $casts = [
        'clock_in_at'  => 'datetime',
        'clock_out_at' => 'datetime',
        'clock_in_lat'  => 'float', 'clock_in_lng'  => 'float',
        'clock_out_lat' => 'float', 'clock_out_lng' => 'float',
    ];

    public function attendance(): BelongsTo { return $this->belongsTo(HrAttendance::class, 'attendance_id'); }
}
