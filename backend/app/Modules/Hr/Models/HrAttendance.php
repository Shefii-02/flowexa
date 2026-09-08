<?php

namespace App\Modules\Hr\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HrAttendance extends Model
{
    protected $table = 'hr_attendance';

    protected $fillable = [
        'company_id', 'user_id', 'work_date',
        'clock_in_at', 'clock_out_at',
        'clock_in_lat', 'clock_in_lng', 'clock_out_lat', 'clock_out_lng',
        'clock_in_distance_m', 'clock_out_distance_m', 'clock_in_out_of_geofence',
        'clock_in_status', 'work_mode', 'source',
        'late_minutes', 'early_leave_minutes', 'overtime_minutes', 'break_minutes', 'worked_minutes',
        'late_note', 'early_leave_note', 'overtime_note', 'overtime_status',
        'overtime_approved_by', 'overtime_reviewed_at',
        'status', 'note_color', 'note_message',
    ];

    protected $casts = [
        'work_date'                => 'date',
        'clock_in_at'              => 'datetime',
        'clock_out_at'             => 'datetime',
        'overtime_reviewed_at'     => 'datetime',
        'clock_in_out_of_geofence' => 'boolean',
        'clock_in_lat'  => 'float', 'clock_in_lng'  => 'float',
        'clock_out_lat' => 'float', 'clock_out_lng' => 'float',
        'late_minutes' => 'integer', 'early_leave_minutes' => 'integer',
        'overtime_minutes' => 'integer', 'break_minutes' => 'integer', 'worked_minutes' => 'integer',
    ];

    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function breaks(): HasMany { return $this->hasMany(HrBreakSession::class, 'attendance_id'); }

    public function openBreak(): ?HrBreakSession
    {
        return $this->breaks()->whereNull('end_at')->latest('id')->first();
    }
}
