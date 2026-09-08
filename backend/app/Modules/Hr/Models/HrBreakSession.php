<?php

namespace App\Modules\Hr\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HrBreakSession extends Model
{
    protected $table = 'hr_break_sessions';

    protected $fillable = [
        'company_id', 'user_id', 'attendance_id', 'break_type_id',
        'start_at', 'end_at',
        'start_lat', 'start_lng', 'end_lat', 'end_lng', 'start_distance_m', 'end_distance_m',
        'minutes', 'over_limit', 'over_by_minutes', 'start_status', 'note',
    ];

    protected $casts = [
        'start_at'   => 'datetime',
        'end_at'     => 'datetime',
        'over_limit' => 'boolean',
        'minutes'    => 'integer',
        'over_by_minutes' => 'integer',
        'start_lat' => 'float', 'start_lng' => 'float',
        'end_lat'   => 'float', 'end_lng'   => 'float',
    ];

    public function breakType(): BelongsTo { return $this->belongsTo(HrBreakType::class, 'break_type_id'); }
}
